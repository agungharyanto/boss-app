<?php

namespace App\Services\Network;

use App\Models\OltDevice;
use App\Models\OltUplinkPort;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v0.23.5 Bagian 2 — refresh ON-DEMAND status port uplink OLT via sidecar
 * (read-only `get_uplink_ports`) + cache ke olt_uplink_ports. Pola sama
 * OnuRegistryService: tidak ada job berkala, baris cache = hasil refresh
 * eksplisit terakhir. Hanya ZTE C300 (sidecar op-nya ZTE-only); vendor
 * lain mengembalikan daftar kosong + pesan (ditangani di UI).
 */
class OltUplinkService
{
    public function __construct(private readonly OltSidecarClient $sidecar) {}

    /**
     * Refresh dari OLT (read-only), parse, upsert cache. Return koleksi
     * OltUplinkPort terbaru. Throw RuntimeException kalau sidecar gagal.
     */
    public function refresh(OltDevice $oltDevice, ?int $requestedBy = null): Collection
    {
        $response = $this->sidecar->read($oltDevice, 'get_uplink_ports', [], $requestedBy);
        if (! ($response['success'] ?? false)) {
            throw new RuntimeException(
                'Gagal baca port uplink dari OLT: '.($response['device_message'] ?? $response['error'] ?? 'unknown')
            );
        }

        $ports = $response['data']['ports'] ?? [];
        $now = now();
        $seenNames = [];

        DB::transaction(function () use ($oltDevice, $ports, $now, &$seenNames) {
            foreach ($ports as $port) {
                $parsed = OltUplinkPortParser::parse($port);
                if (empty($parsed['name'])) {
                    continue;
                }
                $seenNames[] = $parsed['name'];

                OltUplinkPort::updateOrCreate(
                    ['olt_device_id' => $oltDevice->id, 'port_name' => $parsed['name']],
                    [
                        'is_10g' => $parsed['is_10g'],
                        'admin_state' => $parsed['admin_state'],
                        'oper_status' => $parsed['oper_status'],
                        'line_protocol' => $parsed['line_protocol'],
                        'description' => $parsed['description'],
                        'negotiation' => $parsed['negotiation'],
                        'port_type' => $parsed['port_type'],
                        'duplex' => $parsed['duplex'],
                        'mtu' => $parsed['mtu'],
                        'wavelength_nm' => $parsed['wavelength_nm'],
                        'rx_power_dbm' => $parsed['rx_power_dbm'],
                        'tx_power_dbm' => $parsed['tx_power_dbm'],
                        'temperature_c' => $parsed['temperature_c'],
                        'module_type' => $parsed['module_type'],
                        'port_mode' => $parsed['port_mode'],
                        'pvid' => $parsed['pvid'],
                        'untagged_vlans' => $parsed['untagged_vlans'],
                        'tagged_vlans' => $parsed['tagged_vlans'],
                        'raw_payload' => $port,
                        'last_synced_at' => $now,
                    ],
                );
            }

            // Port yang tidak lagi muncul (mis. SFP dicabut) dihapus dari
            // cache supaya tidak menampilkan data basi.
            OltUplinkPort::where('olt_device_id', $oltDevice->id)
                ->when($seenNames !== [], fn ($q) => $q->whereNotIn('port_name', $seenNames))
                ->when($seenNames === [], fn ($q) => $q)
                ->delete();
        });

        return $this->cached($oltDevice);
    }

    /**
     * Baca cache terakhir (tanpa menyentuh OLT). Urut: nama port.
     */
    public function cached(OltDevice $oltDevice): Collection
    {
        return OltUplinkPort::where('olt_device_id', $oltDevice->id)
            ->orderBy('port_name')
            ->get();
    }
}
