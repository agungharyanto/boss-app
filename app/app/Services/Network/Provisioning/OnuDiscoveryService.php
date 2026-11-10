<?php

namespace App\Services\Network\Provisioning;

use App\Models\Odp;
use App\Models\OltDevice;
use App\Models\WorkOrder;
use App\Services\Network\OltSidecarClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v0.13.4.2.3 — PENEMUAN OTOMATIS ONU lewat PEMBACAAN OLT (read-only sidecar).
 * Dipakai `ProvisionOnuViaZtp` sebelum `ZtpOrchestratorService::provision()`
 * untuk (a) menentukan DI OLT MANA + PON MANA ONU berada, dan (b) memastikan
 * ONU genuinely BELUM terkonfigurasi (uncfg ZTE / belum-bind E04ID / baru
 * G02ID) — bukan ONU pelanggan aktif.
 *
 * Aturan (keputusan D1/D3):
 *   - Hanya OLT milik tenant WO yang dipertimbangkan (OLT beda tenant TAK
 *     PERNAH). Petunjuk ODP->OLT (fiber_cores.olt_device_id) hanya MEMPERSEMPIT
 *     pencarian bila datanya ada — tidak menggugurkan OLT lain bila kosong.
 *   - ZTE (zte_c300): SN ada di daftar `onu_uncfg_list`.
 *   - E04ID (hsgq_e04id): MAC ada di `show onu-info` (deny-by-default). MAC
 *     kosong -> E04ID DILEWATI (ditandai, admin diberi tahu).
 *   - G02ID (hsgq_g02id): SN resolvable via `resolve_onu_by_sn` (state ada).
 *   - Ditemukan di TEPAT 1 OLT -> lanjut; 0 -> not_found (job retry); >1 ->
 *     multiple (tolak+lapor); semua OLT tak terjangkau -> olt_unreachable.
 *
 * Semua operasi baca SUDAH ADA di sidecar — NOL perubahan sidecar/gateway.
 */
class OnuDiscoveryService
{
    public function __construct(private readonly OltSidecarClient $sidecar) {}

    /**
     * @return array{found: bool, reason?: string, olt_id?: int, pon?: ?string,
     *     status?: string, identifier?: string, skipped?: list<string>,
     *     detail?: array<string,mixed>}
     */
    public function discover(WorkOrder $workOrder, string $sn, ?string $mac): array
    {
        $candidates = $this->candidateOlts($workOrder);
        if ($candidates->isEmpty()) {
            return ['found' => false, 'reason' => 'no_tenant_olt'];
        }

        $matches = [];
        $unreachable = 0;
        $skipped = [];

        foreach ($candidates as $olt) {
            $vendor = $olt->sidecarVendorKey();

            try {
                $hit = match ($vendor) {
                    'zte_c300' => $this->matchZte($olt, $sn),
                    'hsgq_e04id' => $this->matchE04id($olt, $mac, $skipped),
                    'hsgq_g02id' => $this->matchG02id($olt, $sn),
                    default => null,
                };
            } catch (Throwable $e) {
                $unreachable++;
                Log::info("OnuDiscoveryService: OLT #{$olt->id} ({$vendor}) tak terjangkau saat discovery — {$e->getMessage()}");

                continue;
            }

            if ($hit !== null) {
                $matches[] = ['olt' => $olt] + $hit;
            }
        }

        if (count($matches) > 1) {
            return [
                'found' => false,
                'reason' => 'multiple',
                'detail' => ['olt_ids' => array_map(fn ($m) => $m['olt']->id, $matches)],
                'skipped' => $skipped,
            ];
        }

        if (count($matches) === 1) {
            $m = $matches[0];

            return [
                'found' => true,
                'olt_id' => $m['olt']->id,
                'pon' => $m['pon'] ?? null,
                'status' => $m['status'],
                'identifier' => $m['identifier'],
                'skipped' => $skipped,
            ];
        }

        // Nol match.
        if ($unreachable === $candidates->count()) {
            return ['found' => false, 'reason' => 'olt_unreachable', 'skipped' => $skipped];
        }

        return ['found' => false, 'reason' => 'not_found', 'skipped' => $skipped];
    }

