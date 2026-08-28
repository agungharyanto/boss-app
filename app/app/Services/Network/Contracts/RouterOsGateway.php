<?php

namespace App\Services\Network\Contracts;

use App\Models\Nas;

/**
 * Boundary around the actual MikroTik RouterOS API transport (raw sockets,
 * not HTTP — evilfreelancer/routeros-api-php under the hood via
 * RouterOsApiGateway) so NasService stays testable without a real router:
 * tests bind a fake implementation instead of relying on something like
 * Http::fake(), which doesn't apply here since the client speaks the RouterOS
 * binary API over a socket, not HTTP.
 */
interface RouterOsGateway
{
    /**
     * @return array{online: bool, message: ?string}
     */
    public function ping(Nas $nas): array;

    /**
     * Real ICMP ping (`/ping address=... count=...`) issued FROM $nas's own
     * router toward $targetIp — not a connection attempt from boss-app
     * itself. Built for App\Services\Network\CpeDeviceStatusSyncService:
     * checking a CPE's reachability from boss-app directly over the
     * WireGuard hub-and-spoke tunnel never worked (confirmed empirically —
     * even genieacs-cwmp, which the v0.7.3 routing work specifically
     * targeted, times out reaching a real CPE's TR-069 management IP), but
     * the NAS router itself sits directly on the CPE's own local VLAN with
     * no tunnel involved at all.
     *
     * Requires the RouterOS API user's group to include the `test` policy
     * category (ping falls under `test`, not `write`) — the existing
     * `boss-app-api` group deliberately excludes it (see
     * RouterOsApiGateway::API_USER_POLICY's own docblock); a real router
     * grant is needed before this returns anything but false.
     */
    public function pingHost(Nas $nas, string $targetIp, int $count = 2): bool;

    /**
     * Creates/replaces a dedicated, restricted-policy API user on the
     * router (group `boss-app-api`, idempotently ensured) — v0.6.5.
     * Connects using $connectAsUsername/$connectAsPassword, which is
     * DELIBERATELY separate from $nas's own stored api_username/
     * api_password: the caller passes the NAS owner's real admin
     * credential for the one-time initial provisioning, or the NAS's own
     * current (already-provisioned) API credential for later self-
     * rotation — this method itself doesn't know or care which, it just
     * connects with whatever it's given and creates $newApiUsername/
     * $newApiPassword. Never persists anything — the caller
     * (NasApiUserProvisioningService) is responsible for writing the new
     * credential onto $nas afterward, only on success.
     *
     * @return array{success: bool, message: ?string}
     */
    public function provisionApiUser(
        Nas $nas,
        string $connectAsUsername,
        string $connectAsPassword,
        string $newApiUsername,
        string $newApiPassword,
    ): array;

    /**
     * v0.8.1 fragment+reconcile (App\Console\Commands\VpnSyncRouteFragments)
     * — asks $nas's own router which WireGuard listen port its tunnel is
     * CURRENTLY connected to (`current-endpoint-port` on the matching
     * `/interface/wireguard/peers` entry, found by its own
     * "... NAS {$account->username}" comment — see
     * MikrotikScriptGenerator::wireGuardScript()). This is the ONLY
     * reliable way to know which of the 3 pool nodes a NAS's auto-switch-
     * capable tunnel currently lives on — that decision happens entirely
     * client-side on the router (v0.6.4 auto-switch script), invisible to
     * boss-app any other way. Returns null if the NAS has no matching
     * WireGuard peer, or the query fails for any reason (caller treats
     * null the same as "can't currently be determined" — never guesses).
     */
    public function currentWireguardEndpointPort(Nas $nas, string $peerCommentNeedle): ?int;

    /**
     * v0.14.2.1 — RouterOS live-push, starting with CustomerIpPool. Idempotent
     * create/update of a single `/ip pool` entry, found by $comment (a
     * stable per-row identifier — see CustomerIpPool::mikrotikComment())
     * rather than by $name: a pool can be RENAMED in BOSS App, and looking
     * up by name would then create an orphaned duplicate on the router
     * instead of updating the existing one — same reasoning
     * RouterOsApiGateway::ensureUser()/ensureGroup() already established
     * for `/user`/`/user/group`. $ranges is RouterOS's own
     * "start-end" range syntax (e.g. "192.168.10.10-192.168.10.200").
     *
     * @return array{success: bool, message: ?string}
     */
    public function syncIpPool(Nas $nas, string $comment, string $name, string $ranges): array;

    /**
     * Removes the `/ip pool` entry matching $comment, if any — a no-op
     * (success=true) when nothing matches, since deleting something
     * already gone from the router is not a failure. Can genuinely fail
     * (success=false) if RouterOS refuses because the pool is still
     * referenced elsewhere (a DHCP server/PPP profile using it) — that
     * error message is surfaced to the caller, not silently swallowed.
     *
     * @return array{success: bool, message: ?string}
     */
    public function removeIpPool(Nas $nas, string $comment): array;

