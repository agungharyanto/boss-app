<?php

namespace App\Services\Network;

use App\Models\Nas;
use App\Services\Network\Contracts\NasWireguardInspector;
use Illuminate\Support\Facades\Log;
use RouterOS\Client;
use RouterOS\Query;
use Throwable;

/**
 * v0.16 — real implementation of NasWireguardInspector. Same per-call,
 * per-NAS-credential Client build as RouterOsApiGateway.
 */
class RouterOsNasWireguardInspector implements NasWireguardInspector
{
    private const IFACE = 'boss-vpn-wireguard';

    public function peerStatus(Nas $nas): ?array
    {
        try {
            $client = $this->client($nas, 12);

            $ifaces = $client->query((new Query('/interface/wireguard/print'))->where('name', self::IFACE))->read();
            $peers = $client->query((new Query('/interface/wireguard/peers/print'))->where('interface', self::IFACE))->read();

            $peer = $peers[0] ?? null;

            return [
                'interface_running' => ($ifaces[0]['running'] ?? 'false') === 'true',
                'peer_found' => $peer !== null,
                'last_handshake' => $peer['last-handshake'] ?? null,
                'rx' => (int) ($peer['rx'] ?? 0),
                'tx' => (int) ($peer['tx'] ?? 0),
                'endpoint' => isset($peer['current-endpoint-address'])
                    ? $peer['current-endpoint-address'].':'.($peer['current-endpoint-port'] ?? '?')
                    : null,
            ];
        } catch (Throwable $e) {
            Log::warning("NasWireguardInspector::peerStatus NAS #{$nas->id}: {$e->getMessage()}");

            return null;
        }
    }

    public function pingFromRouter(Nas $nas, string $targetIp, int $count = 3): ?bool
    {
        try {
            $client = $this->client($nas, $count + 6);

            $q = (new Query('/ping'))
                ->equal('address', $targetIp)
                ->equal('count', (string) $count)
                ->equal('interface', self::IFACE);

            $replies = $client->query($q)->read();
            $last = end($replies);

            return (int) ($last['received'] ?? 0) > 0;
        } catch (Throwable $e) {
            Log::warning("NasWireguardInspector::pingFromRouter NAS #{$nas->id} -> {$targetIp}: {$e->getMessage()}");

            return null;
        }
    }

    public function retriggerPeerHandshake(Nas $nas): bool
    {
        try {
            $client = $this->client($nas, 12);

            $peers = $client->query((new Query('/interface/wireguard/peers/print'))->where('interface', self::IFACE))->read();
            $id = $peers[0]['.id'] ?? null;

            if ($id === null) {
                return false;
            }

            $client->query((new Query('/interface/wireguard/peers/disable'))->equal('.id', $id))->read();
            usleep(400_000);
            $client->query((new Query('/interface/wireguard/peers/enable'))->equal('.id', $id))->read();

            return true;
        } catch (Throwable $e) {
            Log::warning("NasWireguardInspector::retriggerPeerHandshake NAS #{$nas->id}: {$e->getMessage()}");

            return false;
        }
    }

    private function client(Nas $nas, int $timeout): Client
    {
        return new Client([
            'host' => $nas->mikrotik_ip,
            'user' => $nas->api_username,
            'pass' => $nas->api_password,
            'port' => (int) $nas->api_port,
            'timeout' => $timeout,
            'attempts' => 1,
        ]);
    }
}
