<?php

namespace App\Services\Network;

use App\Enums\NetworkProfileGroupType;
use App\Jobs\PushNetworkProfileGroupToMikrotikJob;
use App\Jobs\PushPppPackageToMikrotikJob;
use App\Jobs\RemoveNetworkProfileGroupFromMikrotikJob;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v0.14.3 — NetworkProfileGroup business logic per BOSS-006. Two
 * independent side effects on create()/update()/delete(), both real, both
 * confirmed with Agung before implementing:
 *
 * 1. RouterOS live-push (async Job) — same pattern as CustomerIpPoolService
 *    (v0.14.2.1), reused, not reinvented.
 * 2. FreeRADIUS radgroupreply sync (SYNCHRONOUS, not queued) — a genuinely
 *    NEW pattern in this codebase (radgroupcheck/radgroupreply/
 *    radusergroup were confirmed empty and unused everywhere before this),
 *    explicitly requested by Agung rather than the established per-user
 *    Framed-Pool-in-radreply pattern (331 real customers already migrated
 *    that way, see CLAUDE.md's "PPP local-secret → RADIUS migration"
 *    section). Synchronous because `radius_db` is a local, reliable
 *    Postgres connection — same reliability posture RadiusSessionHistoryService
 *    already treats it with — never a real network call to a remote
 *    router the way RouterOS live-push is, so it doesn't need the
 *    async/retry treatment that justifies a queued Job.
 *
 * radgroupcheck/radusergroup are deliberately NOT populated by this
 * service — see writeRadiusGroupReply()'s own docblock for why.
 */
class NetworkProfileGroupService
{
    /**
     * tenant_id is auto-filled by BelongsToTenant's creating() hook.
     *
     * @param  array{nas_id: int, name: string, type: string, customer_ip_pool_id: int, dns_primary?: ?string, dns_secondary?: ?string, parent_queue?: ?string, interface_name?: ?string, service_name?: ?string, is_active?: bool}  $data
     */
    public function create(array $data): NetworkProfileGroup
    {
        $group = NetworkProfileGroup::create($this->normalizeInterfaceFields($data));
        $group->refresh();

        $this->writeRadiusGroupReply($group);
        $this->repushCollidingPppPackages($group);
        PushNetworkProfileGroupToMikrotikJob::dispatch($group->id);

        return $group;
    }

    /**
     * @param  array{nas_id?: int, name?: string, type?: string, customer_ip_pool_id?: int, dns_primary?: ?string, dns_secondary?: ?string, parent_queue?: ?string, interface_name?: ?string, service_name?: ?string, is_active?: bool}  $data
     */
    public function update(NetworkProfileGroup $group, array $data): NetworkProfileGroup
    {
        $group->update($this->normalizeInterfaceFields($data, $group));
        $group->markSyncPending();

        $this->writeRadiusGroupReply($group->fresh());
        $this->repushCollidingPppPackages($group->fresh());
        PushNetworkProfileGroupToMikrotikJob::dispatch($group->id);

        return $group->refresh();
    }

    /**
     * FIX 2 (aturan nama final 2026-09-05) — Grup Profil (ppp) SELALU push
     * nama `/ppp profile` verbatim (dia "anchor"). Kalau Grup Profil ini
     * dibuat/di-rename jadi senama Profil PPP yang SUDAH ter-sync di NAS
     * yang sama, Profil PPP itu harus geser duluan ke nama ber-suffix
     * (`PppPackage::routerOsProfileName()` akan mengembalikan
     * "{nama} (pkg #{id})" begitu Grup Profil senama muncul) — jadi
     * re-dispatch push-nya SEBELUM push Grup Profil ini sendiri (FIFO di
     * `boss-worker` single-worker: paket geser dulu, baru Grup Profil
     * klaim nama verbatim). Idempoten — kalau paket sudah pakai suffix,
     * re-push cuma menghasilkan `set` by-comment yang sama.
     */
    private function repushCollidingPppPackages(NetworkProfileGroup $group): void
    {
        if ($group->type !== NetworkProfileGroupType::Ppp) {
            return;
        }

        PppPackage::whereHas('networkProfileGroup', fn ($query) => $query->where('nas_id', $group->nas_id))
            ->where('name', $group->name)
            ->pluck('id')
            ->each(fn (int $id) => PushPppPackageToMikrotikJob::dispatch($id));
    }