    /**
     * v0.14.3 — Grup Profil, type PPP. Idempotent create/update of a
     * single `/ppp profile` entry, found by $comment (same reasoning as
     * syncIpPool() above — a Grup Profil can be renamed). $remoteAddress
     * is a `/ip pool` NAME (RouterOS resolves the pool by name, not a raw
     * range) — confirmed on the real router that `/ppp profile`'s
     * `remote-address` field genuinely accepts a pool name this way (the
     * existing HomeFixed-10Mbps/PPPOE-REMOTE profiles already do this).
     * $dnsServer is a comma-separated string ("8.8.8.8,8.8.4.4") or null
     * (omitted entirely — confirmed `dns-server` is a real, optional
     * field). $parentQueue is a raw queue name or null (confirmed
     * `parent-queue` is a real field on this RouterOS version via a live
     * add/remove round-trip against ro-hotspot.bajastu.id).
     *
     * v0.14.x revisi (Grup Profil interface/VLAN + expired fallback) —
     * $remoteAddress widened from required `string` to `?string`, and a new
     * $localAddress param added, to support the "Profil Pelanggan Expired"
     * fallback profile (`local-address` = a limited pool, `remote-address`
     * genuinely omitted — Agung's own real Winbox reference pattern).
     * BOTH fields are `/ip pool` NAME references, confirmed via a live test
     * that `local-address` accepts a pool name exactly like `remote-address`
     * does. **Real, load-bearing gotcha found via a live test**: unlike
     * `dns-server`/`parent-queue` (which both genuinely accept an empty
     * string as "clear this field" on `/ppp profile/set`), RouterOS
     * REJECTS an empty string for BOTH `remote-address` and `local-address`
     * ("invalid value for argument remote-address:"/"...local-address:") —
     * the implementation must never unconditionally send an empty-string
     * fallback for these two fields the way dns-server/parent-queue safely
     * can; only include them in the query at all when genuinely non-null.
     *
     * @return array{success: bool, message: ?string}
     */
    public function syncPppProfile(Nas $nas, string $comment, string $name, ?string $remoteAddress, ?string $dnsServer, ?string $parentQueue, ?string $localAddress = null): array;

    /**
     * Removes the `/ppp profile` entry matching $comment, if any — same
     * no-op-on-missing semantics as removeIpPool().
     *
     * @return array{success: bool, message: ?string}
     */
    public function removePppProfile(Nas $nas, string $comment): array;

    /**
     * v0.14.3 — Grup Profil, type Hotspot. Confirmed empirically against a
     * real router that `/ip hotspot user profile` has NO address-pool/
     * dns-server/parent-queue fields at all (its real fields are
     * idle-timeout/shared-users/mac-cookie-timeout/etc.) — a Hotspot
     * client's IP pool is bound to the `/ip hotspot` SERVER instance
     * itself (interface-scoped), never a reusable named profile the way
     * `/ppp profile` works. Per Agung's explicit decision: this method
     * refuses with a clear, specific error if the NAS has no `/ip hotspot`
     * server configured at all yet (real infra decision for whoever runs
     * the router, not something BOSS App invents on their behalf) —
     * otherwise it updates the FIRST hotspot server found on this NAS to
     * reference $poolName as its `address-pool`. There is no companion
     * "remove" method — see PushNetworkProfileGroupToMikrotikJob's own
     * docblock for why unsetting a live server's pool on delete is
     * deliberately never attempted.
     *
     * @return array{success: bool, message: ?string}
     */
    public function syncHotspotServerPool(Nas $nas, string $poolName): array;

