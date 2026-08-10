<?php

namespace App\Services\Network;

use App\Models\CpeConnectedHost;
use App\Models\CpeDevice;

/**
 * Syncs TR-069 `Hosts.Host` (connected LAN clients) into `cpe_connected_hosts`
 * — v0.7.6. Reads whatever GenieACS already has stored for the device (no
 * refreshObject/connection_request triggered here — same "don't force
 * discovery, use what's already there" posture as v0.7.4/v0.7.5's own
 * discovery steps), so a device that has never reported its Hosts object at
 * all simply syncs zero hosts, not an error.
 *
 * One row per (device, MAC address), never one row per poll — see
 * cpe_connected_hosts' own migration for why. A MAC seen in a previous sync
 * but absent from this one is marked `is_active=false`, never deleted — the
 * whole point of this table is "who connected when", not just a live
 * snapshot.
 *
 * Host instance numbers under `Hosts.Host.{n}` are confirmed NOT stable or
 * sequential on real hardware (a live ZTE F663NV3.1 reported indices
 * 7/10/11/67/68, a live Huawei EG8141A5 reported 1/2) — mac_address, never
 * `{n}`, is the only safe identity key, exactly as this table's unique
 * constraint assumes.
 */
class CpeConnectedHostsService
{
    public function __construct(
        private readonly GenieAcsClientService $genieAcsClient,
    ) {}

    public function syncFromGenieAcs(CpeDevice $device): void
    {
        if ($device->genieacs_device_id === null) {
            return;
        }

        $genieAcsDevice = $this->genieAcsClient->findDeviceById($device->genieacs_device_id);

        if ($genieAcsDevice === null) {
            return;
        }

        $hosts = $this->extractHosts($genieAcsDevice);
        $seenMacAddresses = [];

        foreach ($hosts as $host) {
            $seenMacAddresses[] = $host['mac_address'];

            $this->upsertHost($device, $host);
        }

        // Previously-recorded MACs absent from this poll: still-active rows
        // flip to inactive. Rows already inactive are left alone (no
        // pointless writes). Never deleted — see class docblock.
        // withoutGlobalScopes(): same defensive posture as
        // CpeBindingService's own queries — this runs from a scheduled
        // command with no authenticated user (where BelongsToTenant/
        // BelongsToResellerScope are no-ops anyway), but stays correct even
        // if this method is ever called from an authenticated context too.
        CpeConnectedHost::withoutGlobalScopes()
            ->where('cpe_device_id', $device->id)
            ->where('is_active', true)
            ->when(
                $seenMacAddresses !== [],
                fn ($query) => $query->whereNotIn('mac_address', $seenMacAddresses)
            )
            ->update(['is_active' => false]);
    }

    /**
     * @param  array{mac_address: string, hostname: ?string, ip_address: ?string, active: bool}  $host
     */
    private function upsertHost(CpeDevice $device, array $host): void
    {
        $existing = CpeConnectedHost::withoutGlobalScopes()
            ->where('cpe_device_id', $device->id)
            ->where('mac_address', $host['mac_address'])
            ->first();

        if ($existing === null) {
            CpeConnectedHost::create([
                'cpe_device_id' => $device->id,
                'tenant_id' => $device->tenant_id,
                'reseller_id' => $device->reseller_id,
                'mac_address' => $host['mac_address'],
                'hostname' => $host['hostname'],
                'ip_address' => $host['ip_address'],
                'is_active' => $host['active'],
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            return;
        }

        $existing->update([
            // Only overwrite hostname/ip_address when THIS poll actually
            // has a value — a device that momentarily reports an empty
            // HostName shouldn't erase a previously-known one; first_seen_at
            // is never touched here at all, by design.
            'hostname' => $host['hostname'] ?? $existing->hostname,
            'ip_address' => $host['ip_address'] ?? $existing->ip_address,
            'is_active' => $host['active'],
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Walks every `LANDevice.{i}.Hosts.Host.{n}` entry under whichever root
     * (TR-098 `InternetGatewayDevice` or TR-181 `Device`) actually has data
     * — same fallback order as GenieAcsClientService::getStandardIdentity().
     * Skips entries with no MACAddress at all (nothing to key a row on) and
     * normalizes an empty-string HostName to null rather than storing a
     * meaningless blank.
     *
     * @param  array<string, mixed>  $genieAcsDevice
     * @return list<array{mac_address: string, hostname: ?string, ip_address: ?string, active: bool}>
     */
    private function extractHosts(array $genieAcsDevice): array
    {
        foreach (['InternetGatewayDevice', 'Device'] as $root) {
            $lanDevices = $genieAcsDevice[$root]['LANDevice'] ?? null;

            if (! is_array($lanDevices)) {
                continue;
            }

            $hosts = [];

            foreach ($lanDevices as $lanKey => $lanDevice) {
                if (str_starts_with((string) $lanKey, '_') || ! is_array($lanDevice)) {
                    continue;
                }

                $hostEntries = $lanDevice['Hosts']['Host'] ?? null;

                if (! is_array($hostEntries)) {
                    continue;
                }

                foreach ($hostEntries as $hostKey => $host) {
                    if (str_starts_with((string) $hostKey, '_') || ! is_array($host)) {
                        continue;
                    }

                    $mac = $host['MACAddress']['_value'] ?? null;

                    if (! is_string($mac) || $mac === '') {
                        continue;
                    }

                    $hostName = $host['HostName']['_value'] ?? null;

                    $hosts[] = [
                        'mac_address' => strtoupper($mac),
                        'hostname' => ($hostName === '' ? null : $hostName),
                        'ip_address' => $host['IPAddress']['_value'] ?? null,
                        'active' => (bool) ($host['Active']['_value'] ?? false),
                    ];
                }
            }

            if ($hosts !== []) {
                return $hosts;
            }
        }

        return [];
    }
}
