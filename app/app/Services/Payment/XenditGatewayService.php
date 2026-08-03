<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin HTTP wrapper around the Xendit API — deliberately knows nothing about
 * BOSS App's own Invoice/Payment models, only ever takes a plain amount +
 * external_id + channel and returns Xendit's raw response array. Sandbox vs
 * production is determined by WHICH secret key is configured (Xendit uses
 * the same https://api.xendit.co endpoint for both — there's no separate
 * sandbox host), not by config('services.xendit.is_production') — that flag
 * is a safety guard against running with a mismatched key for the current
 * Laravel environment, not a URL switch.
 */
class XenditGatewayService
{
    private const BASE_URL = 'https://api.xendit.co';

    public function __construct(
        private readonly PaymentGatewaySettingsService $settings,
    ) {
        $this->guardEnvironmentMatchesConfiguredMode();
    }

    /**
     * @return array<string, mixed> Xendit's raw create-VA response
     */
    public function createVirtualAccount(string $externalId, float $amount, string $bankCode = 'BCA'): array
    {
        return $this->post('/callback_virtual_accounts', [
            'external_id' => $externalId,
            'bank_code' => $bankCode,
            'name' => 'BOSS App Payment',
            'expected_amount' => $amount,
            'is_closed' => true,
        ]);
    }

    /**
     * @return array<string, mixed> Xendit's raw create-QRIS-code response
     */
    public function createQris(string $externalId, float $amount): array
    {
        return $this->post('/qr_codes', [
            'external_id' => $externalId,
            'type' => 'DYNAMIC',
            'callback_url' => config('app.url').'/api/v1/webhooks/xendit',
            'amount' => $amount,
        ]);
    }

    /**
     * @return array<string, mixed> Xendit's raw create-Invoice response
     */
    public function createInvoice(string $externalId, float $amount): array
    {
        return $this->post('/v2/invoices', [
            'external_id' => $externalId,
            'amount' => $amount,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        // v0.3.5 Fase H: secret key now lives in payment_gateway_settings
        // (encrypted), NOT config('services.xendit.secret_key')/.env — see
        // PaymentGatewaySettingsService's docblock. .env is only read once,
        // by `payment-gateway:import-env`, to seed this table.
        $secretKey = $this->settings->getSecretKey();

        if (blank($secretKey)) {
            throw new RuntimeException(
                'Xendit API Secret belum dikonfigurasi — isi di halaman Pengaturan > Payment Gateway sebelum membuat payment.'
            );
        }

        // Xendit authenticates via HTTP Basic Auth: secret key as the
        // username, empty password.
        $response = Http::withBasicAuth($secretKey, '')
            ->acceptJson()
            ->post(self::BASE_URL.$path, $payload);

        if ($response->failed()) {
            Log::warning('Xendit API call failed', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        return $response->json() ?? [];
    }

    /**
     * Refuses to operate if Laravel's own environment doesn't match the
     * configured Xendit mode — e.g. `XENDIT_IS_PRODUCTION=true` left set on
     * a `local`/`testing` box would silently risk hitting Xendit with
     * production-shaped assumptions while still being "just a test" as far
     * as the rest of the app is concerned. v0.3.5 is sandbox-only; this
     * guard is what prevents that exact mismatch (see CLAUDE.md).
     */
    private function guardEnvironmentMatchesConfiguredMode(): void
    {
        $isProduction = (bool) config('services.xendit.is_production');

        if ($isProduction && ! app()->environment('production')) {
            throw new RuntimeException(
                'XENDIT_IS_PRODUCTION=true tapi APP_ENV bukan production — refuse to call Xendit API. '.
                'v0.3.5 sengaja sandbox-only; perbaiki .env sebelum lanjut.'
            );
        }
    }
}