    /**
     * v0.14.4 — Profil Hotspot. Idempotent create/update of a single
     * `/ip hotspot user profile` entry. UNLIKE syncIpPool()/syncPppProfile()
     * above, lookup is by $lookupName, not a stable comment — confirmed
     * empirically (live add/set round trip against ro-hotspot.bajastu.id)
     * that `/ip hotspot user profile` rejects `comment` as a parameter
     * outright ("unknown parameter comment"). $lookupName is
     * HotspotPackage::mikrotikLookupName() (the name last successfully
     * pushed, so a rename can find and rename the SAME object rather than
     * creating an orphaned duplicate); $targetName is the name to actually
     * set (HotspotPackage::mikrotikTargetName(), i.e. the package's current
     * name). $rateLimit is RouterOS rate-limit syntax ("{kbps}k/{kbps}k",
     * confirmed accepted via a live test) or null (field omitted entirely).
     * $sessionTimeout is a RouterOS time-interval string (e.g. "1d") or
     * null (field omitted — confirmed via a live test that an omitted
     * session-timeout leaves the router's own "none" default in place,
     * i.e. no cap). Requires the NAS to already have a `/ip hotspot` server
     * configured — same precondition as syncHotspotServerPool(), checked
     * the same way (a Profil Hotspot always references a Grup Profil of
     * type Hotspot, which itself requires this to push at all).
     *
     * $addressPool is the `/ip pool` NAME (e.g. CustomerIpPool::name — that
     * model's own live-push already keeps the router-side pool's name in
     * sync with it, see CustomerIpPoolService/syncIpPool()'s own comment-
     * based rename handling, so this is always the CURRENT real router-side
     * name) or null (field omitted). CORRECTION to this method's own
     * original docblock/v0.14.3's docblock: `address-pool` IS a real,
     * settable field on `/ip hotspot user profile` — confirmed via a real
     * live SET round trip against ro-hotspot.bajastu.id (readback showed
     * the value take effect). The original "no address-pool field" claim
     * was wrong, not a RouterOS-version difference — it was inferred purely
     * from the field's ABSENCE in a `print` of an object that had simply
     * never had it SET (RouterOS omits every unset optional property from
     * `print` output, the exact same "print of a never-set field looks
     * identical to a nonexistent field" gotcha already documented several
     * times elsewhere in this codebase for other RouterOS objects — e.g.
     * rate-limit/session-timeout on this very object type, before THEY were
     * first set). No live SET test was ever attempted for address-pool
     * specifically in the original investigation; this claim was then
     * carried forward uncritically into a second sprint before finally
     * being re-tested for real.
     *
     * @return array{success: bool, message: ?string}
     */
    public function syncHotspotUserProfile(
        Nas $nas,
        string $lookupName,
        string $targetName,
        ?string $rateLimit,
        int $sharedUsers,
        ?string $sessionTimeout,
        ?string $addressPool = null,
    ): array;

    /**
     * Removes the `/ip hotspot user profile` entry matching $lookupName, if
     * any — same no-op-on-missing semantics as removeIpPool()/
     * removePppProfile(). Safe to actually delete (unlike Grup Profil's
     * Hotspot type, which only ever touches a shared, admin-owned `/ip
     * hotspot` SERVER object it doesn't own the lifecycle of) — a
     * `/ip hotspot user profile` created by this gateway's own
     * syncHotspotUserProfile() is fully BOSS-App-owned.
     *
     * @return array{success: bool, message: ?string}
     */
    public function removeHotspotUserProfile(Nas $nas, string $lookupName): array;

    /**
     * v0.14.x revisi — Grup Profil interface/VLAN binding. Read-only,
     * on-demand listing of $nas's own real physical/VLAN interfaces (never
     * cached/persisted in boss_db — always a live query, matching Agung's
     * own explicit instruction that this is purely a dropdown-population
     * helper, not a create/manage capability). Deliberately filtered
     * server-side to `type=ether` and `type=vlan` only — a real NAS also
     * has hundreds of dynamic `pppoe-in` interfaces (one per currently-
     * connected PPPoE session) plus BOSS App's own `wg`-type WireGuard
     * tunnel interface, none of which are ever a meaningful "bind a PPPoE
     * Server to this" choice.
     *
     * @return array<int, array{name: string, type: string}>
     */
    public function listInterfaces(Nas $nas): array;

    /**
     * v0.14.x revisi — Grup Profil (type=ppp) interface/VLAN + PPPoE
     * Server binding. Idempotent create/update of a single
     * `/interface/pppoe-server/server` entry, found by $comment — same
     * "lookup by comment, not name" reasoning as syncIpPool()/
     * syncPppProfile() above (`/interface/pppoe-server/server` was
     * confirmed via a live test to support `comment`, unlike `/ip hotspot
     * user profile`). $defaultProfile is a `/ppp profile` NAME — Grup
     * Profil's own PPP push always passes its OWN name here (see
     * PushNetworkProfileGroupToMikrotikJob's own docblock for why: the
     * bare, no-rate-limit `/ppp profile` Grup Profil already pushes since
     * v0.14.3 IS meant to be this PPPoE Server's Default Profile).
     *
     * **Real, load-bearing gotcha found via a live test**: a freshly-added
     * `/interface/pppoe-server/server` entry defaults to `disabled=true`
     * unless explicitly told otherwise — confirmed by reading back a real
     * add with no `disabled` parameter at all. The implementation always
     * explicitly sends `disabled=no`, never relies on RouterOS's own
     * default.
     *
     * @return array{success: bool, message: ?string}
     */
    public function syncPppoeServer(Nas $nas, string $comment, string $serviceName, string $interfaceName, string $defaultProfile): array;

    /**
     * Removes the `/interface/pppoe-server/server` entry matching
     * $comment, if any — same no-op-on-missing semantics as
     * removePppProfile()/removeIpPool().
     *
     * @return array{success: bool, message: ?string}
     */
    public function removePppoeServer(Nas $nas, string $comment): array;
}
