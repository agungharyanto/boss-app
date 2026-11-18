<?php

namespace App\Jobs;

use App\Enums\WhatsappEventType;
use App\Enums\WorkOrderDeviceType;
use App\Enums\ZtpStatus;
use App\Models\CpeDevice;
use App\Models\OltDevice;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Services\Network\Provisioning\OltAdapterRegistry;
use App\Services\Network\Provisioning\OnuDiscoveryService;
use App\Services\Network\Provisioning\ZtpOrchestratorService;
use App\Services\Whatsapp\WhatsappGatewayService;
use App\Services\Whatsapp\WhatsappGroupRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * v0.13.4.2.3 — pemicu OMCI/ZTP saat aktivasi. Di-dispatch dari
 * WorkOrderActivationService::activateFromForm() SETELAH prepare() sukses
 * (afterCommit, DI LUAR transaksi aktivasi). Alur:
 *   discovery (OnuDiscoveryService, baca OLT) -> guard D3/D4 -> provision
 *   (ZtpOrchestratorService) -> simpan hasil per-tahap -> gagal: event WA grup.
 *
 * Kegagalan ZTP TIDAK mem-rollback radcheck/InProgress (D5) — aktivasi sudah
 * selesai; ONU bisa diprovisioning ulang. Idempoten (D6): ztp_provisioned_at
 * terisi / status Success -> no-op; ShouldBeUnique per WO (satu provision
 * berjalan). Antrean DEFAULT (boss-worker), BUKAN whatsapp.
 */
