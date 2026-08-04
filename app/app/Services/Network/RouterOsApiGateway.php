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
}
