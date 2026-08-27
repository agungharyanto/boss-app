<?php

namespace App\Services\Network;

use App\Models\Nas;
use App\Services\Network\Contracts\RouterOsGateway;
use Illuminate\Support\Facades\Log;
use RouterOS\Client;
use RouterOS\Query;
use Throwable;

/**
 * Real implementation of RouterOsGateway — connects to a NAS's Mikrotik API
 * (port 8728 by default, per-row credentials, never a static config) using
 * evilfreelancer/routeros-api-php. A fresh Client is built per call rather
 * than reused/cached, mirroring WhatsappGatewayService's per-session
 * resolution — credentials are dynamic (one NAS row = one router), not a
 * single global connection.
 */
class RouterOsApiGateway implements RouterOsGateway
{
    public function ping(Nas $nas): array
    {
        try {
            $client = new Client([
                'host' => $nas->mikrotik_ip,
                'user' => $nas->api_username,
                'pass' => $nas->api_password,
                'port' => $nas->api_port,
                'timeout' => 5,
            ]);

            $client->query(new Query('/system/resource/print'))->read();

            return ['online' => true, 'message' => null];
        } catch (Throwable $e) {
            Log::warning("RouterOsApiGateway: gagal konek ke NAS #{$nas->id} ({$nas->mikrotik_ip}:{$nas->api_port}): {$e->getMessage()}");

            return ['online' => false, 'message' => $e->getMessage()];
        }
    }

