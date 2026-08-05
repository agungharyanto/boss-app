<?php

namespace App\Services\Network;

use App\Exceptions\NasPortPoolExhaustedException;
use App\Models\NasPortAllocatorState;
use Illuminate\Support\Facades\DB;

/**
 * Race-condition-safe allocation of a (auth_port, acct_port) pair for a new
 * NAS — same "lock a real row inside a transaction" pattern as
 * VpnProvisioningService's vpn_ip_pool allocation, adapted for a plain
 * monotonic counter instead of a pre-seeded pool of individually lockable
 * rows: ports aren't a finite pre-enumerable resource the way IP addresses
 * in a /24 are, so there's nothing to seed ahead of time — just a single
 * counter row (nas_port_allocator_state, id=1) locked with lockForUpdate().
 *
 * Deliberately a plain row lock, NOT a Postgres pg_advisory_xact_lock — the
 * simpler mechanism is fully portable to the sqlite connection the test
 * suite runs on (phpunit.xml), and this is a low-frequency operation (an
 * admin creating a NAS through the UI), not a hot path that needs
 * finer-grained locking.
 *
 * coa_port is deliberately NOT allocated here (a real design mistake this
 * class made initially, corrected before v0.6.5 shipped — caught by
 * checking a real router's actual `/radius incoming` config, not by
 * inspection): unlike auth_port/acct_port, which must be unique because
 * many NAS share ONE FreeRADIUS server that can't tell them apart by
 * source IP (VPN MASQUERADE), CoA runs in the OPPOSITE direction — BOSS
 * App is the CLIENT, each NAS's own RouterOS `/radius incoming port=` is
 * the server, and that's a single ROUTER-WIDE setting (not per-RADIUS-
 * entry) that has no reason to differ per NAS. `nas.coa_port` stays a
 * plain, non-unique, admin-editable column defaulting to 3799 (RFC 5176) —
 * it records what THAT NAS's own CoA listener is already configured to,
 * not something BOSS App hands out.
 *
 * Never reclaims a port once allocated (no "release back to pool" — unlike
 * vpn_ip_pool, a deleted NAS's ports simply stay retired forever). At 10
 * ports/NAS and a range of ~45,000 usable ports (20000-65000, see the
 * nas_port_allocator_state migration's own comment for why 20000 and not a
 * rounder 18120/19999 — a real collision with FreeRADIUS's own stock
 * inner-tunnel listener was found deploying this for real), that's
 * headroom for ~4,500 NAS ever created over this deployment's lifetime
 * before NasPortPoolExhaustedException — acceptable for an ISP's NAS
 * inventory; revisit if that ever becomes a real constraint.
 */
class NasPortAllocatorService
{
    private const RANGE_END = 65000;

    private const STEP = 10;

    /**
     * @return array{auth_port: int, acct_port: int}
     */
    public function allocate(): array
    {
        return DB::transaction(function () {
            $state = NasPortAllocatorState::query()->lockForUpdate()->findOrFail(1);

            $authPort = $state->next_auth_port;

            if ($authPort + 1 > self::RANGE_END) {
                throw new NasPortPoolExhaustedException(
                    'Rentang port NAS (sampai '.self::RANGE_END.') sudah habis — tidak ada slot auth/acct tersisa untuk NAS baru.'
                );
            }

            $state->update(['next_auth_port' => $authPort + self::STEP]);

            return [
                'auth_port' => $authPort,
                'acct_port' => $authPort + 1,
            ];
        });
    }
}
