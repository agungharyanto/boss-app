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
        'l2tp_secrets_dir' => env('VPN_L2TP_SECRETS_DIR', '/vpn-l2tp-data'),

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
    ],

];
