<?php

namespace App\Models;

use App\Support\CidrRange;
use Database\Factories\VpnWireguardNasBlockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * v0.8.1 — one row per NAS, permanently, once it has ever needed a
 * WireGuard account. See the migration's own docblock for the full
 * "sticky /30 block, not a release-and-reuse pool" reasoning.
 */
class VpnWireguardNasBlock extends Model
{
    /** @use HasFactory<VpnWireguardNasBlockFactory> */
    use HasFactory;

    protected $fillable = [
        'nas_id',
        'vpn_server_id',
        'block_index',
        'gateway_ip',
        'router_ip',
    ];

    public function nas(): BelongsTo
    {
        return $this->belongsTo(Nas::class);
    }

    public function vpnServer(): BelongsTo
    {
        return $this->belongsTo(VpnServer::class);
    }

    /**
     * Sticky lookup-or-create: a NAS that already has a block keeps it
     * forever (same row returned on every call) — only a NAS asking for
     * the VERY FIRST time gets a new block_index allocated, FCFS by
     * whichever NAS gets here first (not nas.id order).
     *
     * The outer existence check (before the transaction) is a fast path
     * for the overwhelmingly common case (an already-provisioned NAS
     * being revoked/reprovisioned yet again) — the re-check INSIDE the
     * transaction (after acquiring the lock below) is what actually makes
     * first-time concurrent allocation race-safe.
     *
     * REAL bug found deploying this for real against PostgreSQL (not
     * caught by the test suite, which runs on SQLite): the original
     * version locked `MAX(block_index)` directly
     * (`->lockForUpdate()->max(...)`) — PostgreSQL outright REJECTS
     * `SELECT MAX(...) ... FOR UPDATE` ("Feature not supported"), since
     * there's no single row to lock when the result is an aggregate (and
     * genuinely NO row to lock at all on this pool owner's very first
     * allocation, when the table has zero matching rows yet). Fixed by
     * locking the POOL OWNER's own `vpn_servers` row instead — that row
     * always exists, so it works as a mutex regardless of how many (if
     * any) `vpn_wireguard_nas_blocks` rows currently exist under it. Every
     * concurrent `allocateFor()` call for the SAME pool owner serializes
     * on this one lock; the subsequent plain (now safely serialized, no
     * FOR UPDATE needed) `MAX()` query is then race-free on any driver.
     */
    public static function allocateFor(Nas $nas, VpnServer $poolOwner): self
    {
        $existing = static::where('nas_id', $nas->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($nas, $poolOwner) {
            VpnServer::query()->whereKey($poolOwner->id)->lockForUpdate()->first();

            $existing = static::where('nas_id', $nas->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            // max() returns null on an empty set (this pool owner's very
            // first block ever) — must NOT cast that to 0 and add 1 (that
            // would wrongly skip block_index 0 entirely), so the null case
            // is handled explicitly.
            $maxIndex = static::where('vpn_server_id', $poolOwner->id)->max('block_index');
            $nextIndex = $maxIndex === null ? 0 : $maxIndex + 1;

            $addresses = CidrRange::wireguardNasBlock($poolOwner->subnet_cidr, $nextIndex);

            return static::create([
                'nas_id' => $nas->id,
                'vpn_server_id' => $poolOwner->id,
                'block_index' => $nextIndex,
                'gateway_ip' => $addresses['gateway'],
                'router_ip' => $addresses['router'],
            ]);
        });
    }
}
