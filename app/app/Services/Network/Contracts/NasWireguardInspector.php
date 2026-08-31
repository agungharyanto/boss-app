<?php

namespace App\Services\Network\Contracts;

use App\Models\Nas;

/**
 * v0.16 — router-side reads for the "Cek Koneksi RADIUS" diagnostic
 * (App\Services\Network\NasRadiusDiagnosticService). Deliberately its own
 * small contract rather than three more methods on the already-large
 * RouterOsGateway interface (14 anonymous-class test fakes implement that
 * one — adding to it means touching all 14). All three methods use $nas's
 * OWN stored API credentials and are read-only EXCEPT
 * retriggerPeerHandshake(), which is a net-zero disable+enable of this one
 * NAS's own WireGuard peer (safe, reversible, affects nothing but this
 * NAS's tunnel).
 */
interface NasWireguardInspector
{
    /**
     * `/interface/wireguard` + `/interface/wireguard/peers` for $nas's
     * boss-vpn-wireguard interface, from the router's own point of view.
     * Returns null if the router API is unreachable (bad creds, port,
     * firewall, box down) — the caller renders that as a distinct
     * "step skipped" state, never a hard failure.
     *
     * @return array{
     *     interface_running: bool,
     *     peer_found: bool,
     *     last_handshake: ?string,
     *     rx: int,
     *     tx: int,
     *     endpoint: ?string,
     * }|null
     */
    public function peerStatus(Nas $nas): ?array;

    /**
     * `/ping address=$targetIp count=$count interface=boss-vpn-wireguard`
     * issued FROM $nas's router — the real "can this NAS reach FreeRADIUS
     * through the tunnel to send RADIUS packets" test. Returns null when
     * the API is unreachable, false when every echo was lost.
     */
    public function pingFromRouter(Nas $nas, string $targetIp, int $count = 3): ?bool;

    /**
     * Safe self-solve: disable then re-enable $nas's own
     * boss-vpn-wireguard peer, forcing the router to start a fresh
     * WireGuard handshake. Net-zero (ends in the same enabled state),
     * scoped to this one NAS, touches no container. Returns false if the
     * API is unreachable or no matching peer exists.
     */
    public function retriggerPeerHandshake(Nas $nas): bool;
}
