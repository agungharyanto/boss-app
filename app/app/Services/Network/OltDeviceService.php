<?php

namespace App\Services\Network;

use App\Enums\OltConnectionTestResult;
use App\Models\Nas;
use App\Models\OltDevice;
use App\Services\Network\Contracts\RouterOsGateway;
use Illuminate\Support\Carbon;

/**
 * v0.8.1 — OLT Credential Registry. Business logic (BOSS-006) for
 * App\Livewire\Network\OltDeviceIndex: create/update the registry row, and
 * test reachability THROUGH the OLT's assigned NAS (never directly from
 * boss-app — these OLTs sit on a private management network behind a
 * Mikrotik, exactly the same topology reasoning as
 * CpeDeviceDiagnosticService/the old CpeDeviceStatusSyncService before its
 * v0.7.7 hybrid rewrite).
 */
class OltDeviceService
{
    public function __construct(
        private readonly RouterOsGateway $routerOsGateway,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $tenantId, ?int $resellerId): OltDevice
    {
        $data['tenant_id'] = $tenantId;
        $data['reseller_id'] = $resellerId;

        return OltDevice::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(OltDevice $oltDevice, array $data): OltDevice
    {
        $oltDevice->update($data);

        return $oltDevice->fresh();
    }

    public function delete(OltDevice $oltDevice): void
    {
        $oltDevice->delete();
    }

    /**
     * Ping-level reachability ONLY (SSH/Telnet login verification is
     * explicitly out of scope this sprint — see docs/ROADMAP.md v0.8.1).
     * Reuses RouterOsGateway::pingHost() — the exact same mechanism
     * App\Services\Network\CpeDeviceDiagnosticService and the pre-v0.7.7
     * CpeDeviceStatusSyncService already rely on, so no new
     * router-connection code was written for this.
     *
     * The "duration" in the returned message is wall-clock time for this
     * whole call (connect + /ping round-trip), NOT the raw ICMP RTT
     * RouterOS itself measured — pingHost()'s own return type is a plain
     * bool (see its docblock), so a true per-packet RTT isn't available
     * here without changing that shared interface, which this sprint
     * deliberately didn't do. Good enough to show "did this respond, and
     * roughly how long did it take" without overstating precision.
     *
     * @return array{result: OltConnectionTestResult, message: string}
     */
    public function testConnection(Nas $nas, string $ipAddress): array
    {
        $start = microtime(true);
        $reachable = $this->routerOsGateway->pingHost($nas, $ipAddress, 2);
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        if ($reachable) {
            return [
                'result' => OltConnectionTestResult::Success,
                'message' => "Berhasil terhubung ke {$ipAddress} lewat {$nas->name} ({$elapsedMs}ms).",
            ];
        }

        return [
            'result' => OltConnectionTestResult::Failed,
            'message' => "Tidak ada balasan dari {$ipAddress} lewat {$nas->name} — timeout atau unreachable ({$elapsedMs}ms).",
        ];
    }

    /**
     * Persists the result of testConnection() onto the row itself, purely
     * for the DataTables list's "Status koneksi terakhir" column — the
     * actual SAVE-gating logic (has ip_address/nas_id changed since the
     * last successful test, in THIS form session) lives in
     * OltDeviceIndex's own component state, not here: that's a live
     * form-editing concern, not something derivable from persisted data
     * alone.
     *
     * @param  array{result: OltConnectionTestResult, message: string}  $testOutcome
     */
    public function recordConnectionTest(OltDevice $oltDevice, array $testOutcome): OltDevice
    {
        $oltDevice->update([
            'last_connection_test_at' => Carbon::now(),
            'last_connection_test_result' => $testOutcome['result'],
            'last_connection_test_message' => $testOutcome['message'],
        ]);

        return $oltDevice->fresh();
    }

    /**
     * RFC 1918 private-range check, per BOSS-005: an OLT's ip_address must
     * never be a public IP (these devices sit on a private management
     * network reached only via the assigned NAS's own router). Uses PHP's
     * own FILTER_FLAG_NO_PRIV_RANGE flag rather than hand-rolled CIDR
     * string matching — filter_var() with this flag returns false
     * specifically when the address IS in a private/reserved range, which
     * is exactly the condition this method needs to detect.
     */
    public static function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
    }
}
