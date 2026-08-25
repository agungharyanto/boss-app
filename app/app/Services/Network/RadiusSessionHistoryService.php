<?php

namespace App\Services\Network;

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v0.8.4 — "Riwayat Dialup" on the CPE detail page, reading `radacct`
 * (RADIUS accounting) via the separate `radius` Eloquent connection
 * (config/database.php, points at `radius_db` — a genuinely different
 * Postgres instance from `boss_db`, BOSS-009). Query stays entirely
 * inside `radacct` — matching a customer to their own rows happens in
 * PHP (usernames resolved from `boss_db.customers` first, then used to
 * filter the `radius_db` query), never a cross-database SQL join.
 *
 * **Username resolution, confirmed by direct investigation before writing
 * this** (see CLAUDE.md): `customers.phone_number` is what the v0.12
 * migration batch actually inserted as `radcheck`/`radacct` username for
 * the vast majority of customers — but 13 of 551 customers have a
 * `legacy_username` that DIFFERS from `phone_number` (the batch matched
 * candidates via `phone_number` OR `legacy_username`, so either could be
 * the real RADIUS username for those 13). Both are tried — `WHERE
 * username IN (phone_number, legacy_username)`, deduplicated — rather
 * than guessing which one a given customer actually uses.
 *
 * **A customer who has never dialed since RADIUS accounting was
 * (re-)enabled — including one already genuinely authenticating via
 * RADIUS right now, if their session started before the `-sql` flip —
 * legitimately has ZERO rows here.** This is not an error state: a
 * `radacct` row is only ever written on Accounting-Start/-Stop, and old
 * sessions from before accounting was enabled can't be reconstructed
 * except for whichever ONE Stop packet happens to arrive after re-
 * enabling (which does carry the whole session's summary — RADIUS Stop
 * packets are self-contained, not deltas). A customer never migrated to
 * RADIUS at all (still on a `test-x86-bajastu` local secret) also
 * legitimately has zero rows — same empty state, no way to distinguish
 * the two from `radacct` alone, and no need to: both mean "nothing to
 * show yet" from this feature's point of view.
 *
 * `Acct-Interim-Interval` is NOT configured anywhere in this RADIUS
 * setup (confirmed: zero `radreply` rows, zero active raddb references) —
 * a still-ACTIVE session's row (`acctstoptime IS NULL`) never gets a
 * mid-session refresh, so `acctsessiontime`/`acctinputoctets`/
 * `acctoutputoctets` stay at whatever they were at Accounting-Start
 * (typically 0) until the session actually ends. `sessionSeconds()`
 * computes a live approximate duration for that case (`now() -
 * acctstarttime`) rather than showing a static "0" for an obviously
 * still-running session — upload/download stay 0 until Stop, since
 * there's no better data to show without interim updates (out of scope
 * to configure here, see CLAUDE.md).
 */
class RadiusSessionHistoryService
{
    /**
     * @return array<int, array{acct_id: int, started_at: ?Carbon, stopped_at: ?Carbon, is_active: bool, session_seconds: int, nas_ip: ?string, upload_bytes: int, download_bytes: int, terminate_cause: ?string}>
     */
    public function getHistoryForCustomer(Customer $customer, int $limit = 50): array
    {
        $usernames = $this->candidateUsernames($customer);

        if ($usernames === []) {
            return [];
        }

        $rows = DB::connection('radius')
            ->table('radacct')
            ->whereIn('username', $usernames)
            ->orderByDesc('acctstarttime')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) {
            // radacct's timestamptz columns arrive already correctly
            // offset for Asia/Jakarta — the `radius` connection itself is
            // forced to that session timezone (config/database.php's
            // 'timezone' key, `SET time zone` on connect), not converted
            // here in PHP. A plain Carbon::parse() is enough.
            $startedAt = $row->acctstarttime !== null ? Carbon::parse($row->acctstarttime) : null;
            $stoppedAt = $row->acctstoptime !== null ? Carbon::parse($row->acctstoptime) : null;

            return [
                'acct_id' => (int) $row->radacctid,
                'started_at' => $startedAt,
                'stopped_at' => $stoppedAt,
                'is_active' => $stoppedAt === null,
                'session_seconds' => $this->sessionSeconds($row, $startedAt, $stoppedAt),
                'nas_ip' => $row->nasipaddress,
                'upload_bytes' => (int) ($row->acctinputoctets ?? 0),
                'download_bytes' => (int) ($row->acctoutputoctets ?? 0),
                'terminate_cause' => $row->acctterminatecause,
            ];
        })->all();
    }

    /**
     * @return array<int, string>
     */
    private function candidateUsernames(Customer $customer): array
    {
        return array_values(array_unique(array_filter([
            $customer->phone_number,
            $customer->legacy_username,
        ])));
    }

    private function sessionSeconds(object $row, ?Carbon $startedAt, ?Carbon $stoppedAt): int
    {
        if ($stoppedAt !== null) {
            return (int) ($row->acctsessiontime ?? 0);
        }

        // Still active, no interim updates ever refresh acctsessiontime —
        // approximate from wall-clock time elapsed since Accounting-Start.
        return $startedAt !== null ? max(0, (int) $startedAt->diffInSeconds(now())) : 0;
    }

    /**
     * Dynamic-unit byte formatting (B/KB/MB/GB) — same "pick one unit that
     * fits the magnitude" spirit as `resources/js/app.js`'s `pickBpsUnit`
     * (Monitoring traffic graph), not a literal reuse: that helper formats
     * a bit RATE client-side for a chart axis, this formats a cumulative
     * BYTE total server-side for a table cell — different unit family,
     * same UX principle, no existing PHP helper for this in the codebase
     * to reuse instead.
     */
    public function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return number_format($value, $power === 0 ? 0 : 2).' '.$units[$power];
    }
}
