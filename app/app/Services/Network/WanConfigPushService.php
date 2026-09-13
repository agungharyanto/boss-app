<?php

namespace App\Services\Network;

use App\Enums\CpeActionStatus;
use App\Enums\CpeActionType;
use App\Models\CpeActionLog;
use App\Models\CpeDevice;
use App\Models\User;
use RuntimeException;
use Throwable;

/**
 * v0.12.6 — "Push Konfig" (Opsi B, Task Langsung), pola PERSIS
 * App\Services\Network\CpeActionService: tulis CpeActionLog dulu (status
 * queued), lalu GenieAcsClientService::sendTask() langsung ke SATU
 * genieacs_device_id — TIDAK ADA preset/precondition sama sekali. Reuse
 * `cpe_action_logs` (bukan tabel baru) — action_type baru
 * CpeActionType::PushWanConfig sudah cukup untuk membedakan riwayatnya.
 *
 * KETERBATASAN DIKETAHUI, BUKAN CELAH TERSEMBUNYI — dibaca dulu sebelum
 * mengubah scope method ini:
 *
 * 1. Task GenieACS TIDAK PUNYA mekanisme "jalankan provision script X
 *    sebagai task one-off" (dikonfirmasi langsung ke source genieacs-nbi
 *    — task type yang didukung hanya addObject/deleteObject/download/
 *    factoryReset/getParameterValues/reboot/refreshObject/
 *    setParameterValues). Logic vendor-branching lengkap di
 *    `default-wan.js` (deteksi Huawei/CMCC/ZTE-generic/CT-COM, resolusi
 *    WCD dinamis multi-round-trip untuk CT-COM — lihat CLAUDE.md "Cabang
 *    CT-COM di default-wan.js") TIDAK BISA direplikasi sebagai daftar
 *    `[path, value, type]` statis dari sini tanpa risiko nyata (insiden
 *    CT-COM Sept 7-9 berakar persis dari kesalahan path/WCD-allocation
 *    semacam ini).
 * 2. Method ini SENGAJA hanya push wan1_pppoe_username/wan1_pppoe_password
 *    — path TR-069 yang DIKONFIRMASI STANDAR lintas vendor TERMASUK
 *    CT-COM (lihat CLAUDE.md: "PPPoE user/pass: field STANDAR
 *    WANPPPConnection.1.Username/.Password"). VLAN (`wan1_vlan`/
 *    `wan2_vlan`) dan WAN2 (bridge) TIDAK di-push lewat jalur ini —
 *    path-nya genuinely vendor-spesifik dan untuk device yang belum
 *    punya slot WAN itu butuh resolusi WCD dinamis — tidak aman dikirim
 *    sebagai setParameterValues statis.
 * 3. Instance TR-069 (WANDevice/WANConnectionDevice/WANPPPConnection)
 *    TIDAK selalu 1.1.1 — berbeda-beda per device (dikonfirmasi fleet
 *    CT-COM). Path yang benar di-resolve LIVE lewat
 *    CpeParameterResolverService::resolveActivePppConnectionPath()
 *    (method BARU, v0.12.6, reuse logic scanning yang sudah dipakai
 *    resolvePppoeConnection() untuk menampilkan PPPoE Username di
 *    halaman detail) — TIDAK PERNAH hardcode index sendiri. Konsekuensi:
 *    device yang BELUM PERNAH punya WAN1 PPPoE ter-provisioning sama
 *    sekali (belum ada instance WANPPPConnection aktif) TIDAK BISA
 *    di-push lewat jalur ini — provisioning AWAL tetap domain Auto WAN
 *    (preset), bukan Push Konfig.
 *
 * Kalau kelak dibutuhkan push VLAN/WAN2 atau provisioning WAN1 baru yang
 * genuinely aman, itu perlu desain terpisah — BUKAN scope sub-versi ini.
 */
class WanConfigPushService
{
    public function __construct(
        private readonly WanConfigTemplateResolverService $resolver,
        private readonly CpeParameterResolverService $parameterResolver,
        private readonly GenieAcsClientService $genieAcsClient,
    ) {}

    public function push(CpeDevice $device, ?User $actor): CpeActionLog
    {
        $template = $this->resolver->resolveTemplateForDevice($device);

        if ($template === null) {
            return $this->skip($device, $actor, null, 'Tidak ada Template Konfig CPE yang cocok untuk kombinasi Paket + Tipe Modem device ini — cek Paket pelanggan sudah benar dan Tipe Modem device sudah ter-assign (lihat section Tipe Modem di atas), atau buat Template-nya dulu di /wan-config-templates.');
        }

        $parameters = ['wan_config_template_id' => $template->id, 'wan_config_template_name' => $template->name];

        if (! $template->wan1_enabled) {
            return $this->skip($device, $actor, $parameters, 'Template ditemukan tapi WAN1 tidak diaktifkan di template ini — tidak ada yang perlu dikirim.');
        }

        $activePath = $this->parameterResolver->resolveActivePppConnectionPath($device->genieacs_device_id);

        if ($activePath === null) {
            return $this->skip($device, $actor, $parameters, 'Device ini belum punya WAN1 PPPoE yang genuinely aktif (belum pernah ter-provisioning) — instance TR-069 yang aman untuk diperbarui tidak ditemukan. Push Konfig hanya bisa MEMPERBARUI WAN1 yang sudah ada, bukan membuat baru dari nol (itu jalur Auto WAN/preset).');
        }

        $log = CpeActionLog::create([
            'cpe_device_id' => $device->id,
            'tenant_id' => $device->tenant_id,
            'reseller_id' => $device->reseller_id,
            'performed_by' => $actor?->id,
            'action_type' => CpeActionType::PushWanConfig,
            'parameters' => $parameters + ['target_path' => $activePath],
            'status' => CpeActionStatus::Queued,
        ]);

        try {
            if ($device->genieacs_device_id === null) {
                throw new RuntimeException('Device belum pernah terhubung ke GenieACS (genieacs_device_id kosong) — tidak bisa mengirim task.');
            }

            $result = $this->genieAcsClient->sendTask($device->genieacs_device_id, [
                'name' => 'setParameterValues',
                'parameterValues' => [
                    ["{$activePath}.Username", $template->wan1_pppoe_username, 'xsd:string'],
                    ["{$activePath}.Password", $template->wan1_pppoe_password, 'xsd:string'],
                ],
            ]);

            $log->update([
                'genieacs_task_id' => $result['task_id'],
                'status' => CpeActionStatus::Delivered,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $log->update([
                'status' => CpeActionStatus::Failed,
                'failed_reason' => substr($e->getMessage(), 0, 500),
                'completed_at' => now(),
            ]);
        }

        return $log->fresh();
    }

    /**
     * @param  ?array<string, mixed>  $parameters
     */
    private function skip(CpeDevice $device, ?User $actor, ?array $parameters, string $reason): CpeActionLog
    {
        return CpeActionLog::create([
            'cpe_device_id' => $device->id,
            'tenant_id' => $device->tenant_id,
            'reseller_id' => $device->reseller_id,
            'performed_by' => $actor?->id,
            'action_type' => CpeActionType::PushWanConfig,
            'parameters' => $parameters,
            'status' => CpeActionStatus::Skipped,
            'failed_reason' => $reason,
            'completed_at' => now(),
        ]);
    }
}