class ProvisionOnuViaZtp implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 6 percobaan (discovery retry saat ONU belum terlihat) dalam ~10 menit. */
    public int $tries = 6;

    public int $timeout = 120;

    public function __construct(public int $workOrderId) {}

    public function uniqueId(): string
    {
        return 'ztp-wo-'.$this->workOrderId;
    }

    /** @return list<int> backoff detik per percobaan (total ~10 menit). */
    public function backoff(): array
    {
        return [60, 90, 120, 150, 180];
    }

    public function handle(
        OnuDiscoveryService $discovery,
        ZtpOrchestratorService $orchestrator,
        OltAdapterRegistry $oltRegistry,
        WhatsappGatewayService $whatsapp,
        WhatsappGroupRouter $router,
    ): void {
        $wo = WorkOrder::find($this->workOrderId);
        if ($wo === null) {
            return;
        }

        // D6 — idempotensi: sudah diprovisioning sukses -> no-op.
        if ($wo->ztp_provisioned_at !== null || $wo->ztp_status === ZtpStatus::Success->value) {
            return;
        }

        $device = WorkOrderDevice::where('work_order_id', $wo->id)
            ->where('device_type', WorkOrderDeviceType::Ont)
            ->orderBy('id')
            ->first();
        if ($device === null) {
            $this->fail($wo, 'no_device', 'Tidak ada perangkat ONT di WO ini.', null, null, $whatsapp, $router);

            return;
        }

        $sn = (string) $device->serial_number;
        $mac = $device->mac_address;
        $cpe = CpeDevice::withoutGlobalScopes()->where('work_order_device_id', $device->id)->first();
        if ($cpe === null) {
            $this->fail($wo, 'no_cpe', 'CPE belum ter-bind untuk WO ini.', null, null, $whatsapp, $router);

            return;
        }

        $wo->update(['ztp_status' => ZtpStatus::Running->value, 'ztp_attempts' => $this->attempts()]);

        // --- DISCOVERY ---
        $disc = $discovery->discover($wo, $sn, $mac);
        if (($disc['found'] ?? false) !== true) {
            $reason = $disc['reason'] ?? 'not_found';

            // ONU belum terlihat -> retry bertahap sampai tries habis.
            if ($reason === 'not_found' && $this->attempts() < $this->tries) {
                $wo->update(['ztp_status' => ZtpStatus::Pending->value]);
                $this->release($this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)]);

                return;
            }

            $this->fail($wo, 'discovery:'.$reason, $this->discoveryMessage($reason, $disc), null, $sn, $whatsapp, $router, $cpe, $disc);

            return;
        }

        $olt = OltDevice::withoutGlobalScopes()->find($disc['olt_id']);
        if ($olt === null) {
            $this->fail($wo, 'discovery:olt_missing', 'OLT hasil discovery tidak ditemukan.', null, $sn, $whatsapp, $router, $cpe, $disc);

            return;
        }

        // --- GUARD D3/D4 ---
        $skipReason = $this->guard($wo, $olt, $cpe, $sn, $oltRegistry);
        if ($skipReason !== null) {
            // Kanal/gate OFF = SKIPPED (bukan error, tak kirim WA gagal).
            if (str_starts_with($skipReason['kind'], 'skip')) {
                $wo->update([
                    'ztp_status' => ZtpStatus::Skipped->value,
                    'ztp_olt_device_id' => $olt->id,
                    'ztp_last_result' => ['skipped' => $skipReason['reason']],
                ]);
                Log::info("ProvisionOnuViaZtp: WO #{$wo->id} dilewati — {$skipReason['reason']}");

                return;
            }
            // Guard keamanan gagal (mis. SN aktif) = FAILED + WA.
            $this->fail($wo, $skipReason['kind'], $skipReason['reason'], $olt, $sn, $whatsapp, $router, $cpe, $disc);

            return;
        }

        // --- PROVISION ---
        $options = ['requested_by' => null];
        if (($disc['pon'] ?? null) !== null) {
            $options['pon_interface'] = $disc['pon'];
        }

        $result = $orchestrator->provision($cpe, $olt, $options);

        $wo->update([
            'ztp_olt_device_id' => $olt->id,
            'ztp_attempts' => $this->attempts(),
            'ztp_last_result' => $this->sanitize($result),
        ]);

        if (($result['ok'] ?? false) === true) {
            $wo->update(['ztp_status' => ZtpStatus::Success->value, 'ztp_provisioned_at' => now()]);
            Log::info("ProvisionOnuViaZtp: WO #{$wo->id} ZTP sukses (OLT #{$olt->id}, SN {$sn}).");

            return;
        }

        $this->fail($wo, $result['stage'] ?? 'provision', $result['reason'] ?? 'tahap provision gagal', $olt, $sn, $whatsapp, $router, $cpe, $disc);
    }

    /**
     * Guard D3/D4. Return null = lolos. Return ['kind'=>'skip_*'|'guard_*',
     * 'reason'=>...] = berhenti (skip tak kirim WA; guard_* kirim WA gagal).
     *
     * @return array{kind: string, reason: string}|null
     */
    private function guard(WorkOrder $wo, OltDevice $olt, CpeDevice $cpe, string $sn, OltAdapterRegistry $oltRegistry): ?array
    {
        if ($olt->requires_bind_onu === null) {
            return ['kind' => 'skip_admission', 'reason' => "OLT #{$olt->id} belum diset requires_bind_onu."];
        }

        $via = $oltRegistry->for($olt->sidecarVendorKey())->capabilities()->wanInternetVia;

        if ($via === 'omci') {
            // Kanal OMCI per-OLT DAN gate kode keras (default OFF — ZTE produksi).
            if ($olt->omci_channel_enabled !== true) {
                return ['kind' => 'skip_channel', 'reason' => "Kanal OMCI OLT #{$olt->id} dinonaktifkan."];
            }
            if ((bool) config('services.olt_sidecar.ztp_omci_activation_enabled') !== true) {
                return ['kind' => 'skip_omci_gate', 'reason' => 'Jalur WAN OMCI (ZTE) di-gate OFF (ztp_omci_activation_enabled=false).'];
            }
        } elseif ($olt->tr069_channel_enabled !== true) {
            return ['kind' => 'skip_channel', 'reason' => "Kanal TR-069 OLT #{$olt->id} dinonaktifkan."];
        }

        // SN tidak boleh milik CPE pelanggan AKTIF lain (sudah online di GenieACS).
        $activeElsewhere = CpeDevice::withoutGlobalScopes()
            ->where('serial_number', $sn)
            ->where('id', '!=', $cpe->id)
            ->whereNotNull('genieacs_device_id')
            ->exists();
        if ($activeElsewhere) {
            return ['kind' => 'guard_sn_active', 'reason' => "SN {$sn} sudah terpakai perangkat aktif lain — provisioning ditolak demi keamanan."];
        }

        return null;
    }

    /** Tandai gagal final + kirim event WA ke grup/admin (bukan pelanggan). */
    private function fail(
        WorkOrder $wo,
        string $stage,
        string $reason,
        ?OltDevice $olt,
        ?string $sn,
        WhatsappGatewayService $whatsapp,
        WhatsappGroupRouter $router,
        ?CpeDevice $cpe = null,
        ?array $disc = null,
    ): void {
        $existing = is_array($wo->ztp_last_result) ? $wo->ztp_last_result : [];
        $wo->update([
            'ztp_status' => ZtpStatus::Failed->value,
            'ztp_attempts' => $this->attempts(),
            'ztp_last_result' => $existing + ['failed_stage' => $stage, 'failed_reason' => $reason, 'discovery' => $disc],
        ]);

        Log::warning('ProvisionOnuViaZtp: GAGAL', [
            'work_order_id' => $wo->id,
            'stage' => $stage,
            'reason' => $reason,
            'olt_id' => $olt?->id,
        ]);

        $this->notifyGroup($wo, $stage, $reason, $olt, $sn, $whatsapp, $router);
    }

    private function notifyGroup(
        WorkOrder $wo,
        string $stage,
        string $reason,
        ?OltDevice $olt,
        ?string $sn,
        WhatsappGatewayService $whatsapp,
        WhatsappGroupRouter $router,
    ): void {
        $group = $router->resolve($wo->tenant_id, WhatsappEventType::OnuProvisioningFailed->value);
        if ($group === null) {
            return;
        }

        $customer = $wo->customer;

        $whatsapp->buildAndQueueForGroup(
            WhatsappEventType::OnuProvisioningFailed,
            $wo->tenant_id,
            $group->jid,
            [
                'work_order_id' => (string) $wo->id,
                'customer_name' => (string) ($customer->name ?? '-'),
                'cid' => (string) ($customer->cid ?? '-'),
                'onu_sn' => (string) ($sn ?? '-'),
                'olt_name' => (string) ($olt->name ?? '-'),
                'stage' => $stage,
                'reason' => $reason,
                'status_link' => $this->statusLink($wo),
            ],
            $group->session_key,
        );
    }

    private function statusLink(WorkOrder $wo): string
    {
        if ($wo->claimed_by_technician_id === null) {
            return '';
        }
        $tech = Technician::withoutGlobalScopes()->find($wo->claimed_by_technician_id);
        if ($tech === null) {
            return '';
        }

        return URL::temporarySignedRoute(
            'work-orders.activate.status',
            now()->addDays(3),
            ['work_order' => $wo->id, 'technician' => $tech->id],
        );
    }

    private function discoveryMessage(string $reason, array $disc): string
    {
        return match ($reason) {
            'not_found' => 'ONU tidak ditemukan di OLT tenant setelah beberapa percobaan.',
            'multiple' => 'ONU ditemukan di LEBIH DARI SATU OLT — tolak, perlu cek manual.',
            'olt_unreachable' => 'Semua OLT tenant tak terjangkau saat discovery.',
            'no_tenant_olt' => 'Tidak ada OLT ter-registrasi untuk tenant ini.',
            default => "Discovery gagal: {$reason}",
        };
    }

    /**
     * Buang kemungkinan data sensitif dari hasil provision() sebelum disimpan
     * ke DB (hasil per-tahap hanya ringkasan ok/stage/reason — tidak ada
     * kredensial OLT; defensive: hapus key yang mengandung password/secret).
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function sanitize(array $result): array
    {
        array_walk_recursive($result, function (&$v, $k) {
            if (is_string($k) && preg_match('/(password|secret|token)/i', $k)) {
                $v = '***';
            }
        });

        return $result;
    }
}
