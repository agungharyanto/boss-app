<?php

namespace App\Services\Network;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Wraps a full Mikrotik script (VPN or RADIUS) behind a short-lived,
 * single-use download token instead of asking the admin to paste the whole
 * thing into the router's interactive terminal.
 *
 * Found via real hardware testing (v0.6.3 gap-fix round 3): pasting a large
 * script (especially OpenVPN's, which embeds a PEM private key) directly
 * into RouterOS's interactive CLI can trigger an unrelated confirmation
 * prompt ("Save changes? [y/N]") mid-paste — the terminal's line-by-line
 * paste handling appears to occasionally misinterpret bytes inside a long
 * pasted blob, leaving the rest of the script "interrupted"/never executed.
 * `/tool fetch` + `/import` instead reads the script as a whole file and
 * executes it as a non-interactive script, which never touches that same
 * interactive-paste code path.
 *
 * The script content itself contains private keys/PSKs/passwords, so the
 * token is NOT a permanent public URL: it lives only in cache (Redis in
 * this app, see config('cache.default')), is deleted the instant it's
 * fetched once (Cache::pull is an atomic get-and-delete — no window where
 * a second request could still read it), and expires on its own after
 * TTL_MINUTES even if never fetched at all.
 */
class ScriptDownloadTokenService
{
    private const CACHE_PREFIX = 'vpn-script-download:';

    private const TTL_MINUTES = 10;

    public function store(string $scriptContent): string
    {
        $token = Str::random(48);

        Cache::put(self::CACHE_PREFIX.$token, $scriptContent, now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /**
     * Atomic get-and-delete — a second fetch of the same token (accidental
     * double-click, retry, or an attacker who somehow observed the token in
     * transit) gets nothing, not a replay of the same secret-bearing script.
     */
    public function retrieveAndInvalidate(string $token): ?string
    {
        return Cache::pull(self::CACHE_PREFIX.$token);
    }

    /**
     * $schemeAndHost must come from the actual inbound request (e.g.
     * request()->getSchemeAndHttpHost()), not a hardcoded/config value —
     * this server currently has no TLS (APP_URL is plain http://), and
     * hardcoding https:// here would make the router's /tool fetch fail
     * with no obvious reason why. Switches to https:// automatically the
     * moment this app is actually served over TLS, with no code change.
     */
    public function buildFetchCommand(string $token, string $schemeAndHost): string
    {
        $url = $this->buildDownloadUrl($token, $schemeAndHost);

        return '/tool fetch url="'.$url.'" mode='.$this->fetchMode($schemeAndHost).' dst-path="boss-vpn-setup.rsc";'
            .'/import file-name="boss-vpn-setup.rsc";'
            .'/file remove [find name="boss-vpn-setup.rsc"];';
    }

    /**
     * Bare download URL for a token, no /tool fetch wrapping — used when a
     * script needs to embed additional fetches of its OWN (e.g. OpenVPN's
     * certificate/key files, see MikrotikScriptGenerator::openVpnScript())
     * rather than the single top-level script-download flow.
     */
    public function buildDownloadUrl(string $token, string $schemeAndHost): string
    {
        return rtrim($schemeAndHost, '/')."/vpn-script-generator/download/{$token}.rsc";
    }

    public function fetchMode(string $schemeAndHost): string
    {
        return str_starts_with($schemeAndHost, 'https://') ? 'https' : 'http';
    }

    public function ttlMinutes(): int
    {
        return self::TTL_MINUTES;
    }
}
