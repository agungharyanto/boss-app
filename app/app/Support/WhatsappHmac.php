<?php

namespace App\Support;

/**
 * HMAC-SHA256 signing/verification for the internal Laravel <-> Node
 * whatsapp-gateway HTTP API (v0.4.0). Unlike the Xendit webhook's static
 * shared-token compare, this signs {timestamp}.{body} so a captured request
 * can't be replayed verbatim — verify() rejects anything outside a 5 minute
 * tolerance window even with a byte-for-byte correct signature.
 *
 * The secret itself (WHATSAPP_GATEWAY_HMAC_SECRET) is an infra-level shared
 * secret, APP_KEY-class, not a business credential — same value must exist
 * in both this app's .env and whatsapp-gateway/.env.
 */
class WhatsappHmac
{
    private const TOLERANCE_SECONDS = 300;

    private readonly string $secret;

    public function __construct(?string $secret = null)
    {
        $this->secret = $secret ?? (string) config('services.whatsapp_gateway.hmac_secret');
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