    /**
     * Revisi Grup Profil — interface_name/service_name (PPPoE Server
     * binding) are only ever meaningful for type=ppp. The single
     * authoritative place this rule is enforced, so both entry points
     * (NetworkProfileGroupIndex Livewire, the REST API via
     * Store/UpdateNetworkProfileGroupRequest) get the same guarantee — a
     * caller sending both while type=hotspot never persists them, rather
     * than each caller needing to remember to null them out itself.
     *
     * Also triggers on a bare `type` change alone (no interface_name/
     * service_name in the same request) — an update() switching an
     * existing Ppp-type group to Hotspot must clear whatever stale
     * interface_name/service_name it already had stored, not just skip
     * normalization because THIS particular request didn't happen to
     * mention those two keys.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeInterfaceFields(array $data, ?NetworkProfileGroup $existing = null): array
    {
        $touchesInterfaceFields = array_key_exists('interface_name', $data) || array_key_exists('service_name', $data);
        $touchesType = array_key_exists('type', $data);

        if (! $touchesInterfaceFields && ! $touchesType) {
            return $data;
        }

        $type = $data['type'] ?? $existing?->type->value;

        if ($type !== NetworkProfileGroupType::Ppp->value) {
            $data['interface_name'] = null;
            $data['service_name'] = null;
        }

        return $data;
    }

    /**
     * Soft delete — same "will be referenced by Profil Hotspot/Profil PPP
     * (v0.14.4/v0.14.5)" reasoning as CustomerIpPoolService::delete().
     */
    public function delete(NetworkProfileGroup $group): void
    {
        $groupName = $group->radiusGroupName();

        $group->delete();

        // radgroupreply is FreeRADIUS operational config BOSS App fully
        // owns/created — safe to hard-delete outright, unlike the router
        // push (which touches infrastructure BOSS App doesn't own the
        // full lifecycle of, see RemoveNetworkProfileGroupFromMikrotikJob).
        // Column names are lowercase (`groupname`, not `GroupName`) — real
        // bug caught running this for the first time: schema.sql's DDL
        // writes them mixed-case, but Postgres folds any UNQUOTED
        // identifier to lowercase at creation time, while Laravel's query
        // builder double-quotes column names (case-sensitive) — matches
        // the same lowercase convention RadiusSessionHistoryService
        // already established for radacct.username.
        DB::connection('radius')->table('radgroupreply')->where('groupname', $groupName)->delete();

        RemoveNetworkProfileGroupFromMikrotikJob::dispatch($group->id);
    }

    public function resync(NetworkProfileGroup $group): void
    {
        $group->markSyncPending();

        PushNetworkProfileGroupToMikrotikJob::dispatch($group->id);
    }

    /**
     * Rewrites (delete-then-insert, same "rewrite wholesale" idiom already
     * established in this codebase for chap-secrets — see CLAUDE.md's
     * L2TP/IPsec section) every radgroupreply row for this group's own
     * stable GroupName. Mirrors the EXACT 3-attribute shape already used
     * for the 295+ real per-user PPP radreply rows (Service-Type/
     * Framed-Protocol/Framed-Pool, same op values) for type=Ppp;
     * type=Hotspot gets only Framed-Pool + the standard RFC 2865
     * Login-User Service-Type (the conventional value for a
     * web-authenticated session, distinct from PPP's Framed-User).
     *
     * radgroupcheck is deliberately NOT populated — no meaningful
     * per-group CHECK attribute exists at this abstraction level yet
     * (reserved for a future need, e.g. Simultaneous-Use limits).
     * radusergroup is deliberately NOT populated by NetworkProfileGroup
     * itself — it has no individual customer/user concept at all (that's
     * Profil PPP/Profil Hotspot's job, v0.14.4/v0.14.5, once a real
     * customer is linked to a package that references this group) — until
     * a customer's own radusergroup row references this GroupName, these
     * radgroupreply rows have no live effect on any real RADIUS
     * authentication, matching the same "infrastructure ahead of the
     * feature that uses it" pattern already established elsewhere in this
     * codebase (e.g. v0.3.3 Tax Engine built before Invoicing existed).
     */
    private function writeRadiusGroupReply(NetworkProfileGroup $group): void
    {
        $groupName = $group->radiusGroupName();

        // Defense in depth — Store/UpdateNetworkProfileGroupRequest's own
        // Rule::exists()->whereNull('deleted_at') should already prevent
        // this at every legitimate call site, but a real bug was caught
        // here directly (via manual verification, not a unit test): the
        // FK's restrictOnDelete() only blocks a HARD delete, never a soft
        // one, so a pool referenced by an already-saved group can still
        // become soft-deleted independently later. Skip cleanly rather
        // than crash on a null->name access.
        if ($group->customerIpPool === null) {
            Log::warning("NetworkProfileGroupService: CustomerIpPool untuk NetworkProfileGroup #{$group->id} tidak ditemukan (mungkin sudah dihapus) — radgroupreply dilewati.");

            return;
        }

        $poolName = $group->customerIpPool->name;

        DB::connection('radius')->table('radgroupreply')->where('groupname', $groupName)->delete();

        $rows = $group->type === NetworkProfileGroupType::Ppp
            ? [
                ['groupname' => $groupName, 'attribute' => 'Service-Type', 'op' => '=', 'value' => 'Framed-User'],
                ['groupname' => $groupName, 'attribute' => 'Framed-Protocol', 'op' => '=', 'value' => 'PPP'],
                ['groupname' => $groupName, 'attribute' => 'Framed-Pool', 'op' => ':=', 'value' => $poolName],
            ]
            : [
                ['groupname' => $groupName, 'attribute' => 'Service-Type', 'op' => '=', 'value' => 'Login-User'],
                ['groupname' => $groupName, 'attribute' => 'Framed-Pool', 'op' => ':=', 'value' => $poolName],
            ];

        DB::connection('radius')->table('radgroupreply')->insert($rows);
    }
}
