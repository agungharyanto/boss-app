<?php

namespace App\Services\Network;

/**
 * v0.23.5 Bagian 2 — parser pure (tanpa I/O) output 3 command uplink ZTE
 * C300 yang dikembalikan sidecar `get_uplink_ports` menjadi kolom tabel
 * tab Uplink. Dipisah dari service supaya bisa diuji tanpa OLT/sidecar.
 *
 * Format output TERUJI (lihat docs/omci/zte-c300-cli-reference.md):
 * - show interface <port>          -> baris status + negotiation + type + duplex
 * - show interface optical-module-info <port> -> wavelength/rx/tx/temp
 * - show vlan port <port>          -> PortMode/Pvid/UntaggedVlan/TaggedVlan
 */
class OltUplinkPortParser
{
    /**
     * @param  array{name?:string,is_10g?:bool,port_status_lines?:array<string>,optical_lines?:array<string>,vlan_lines?:array<string>}  $port
     * @return array<string, mixed>
     */
    public static function parse(array $port): array
    {
        $status = self::parseStatus($port['port_status_lines'] ?? []);
        $optical = self::parseOptical($port['optical_lines'] ?? []);
        $vlan = self::parseVlan($port['vlan_lines'] ?? []);

        return array_merge(
            ['name' => $port['name'] ?? null, 'is_10g' => (bool) ($port['is_10g'] ?? false)],
            $status,
            $optical,
            $vlan,
        );
    }

    /**
     * @param  array<string>  $lines
     * @return array<string, mixed>
     */
    private static function parseStatus(array $lines): array
    {
        $out = [
            'admin_state' => null, 'oper_status' => null, 'line_protocol' => null,
            'description' => null, 'negotiation' => null, 'port_type' => null, 'duplex' => null,
        ];

        foreach ($lines as $line) {
            $line = trim($line);

            // "<port> is administratively down, line protocol is down, detect status is OK"
            // "<port> is up, line protocol is up, detect status is OK"
            if (preg_match('/^x?gei_\S+\s+is\s+(.+?),\s+line protocol is\s+(\S+?),/i', $line, $m)) {
                $stateText = strtolower(trim($m[1]));
                $out['admin_state'] = str_contains($stateText, 'administratively') ? 'down' : 'up';
                $out['oper_status'] = str_contains($stateText, 'up') ? 'up' : 'down';
                $out['line_protocol'] = strtolower($m[2]);

                continue;
            }
            if (preg_match('/^Description is\s+(.+)$/i', $line, $m)) {
                $out['description'] = trim($m[1]) === 'none' ? null : trim($m[1]);
            } elseif (preg_match('/^The port negotiation is\s+(\S+)/i', $line, $m)) {
                $out['negotiation'] = strtolower($m[1]);
            } elseif (preg_match('/^The port is\s+(\S+)/i', $line, $m)) {
                $out['port_type'] = strtolower($m[1]); // optical / copper
            } elseif (preg_match('/^Duplex\s+(\S+)/i', $line, $m)) {
                $out['duplex'] = strtolower($m[1]);
            } elseif (preg_match('/\bMTU\b[:\s]+(\d+)/i', $line, $m)) {
                $out['mtu'] = (int) $m[1];
            }
        }

        $out['mtu'] = $out['mtu'] ?? null;

        return $out;
    }

    /**
     * @param  array<string>  $lines
     * @return array<string, mixed>
     */
    private static function parseOptical(array $lines): array
    {
        $out = [
            'wavelength_nm' => null, 'rx_power_dbm' => null, 'tx_power_dbm' => null,
            'temperature_c' => null, 'module_type' => null,
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^Wavelength\s*:\s*(\d+)/i', $line, $m)) {
                $out['wavelength_nm'] = (int) $m[1];
            } elseif (preg_match('/^Module-Type\s*:\s*(\S+)/i', $line, $m)) {
                $out['module_type'] = $m[1];
            } elseif (preg_match('/RxPower\s*:\s*([-\d.]+)/i', $line, $m)) {
                $out['rx_power_dbm'] = self::numOrNull($m[1]);
            }
            // TxPower memakai pemisah tab/kolom — tangkap terpisah (baris sama
            // dengan RxPower ATAU baris sendiri).
            if (preg_match('/TxPower\s*:\s*([-\d.]+)/i', $line, $m)) {
                $out['tx_power_dbm'] = self::numOrNull($m[1]);
            }
            if (preg_match('/^Temperature\s*:\s*([-\d.]+)/i', $line, $m)) {
                $out['temperature_c'] = self::numOrNull($m[1]);
            }
        }

        return $out;
    }

    /**
     * @param  array<string>  $lines
     * @return array<string, mixed>
     */
    private static function parseVlan(array $lines): array
    {
        $out = ['port_mode' => null, 'pvid' => null, 'untagged_vlans' => null, 'tagged_vlans' => null];

        $pendingLabel = null;
        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Baris data: "hybrid>=0     1     0     0x8100/PORT ..."
            if (preg_match('/^(hybrid|access|trunk)\S*\s+(\d+)\s/i', $trimmed, $m)) {
                $out['port_mode'] = strtolower(preg_replace('/\W.*$/', '', $m[1]));
                $out['pvid'] = (int) $m[2];

                continue;
            }
            if (preg_match('/^UntaggedVlan:/i', $trimmed)) {
                $pendingLabel = 'untagged';

                continue;
            }
            if (preg_match('/^TaggedVlan:/i', $trimmed)) {
                $pendingLabel = 'tagged';

                continue;
            }
            // Nilai setelah label HANYA kalau baris berformat daftar VLAN
            // (angka/koma/strip) — hindari menangkap baris prompt hostname.
            if ($pendingLabel !== null) {
                if (preg_match('/^[\d,\-\s]+$/', $trimmed) && $trimmed !== '') {
                    $out[$pendingLabel.'_vlans'] = preg_replace('/\s+/', '', $trimmed);
                }
                $pendingLabel = null;
            }
        }

        return $out;
    }

    private static function numOrNull(string $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }
}
