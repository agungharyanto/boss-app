<?php

namespace App\Support;

/**
 * HMAC-SHA256 signing/verification for the internal Laravel <-> Python
 * olt-sidecar HTTP API (v0.23.3) — struktur PERSIS App\Support\WhatsappHmac,
 * hanya secret-nya berbeda (OLT_SIDECAR_HMAC_SECRET, bukan
 * WHATSAPP_GATEWAY_HMAC_SECRET). Signs {timestamp}.{body} sehingga request
 * yang ter-capture tidak bisa di-replay verbatim — verify() menolak apa pun
 * di luar jendela toleransi 5 menit bahkan dengan signature yang benar
 * byte-for-byte.
 *
 * Secret-nya sendiri (OLT_SIDECAR_HMAC_SECRET) adalah infra-level shared
 * secret, kelas APP_KEY, bukan kredensial bisnis — harus sama persis di
 * root .env (dibaca boss-app) dan environment container olt-sidecar
 * sendiri (docker-compose.yml).
 */
class OltSidecarHmac
{
    private const TOLERANCE_SECONDS = 300;

    private readonly string $secret;

    public function __construct(?string $secret = null)
    {
        $this->secret = $secret ?? (string) config('services.olt_sidecar.hmac_secret');
    }

    public function sign(string $body, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);
    }

    /**
     * @param  string  $body  Raw request body exactly as sent/received — signing over a
     *                        re-encoded version would silently break on any whitespace/key-order difference.
     */
    public function verify(string $body, string $signature, int $timestamp): bool
    {
        if (abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals($this->sign($body, $timestamp), $signature);
    }
}