    /**
     * OLT kandidat: milik tenant WO, kanal belum tahu (dicek di guard job),
     * requires_bind_onu TIDAK null (mode admisi terdefinisi). Petunjuk ODP->OLT
     * memprioritaskan (bukan memfilter keras).
     *
     * @return Collection<int, OltDevice>
     */
    private function candidateOlts(WorkOrder $workOrder): Collection
    {
        $hintOltIds = $this->odpOltHint($workOrder);

        $olts = OltDevice::withoutGlobalScopes()
            ->where('tenant_id', $workOrder->tenant_id)
            ->whereNotNull('requires_bind_onu')
            ->get();

        if ($hintOltIds !== [] && $olts->whereIn('id', $hintOltIds)->isNotEmpty()) {
            return $olts->whereIn('id', $hintOltIds)->values();
        }

        return $olts->values();
    }

    /**
     * Petunjuk ODP->OLT via topologi fiber (v0.16): fiber_cores yang menunjuk
     * ODP WO membawa olt_device_id. Kosong = tak ada petunjuk (cari semua OLT
     * tenant). Tidak menggugurkan apa pun — hanya memprioritaskan.
     *
     * @return list<int>
     */
    private function odpOltHint(WorkOrder $workOrder): array
    {
        $odpId = $workOrder->odp_id ?? null;
        if ($odpId === null) {
            return [];
        }

        return DB::table('fiber_cores')
            ->where('splice_node_type', Odp::class)
            ->where('splice_node_id', $odpId)
            ->whereNotNull('olt_device_id')
            ->pluck('olt_device_id')
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{pon: ?string, status: string, identifier: string}|null */
    private function matchZte(OltDevice $olt, string $sn): ?array
    {
        $result = $this->sidecar->onuUncfgList($olt, null);
        if ($this->linesContain($result, $sn)) {
            return ['pon' => null, 'status' => 'uncfg', 'identifier' => $sn];
        }

        return null;
    }

    /**
     * @param  list<string>  $skipped
     * @return array{pon: ?string, status: string, identifier: string}|null
     */
    private function matchE04id(OltDevice $olt, ?string $mac, array &$skipped): ?array
    {
        if ($mac === null || trim($mac) === '') {
            $skipped[] = "E04ID OLT #{$olt->id} dilewati: MAC modem kosong (E04ID memerlukan MAC).";

            return null;
        }

        $result = $this->sidecar->showOnuInfoMac($olt, $mac, null);
        if ($this->linesContain($result, $mac)) {
            return ['pon' => $result['pon'] ?? null, 'status' => 'deny', 'identifier' => $mac];
        }

        return null;
    }

    /** @return array{pon: ?string, status: string, identifier: string}|null */
    private function matchG02id(OltDevice $olt, string $sn): ?array
    {
        $result = $this->sidecar->resolveOnuBySn($olt, $sn, null);
        $onuId = $result['onu_id'] ?? ($result['data']['onu_id'] ?? null);
        $pon = $result['pon'] ?? ($result['data']['pon'] ?? null);
        if ($onuId !== null && $onuId !== '') {
            return ['pon' => $pon !== null ? (string) $pon : null, 'status' => 'new', 'identifier' => $sn];
        }

        return null;
    }

    /** Apakah output baca sidecar (lines/teks) mengandung needle (case-insensitive). */
    private function linesContain(array $result, string $needle): bool
    {
        $needle = strtolower(trim($needle));
        if ($needle === '') {
            return false;
        }

        $lines = $result['lines'] ?? $result['data']['lines'] ?? $result;
        $hay = is_array($lines) ? strtolower(implode("\n", array_map('strval', $this->flatten($lines)))) : strtolower((string) $lines);

        return str_contains($hay, $needle);
    }

    /** @return list<mixed> */
    private function flatten(array $arr): array
    {
        $out = [];
        array_walk_recursive($arr, function ($v) use (&$out) {
            $out[] = $v;
        });

        return $out;
    }
}
