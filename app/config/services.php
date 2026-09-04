<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // v0.3.5 Payment Gateway — sandbox only this sprint (BOSS-005: secrets
    // live in .env, never hardcoded/committed). is_production is a safety
    // guard read at request-time by XenditGatewayService (see its
    // guardEnvironmentMatchesConfiguredMode()) — never reuse a sandbox key
    // expecting it to work in production mode or vice versa.
    //
    // Fase H (v0.3.5): secret_key/callback_token below are ONLY read once
    // more, by `php artisan payment-gateway:import-env` — the real runtime
    // source for both is now payment_gateway_settings (encrypted DB row),
    // see PaymentGatewaySettingsService. Do not add new config()/env() reads
    // of these two keys elsewhere.
    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
        'is_production' => env('XENDIT_IS_PRODUCTION', false),
    ],

    // v0.4.0 WhatsApp Gateway — internal HTTP API to the whatsapp-gateway
    // Node.js container (never exposed publicly, BOSS-010). hmac_secret is
    // an infra-level shared secret (APP_KEY-class, not a business
    // credential) — must match whatsapp-gateway's own copy exactly, see
    // App\Support\WhatsappHmac.
    'whatsapp_gateway' => [
        'url' => env('WHATSAPP_GATEWAY_URL'),
        'hmac_secret' => env('WHATSAPP_GATEWAY_HMAC_SECRET'),
    ],

    // v0.6.2 OpenVPN provisioning — these paths are the boss-app side of
    // the vpn_pki/vpn_ccd named volumes shared with the openvpn container
    // (docker-compose.yml), NOT the openvpn container's own /etc/openvpn/*
    // paths. VpnProvisioningService runs `easyrsa` directly against
    // pki_dir; defaults already match the compose mount paths, so no new
    // .env keys are required unless a deployment needs to override them.
    'vpn' => [
        // /vpn-pki-data/pki, not /vpn-pki-data itself — easyrsa init-pki
        // rm -rf's --pki-dir on first run, which fails if --pki-dir IS the
        // volume's own mountpoint (see docker-compose.yml's vpn_pki mount).
        'pki_dir' => env('VPN_PKI_DIR', '/vpn-pki-data/pki'),
        'ccd_dir' => env('VPN_CCD_DIR', '/vpn-ccd'),
        // Same value as docker/openvpn/entrypoint.sh's VPN_SUBNET_NETMASK —
        // used to write the matching `ifconfig-push <ip> <netmask>` line in
        // each client-config-dir file (topology subnet requires the
        // netmask on every ifconfig-push, not just the IP).
        'netmask' => env('VPN_SUBNET_NETMASK', '255.255.255.0'),
        // v0.6.3 — boss-app side of the vpn_wg_data/vpn_l2tp_secrets named
        // volumes (docker-compose.yml), mirroring pki_dir/ccd_dir above.
        // wg_peers_dir is a subdirectory of the wireguard container's own
        // /etc/wireguard mount (its server keys live at the mount root,
        // deliberately readable by boss-app too — same posture already
        // accepted for OpenVPN's server.key inside the shared pki dir).
        'wg_peers_dir' => env('VPN_WG_PEERS_DIR', '/vpn-wg-data/peers'),
        // v0.8.1 — sibling directory to wg_peers_dir, same shared
        // vpn_wg_data volume. VpnProvisioningService writes one fragment
        // per NAS here (the NAS's own /30 gateway address); docker/
        // wireguard/entrypoint.sh's reconcile loop applies each one to
        // wg0 on all 3 nodes — see VpnWireguardNasBlock's own docblock.
        'wg_addresses_dir' => env('VPN_WG_ADDRESSES_DIR', '/vpn-wg-data/addresses'),
        'l2tp_secrets_dir' => env('VPN_L2TP_SECRETS_DIR', '/vpn-l2tp-data'),

        // v0.8.1 fragment+reconcile (replaces the OSPF experiment — see
        // CLAUDE.md's "OSPF Dynamic Routing" section for why, and
        // "Fragment+Reconcile Routing" for this mechanism's own design).
        // Sibling directory to wg_peers_dir/wg_addresses_dir, same shared
        // vpn_wg_data volume — App\Console\Commands\VpnSyncRouteFragments
        // writes one file per active WireGuard NAS here
        // (routes/nas-{id}.conf, "<subnet> via <node_ip>" per line); each
        // of the 5 consumer containers' own reconcile loop reads every
        // file here and `ip route replace`s each line, same polling-loop
        // idiom already used for peers/addresses.
        'routes_dir' => env('VPN_ROUTES_DIR', '/vpn-wg-data/routes'),

        // Port -> internal boss-network IP for the 3 WireGuard pool nodes,
        // keyed by the SAME listen port RouterOS reports as a NAS's own
        // `current-endpoint-port` (also vpn_servers.port DB-side — this is
        // a plain env-driven map rather than a DB join purely because
        // VpnSyncRouteFragments needs it independent of any one
        // VpnServer row's current pool-ownership state). Used to turn
        // "this NAS is currently on port 51821" into "route via
        // 172.28.0.4" — see RouterOsGateway::currentWireguardEndpointPort().
        'wireguard_node_ips' => [
            51820 => env('WIREGUARD_NODE1_INTERNAL_IP'),
            51821 => env('WIREGUARD_NODE2_INTERNAL_IP'),
            51822 => env('WIREGUARD_NODE3_INTERNAL_IP'),
        ],

        // v0.6.3 Script Generator (VpnScriptService) — values embedded into
        // generated Mikrotik scripts. public_ip/freeradius_internal_ip are
        // the same server-wide values every VPN container's entrypoint
        // already reads from .env; openvpn_port/wireguard_port match each
        // container's own hardcoded/env port so the generated script always
        // targets the port that container is actually listening on.
        'public_ip' => env('VPN_PUBLIC_IP'),
        'freeradius_internal_ip' => env('FREERADIUS_INTERNAL_IP'),
        'openvpn_port' => (int) env('VPN_OPENVPN_PORT', 1194),
        'wireguard_port' => (int) env('WG_LISTEN_PORT', 51820),
        'l2tp_ipsec_psk' => env('L2TP_IPSEC_PSK'),

        // v0.8.1 — the reserved /27 (INFRA_TUNNEL_BLOCK_CIDR) fed into
        // MikrotikScriptGenerator::wireGuardScript()'s allowed-address +
        // single infra-block route, replacing the old one-/32-per-service
        // model. See VpnScriptService::wireGuardScriptOrThrow() and
        // CLAUDE.md's "Infra Tunnel IP Block" section.
        'infra_block_cidr' => env('INFRA_TUNNEL_BLOCK_CIDR'),

        // v0.8.1 — read by VpnProvisioningService to widen a WireGuard
        // account's own AllowedIPs (server-side cryptokey-routing filter,
        // NOT the same thing as docker/wireguard/entrypoint.sh's `ip
        // route`/iptables additions — both are independently required).
        // Same single-global-subnet limitation as everywhere else
        // OLT_MANAGEMENT_SUBNET is read (docker/wireguard/entrypoint.sh,
        // docker/librenms/route-init.sh).
        'olt_management_subnet' => env('OLT_MANAGEMENT_SUBNET'),
    ],

    // v0.6.5 dynamic virtual server + CoA — boss-app side of the
    // freeradius_nas_config named volume shared with the freeradius
    // container (docker-compose.yml), mirroring vpn.pki_dir/wg_peers_dir
    // above. FreeradiusVirtualServerService writes into listen/ + clients/
    // here; CoaService writes into coa-queue/ (see its own docblock).
    'freeradius' => [
        'nas_config_dir' => env('FREERADIUS_NAS_CONFIG_DIR', '/freeradius-nas-config'),
    ],

    // v0.7.1 GenieACS — genieacs-nbi has no auth of its own, network
    // isolation is the security boundary (see docker-compose.yml's
    // genieacs-nbi service comment), so this is just the internal
    // container-to-container URL, never exposed to the host.
    //
    // cwmp_internal_ip/nbi_internal_ip (v0.7.3) are the pinned boss-network
    // IPs from GENIEACS_CWMP_INTERNAL_IP/GENIEACS_NBI_INTERNAL_IP (see
    // .env.example) — both addresses now live inside the shared
    // INFRA_TUNNEL_BLOCK_CIDR (services.vpn.infra_block_cidr) instead of
    // getting their own individual allowed-address/route entries (v0.8.1,
    // see MikrotikScriptGenerator::wireGuardScript()'s docblock). These two
    // config keys are no longer read by VpnScriptService as of that change
    // — kept here only because docker-compose.yml's ipv4_address and
    // docker/wireguard/entrypoint.sh's TR069_MANAGEMENT_SUBNET firewall
    // exception still need the underlying env vars directly (shell, not
    // Laravel config).
    'genieacs' => [
        'nbi_url' => env('GENIEACS_NBI_URL', 'http://genieacs-nbi:7557'),
        'cwmp_internal_ip' => env('GENIEACS_CWMP_INTERNAL_IP'),
        'nbi_internal_ip' => env('GENIEACS_NBI_INTERNAL_IP'),
    ],

    // Ambang batas status online/offline CPE (CpeDeviceStatusSyncService).
    // `online_threshold_minutes` — device yang Inform lebih baru dari ini
    // langsung dianggap Online tanpa probe. Default 180 (3 jam) supaya ONT
    // dengan PeriodicInformInterval panjang (banyak vendor 1-12 jam) tidak
    // salah dicap Offline setiap siklus sync 15 menit.
    // `offline_hard_cutoff_minutes` — cuma setelah TIDAK ada Inform selama
    // ini (DAN probe connection_request gagal) device benar-benar di-set
    // Offline. Di antara dua ambang: probe gagal TIDAK mengubah status
    // (jangan bohong "offline" kalau belum yakin).
    'cpe' => [
        'online_threshold_minutes' => (int) env('CPE_ONLINE_THRESHOLD_MINUTES', 180),
        'offline_hard_cutoff_minutes' => (int) env('CPE_OFFLINE_HARD_CUTOFF_MINUTES', 1440),
    ],

    // v0.8.2 — LibreNMS REST API (v0.8.1 already brought the container up
    // with token auth, see .env's LIBRENMS_API_URL/LIBRENMS_API_TOKEN).
    // Container-to-container URL only, no host port published — same
    // "network isolation is the security boundary, token is defense-in-
    // depth on top" posture as every other internal service in this repo.
    //
    // rrd_data_dir is the boss-app side of the librenms_data named volume
    // (docker-compose.yml, mounted read-only) — LibreNmsService::
    // getTrafficHistory() shells out to `rrdtool xport --json` directly
    // against files under here, since LibreNMS's own REST API has no raw
    // time-series JSON endpoint in this installed version (confirmed by
    // reading its routes/api.php directly — see CLAUDE.md's "Dashboard
    // Monitoring (v0.8.2)"). cache_ttl is deliberately short (seconds, not
    // minutes) so a widget appearing on both the Monitoring page and a
    // future Dashboard placement doesn't multiply real hits to LibreNMS.
    'librenms' => [
        'api_url' => env('LIBRENMS_API_URL', 'http://librenms:8000/api/v0'),
        'api_token' => env('LIBRENMS_API_TOKEN'),
        'rrd_data_dir' => env('LIBRENMS_RRD_DATA_DIR', '/librenms-data/rrd'),
        'cache_ttl' => (int) env('LIBRENMS_CACHE_TTL', 45),
    ],

    // v0.8.4 Bagian C — App\Services\Infra\ContainerStatsService's only
    // dependency. Points at docker-stats-proxy (docker-compose.yml), never
    // a direct docker.sock mount on this container — see CLAUDE.md
    // "Container Stats via docker-socket-proxy (v0.8.4 Bagian C)".
    'docker_stats' => [
        'proxy_url' => env('DOCKER_STATS_PROXY_URL', 'http://docker-stats-proxy:2375'),
    ],

    // v0.16.0 Langkah 11 — self-hosted OSRM (docker-compose.yml `osrm`
    // service). App\Services\Network\RoutingService's only dependency;
    // container-to-container URL, no host port. When unreachable/slow,
    // RoutingService falls back to a straight-line estimate — never a
    // silent failure. timeout is short on purpose: this is a synchronous
    // call inside a Livewire request, and a routed answer for two points
    // ~a few km apart returns in well under a second when OSRM is up.
    'osrm' => [
        'url' => env('OSRM_URL', 'http://osrm:5000'),
        'timeout' => (int) env('OSRM_TIMEOUT', 5),
    ],

];