    public function pingHost(Nas $nas, string $targetIp, int $count = 2): bool
    {
        try {
            $client = new Client([
                'host' => $nas->mikrotik_ip,
                'user' => $nas->api_username,
                'pass' => $nas->api_password,
                'port' => $nas->api_port,
                // RouterOS itself paces /ping at ~1s per attempt regardless
                // of reachability (it always completes count attempts, never
                // hangs indefinitely) — this just needs enough headroom for
                // that plus the connection handshake itself.
                'timeout' => $count + 5,
            ]);

            $query = new Query('/ping');
            $query->equal('address', $targetIp)->equal('count', (string) $count);

            $replies = $client->query($query)->read();

            foreach ($replies as $reply) {
                // A successful ICMP reply carries a round-trip `time` field;
                // a timed-out attempt doesn't — this is the standard way to
                // tell the two apart in RouterOS's own /ping output.
                if (isset($reply['time'])) {
                    return true;
                }

                // The router rejected the command outright (e.g. the API
                // user's group policy doesn't include `test`, which /ping
                // requires) — a real ["after"]["message"] trap, confirmed
                // against this exact router: "not enough permissions (9)".
                // Distinct from "no reply within count attempts" and worth
                // its own log line so this doesn't silently masquerade as
                // every device being offline.
                $trapMessage = $reply['after']['message'] ?? null;

                if ($trapMessage !== null) {
                    Log::warning("RouterOsApiGateway: /ping ditolak router untuk NAS #{$nas->id} ({$nas->mikrotik_ip}) — {$trapMessage}");

                    return false;
                }
            }

            return false;
        } catch (Throwable $e) {
            Log::warning("RouterOsApiGateway: gagal ping {$targetIp} via NAS #{$nas->id} ({$nas->mikrotik_ip}): {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Restricted group policy: read+api+password (password = allowed to
     * change its OWN password, needed for self-rotation later — see
     * NasApiUserProvisioningService's docblock, this exact policy shape
     * was confirmed explicitly with Agung before implementing) — no
     * local/telnet/ssh/ftp/reboot/write/policy/test/winbox/web/sniff/
     * sensitive/romon/rest-api. `!dude` deliberately excluded — a
     * RouterOS 6.x-era keyword removed in 7.x, real bug found and fixed
     * in the old script-based version of this (see MikrotikScriptGenerator
     * ::radiusScript()'s docblock).
     */
    private const API_USER_GROUP = 'boss-app-api';

    private const API_USER_POLICY = 'read,api,password,!local,!telnet,!ssh,!ftp,!reboot,!write,!policy,!test,!winbox,!web,!sniff,!sensitive,!romon,!rest-api';

    public function provisionApiUser(
        Nas $nas,
        string $connectAsUsername,
        string $connectAsPassword,
        string $newApiUsername,
        string $newApiPassword,
    ): array {
        try {
            $client = new Client([
                'host' => $nas->mikrotik_ip,
                'user' => $connectAsUsername,
                'pass' => $connectAsPassword,
                'port' => $nas->api_port,
                'timeout' => 10,
            ]);

            $this->ensureGroup($client);
            $this->ensureUser($client, $newApiUsername, $newApiPassword);

            return ['success' => true, 'message' => null];
        } catch (Throwable $e) {
            Log::warning("RouterOsApiGateway: gagal provisioning user API untuk NAS #{$nas->id} ({$nas->mikrotik_ip}:{$nas->api_port}): {$e->getMessage()}");

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * `/user/group/set` on an existing group (not remove+add) — matters
     * for the self-rotation path specifically: the very user making this
     * call might belong to this group, and a remove+recreate would
     * transiently invalidate its own session mid-operation.
     */
    private function ensureGroup(Client $client): void
    {
        $find = new Query('/user/group/print');
        $find->where('name', self::API_USER_GROUP);
        $existing = $client->query($find)->read();

        if ($existing === []) {
            $add = new Query('/user/group/add');
            $add->equal('name', self::API_USER_GROUP)->equal('policy', self::API_USER_POLICY);
            $client->query($add)->read();

            return;
        }

        $set = new Query('/user/group/set');
        $set->equal('.id', $existing[0]['.id'])->equal('policy', self::API_USER_POLICY);
        $client->query($set)->read();
    }

    private function ensureUser(Client $client, string $username, string $password): void
    {
        $find = new Query('/user/print');
        $find->where('name', $username);
        $existing = $client->query($find)->read();

        if ($existing === []) {
            $add = new Query('/user/add');
            $add->equal('name', $username)->equal('group', self::API_USER_GROUP)->equal('password', $password);
            $client->query($add)->read();

            return;
        }

        $set = new Query('/user/set');
        $set->equal('.id', $existing[0]['.id'])->equal('group', self::API_USER_GROUP)->equal('password', $password);
        $client->query($set)->read();
    }

    public function currentWireguardEndpointPort(Nas $nas, string $peerCommentNeedle): ?int
    {
        try {
            $client = new Client([
                'host' => $nas->mikrotik_ip,
                'user' => $nas->api_username,
                'pass' => $nas->api_password,
                'port' => $nas->api_port,
                'timeout' => 8,
            ]);

            $peers = $client->query(new Query('/interface/wireguard/peers/print'))->read();

            foreach ($peers as $peer) {
                if (str_contains($peer['comment'] ?? '', $peerCommentNeedle)) {
                    $port = (int) ($peer['current-endpoint-port'] ?? 0);

                    return $port > 0 ? $port : null;
                }
            }

            return null;
        } catch (Throwable $e) {
            Log::warning("RouterOsApiGateway: gagal ambil current-endpoint-port untuk NAS #{$nas->id} ({$nas->mikrotik_ip}): {$e->getMessage()}");

            return null;
        }
    }

    public function syncIpPool(Nas $nas, string $comment, string $name, string $ranges): array
    {
        try {
            $client = new Client([
                'host' => $nas->mikrotik_ip,
                'user' => $nas->api_username,
                'pass' => $nas->api_password,
                'port' => $nas->api_port,
                'timeout' => 10,
            ]);

            $find = new Query('/ip/pool/print');
            $find->where('comment', $comment);
            $existing = $client->query($find)->read();

            if ($existing === []) {
                $add = new Query('/ip/pool/add');
                $add->equal('name', $name)->equal('ranges', $ranges)->equal('comment', $comment);
                $client->query($add)->read();
            } else {
                $set = new Query('/ip/pool/set');
                $set->equal('.id', $existing[0]['.id'])->equal('name', $name)->equal('ranges', $ranges);
                $client->query($set)->read();
            }

            return ['success' => true, 'message' => null];
        } catch (Throwable $e) {
            Log::warning("RouterOsApiGateway: gagal sync /ip pool (comment={$comment}) ke NAS #{$nas->id} ({$nas->mikrotik_ip}:{$nas->api_port}): {$e->getMessage()}");

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function removeIpPool(Nas $nas, string $comment): array
    {
        try {
            $client = new Client([
                'host' => $nas->mikrotik_ip,
                'user' => $nas->api_username,
                'pass' => $nas->api_password,
                'port' => $nas->api_port,
                'timeout' => 10,
            ]);

            $find = new Query('/ip/pool/print');
            $find->where('comment', $comment);
            $existing = $client->query($find)->read();

            if ($existing === []) {
                return ['success' => true, 'message' => null];
            }

            $remove = new Query('/ip/pool/remove');
            $remove->equal('.id', $existing[0]['.id']);
            $client->query($remove)->read();

            return ['success' => true, 'message' => null];
        } catch (Throwable $e) {
            Log::warning("RouterOsApiGateway: gagal hapus /ip pool (comment={$comment}) di NAS #{$nas->id} ({$nas->mikrotik_ip}:{$nas->api_port}): {$e->getMessage()}");

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function syncPppProfile(Nas $nas, string $comment, string $name, string $remoteAddress, ?string $dnsServer, ?string $parentQueue): array
    {
        try {
            $client = new Client([
                'host' => $nas->mikrotik_ip,
                'user' => $nas->api_username,
                'pass' => $nas->api_password,
                'port' => $nas->api_port,
                'timeout' => 10,
            ]);

            $find = new Query('/ppp/profile/print');
            $find->where('comment', $comment);
            $existing = $client->query($find)->read();

            if ($existing === []) {
                $add = new Query('/ppp/profile/add');
                $add->equal('name', $name)->equal('remote-address', $remoteAddress)->equal('comment', $comment);

                if ($dnsServer !== null) {
                    $add->equal('dns-server', $dnsServer);
                }

                if ($parentQueue !== null) {
                    $add->equal('parent-queue', $parentQueue);
                }

                $client->query($add)->read();
            } else {
                $set = new Query('/ppp/profile/set');
                $set->equal('.id', $existing[0]['.id'])->equal('name', $name)->equal('remote-address', $remoteAddress);
                $set->equal('dns-server', $dnsServer ?? '');
                $set->equal('parent-queue', $parentQueue ?? 'none');
                $client->query($set)->read();
            }

            return ['success' => true, 'message' => null];
        } catch (Throwable $e) {
            Log::warning("RouterOsApiGateway: gagal sync /ppp profile (comment={$comment}) ke NAS #{$nas->id} ({$nas->mikrotik_ip}:{$nas->api_port}): {$e->getMessage()}");

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function removePppProfile(Nas $nas, string $comment): array
    {
        try {
            $client = new Client([
                'host' => $nas->mikrotik_ip,
                'user' => $nas->api_username,
                'pass' => $nas->api_password,
                'port' => $nas->api_port,
                'timeout' => 10,
            ]);

            $find = new Query('/ppp/profile/print');
            $find->where('comment', $comment);
            $existing = $client->query($find)->read();

            if ($existing === []) {
                return ['success' => true, 'message' => null];
            }

            $remove = new Query('/ppp/profile/remove');
            $remove->equal('.id', $existing[0]['.id']);
            $client->query($remove)->read();

            return ['success' => true, 'message' => null];
        } catch (Throwable $e) {
            Log::warning("RouterOsApiGateway: gagal hapus /ppp profile (comment={$comment}) di NAS #{$nas->id} ({$nas->mikrotik_ip}:{$nas->api_port}): {$e->getMessage()}");

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function syncHotspotServerPool(Nas $nas, string $poolName): array
    {
        try {
            $client = new Client([
                'host' => $nas->mikrotik_ip,
                'user' => $nas->api_username,
                'pass' => $nas->api_password,
                'port' => $nas->api_port,
                'timeout' => 10,
            ]);

            $servers = $client->query(new Query('/ip/hotspot/print'))->read();

            if ($servers === []) {
                return [
                    'success' => false,
                    'message' => 'NAS ini belum punya Hotspot Server di Mikrotik. Buat Hotspot Server terlebih dahulu (System > Hotspot Setup) sebelum push Grup Profil tipe Hotspot.',
                ];
            }

            // Known, documented simplification: the FIRST hotspot server
            // found is used — there is no UI yet for picking a specific
            // one when a NAS has more than one, per the sprint's own
            // scope (see NetworkProfileGroup's own docblock).
            $set = new Query('/ip/hotspot/set');
            $set->equal('.id', $servers[0]['.id'])->equal('address-pool', $poolName);
            $client->query($set)->read();

            return ['success' => true, 'message' => null];
        } catch (Throwable $e) {
            Log::warning("RouterOsApiGateway: gagal sync address-pool hotspot server ke NAS #{$nas->id} ({$nas->mikrotik_ip}:{$nas->api_port}): {$e->getMessage()}");

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function syncHotspotUserProfile(
        Nas $nas,
        string $lookupName,
        string $targetName,
        ?string $rateLimit,
        int $sharedUsers,
        ?string $sessionTimeout,
        ?string $addressPool = null,
    ): array {
        try {
            $client = new Client([
                'host' => $nas->mikrotik_ip,
                'user' => $nas->api_username,
                'pass' => $nas->api_password,
                'port' => $nas->api_port,
                'timeout' => 10,
            ]);

            // Same precondition as syncHotspotServerPool() — a Hotspot
            // user profile with no Hotspot Server behind it can never
            // actually authenticate anyone, even though RouterOS itself
            // happily lets the object be created regardless (confirmed
            // empirically). Per Agung's explicit instruction, this refuses
            // rather than push a profile that can't do anything yet.
            $servers = $client->query(new Query('/ip/hotspot/print'))->read();

            if ($servers === []) {
                return [
                    'success' => false,
                    'message' => 'NAS ini belum punya Hotspot Server di Mikrotik. Buat Hotspot Server terlebih dahulu (System > Hotspot Setup) sebelum push Profil Hotspot.',
                ];
            }

            $find = new Query('/ip/hotspot/user/profile/print');
            $find->where('name', $lookupName);
            $existing = $client->query($find)->read();

            if ($existing === []) {
                $add = new Query('/ip/hotspot/user/profile/add');
                $add->equal('name', $targetName)->equal('shared-users', (string) $sharedUsers);

                if ($rateLimit !== null) {
                    $add->equal('rate-limit', $rateLimit);
                }

                if ($sessionTimeout !== null) {
                    $add->equal('session-timeout', $sessionTimeout);
                }

                if ($addressPool !== null) {
                    $add->equal('address-pool', $addressPool);
                }

                $response = $client->query($add)->read();
            } else {
                $set = new Query('/ip/hotspot/user/profile/set');
                $set->equal('.id', $existing[0]['.id'])
                    ->equal('name', $targetName)
                    ->equal('shared-users', (string) $sharedUsers);

                // Real bug found and fixed against a real router (TOKEN-1Hp
                // on ro-hotspot.bajastu.id, reported by Agung): the old
                // unconditional `?? 'none'`/`?? ''` fallbacks here made
                // EVERY set for a null session-timeout fail outright —
                // confirmed via a live test that RouterOS rejects BOTH
                // 'none' AND '' as an explicit session-timeout value
                // ("invalid time value for argument session-timeout"),
                // unlike idle-timeout, which genuinely does accept/display
                // 'none'. Conditional inclusion (never sending the
                // parameter at all when null), same as the add branch
                // above, sidesteps the whole "what string means 'clear
                // this'" question entirely — confirmed via a live test that
                // omitting a parameter on `set` leaves that field
                // untouched, not reset. Applied to rate-limit/address-pool
                // too for the same reason, even though neither is ever
                // actually null for a real HotspotPackage in practice
                // (both are always derived from required, non-nullable
                // relations) — consistency, and no reason to trust an
                // untested clearing value for them either.
                //
                // Known, accepted trade-off from this fix: switching an
                // EXISTING synced package's Batasan away from TimeBase (so
                // routerOsSessionTimeout() newly returns null) no longer
                // actively clears a previously-set session-timeout value on
                // the router — the field simply stays at whatever it was.
                // Not solved here — flagged, not silently worked around.
                if ($rateLimit !== null) {
                    $set->equal('rate-limit', $rateLimit);
                }

                if ($sessionTimeout !== null) {
                    $set->equal('session-timeout', $sessionTimeout);
                }

                if ($addressPool !== null) {
                    $set->equal('address-pool', $addressPool);
                }

                $response = $client->query($set)->read();
            }

            // `/ip hotspot user profile` rejects an unexpected parameter
            // (e.g. `comment`, confirmed empirically) by returning
            // ['after' => ['message' => '...']] WITHOUT throwing — a real
            // gap found while investigating this object type, worth
            // guarding against explicitly here rather than trusting a bare
            // try/catch the way the older syncIpPool()/syncPppProfile()
            // methods do (a genuine RouterOS API parameter rejection would
            // otherwise be silently reported as success by those).
            if (isset($response['after']['message'])) {
                throw new \RuntimeException((string) $response['after']['message']);
            }

            return ['success' => true, 'message' => null];
        } catch (Throwable $e) {
            Log::warning("RouterOsApiGateway: gagal sync /ip hotspot user profile (name={$lookupName}) ke NAS #{$nas->id} ({$nas->mikrotik_ip}:{$nas->api_port}): {$e->getMessage()}");

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function removeHotspotUserProfile(Nas $nas, string $lookupName): array
    {
        try {
            $client = new Client([
                'host' => $nas->mikrotik_ip,
                'user' => $nas->api_username,
                'pass' => $nas->api_password,
                'port' => $nas->api_port,
                'timeout' => 10,
            ]);

            $find = new Query('/ip/hotspot/user/profile/print');
            $find->where('name', $lookupName);
            $existing = $client->query($find)->read();

            if ($existing === []) {
                return ['success' => true, 'message' => null];
            }

            $remove = new Query('/ip/hotspot/user/profile/remove');
            $remove->equal('.id', $existing[0]['.id']);
            $client->query($remove)->read();

            return ['success' => true, 'message' => null];
        } catch (Throwable $e) {
            Log::warning("RouterOsApiGateway: gagal hapus /ip hotspot user profile (name={$lookupName}) di NAS #{$nas->id} ({$nas->mikrotik_ip}:{$nas->api_port}): {$e->getMessage()}");

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
