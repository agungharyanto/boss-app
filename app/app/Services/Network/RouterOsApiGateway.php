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
}
