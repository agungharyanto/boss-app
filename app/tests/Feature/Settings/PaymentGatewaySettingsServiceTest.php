<?php

namespace Tests\Feature\Settings;

use App\Models\PaymentGatewayChannel;
use App\Models\PaymentGatewaySettings;
use App\Models\User;
use App\Services\Payment\PaymentGatewaySettingsService;
use Database\Seeders\PaymentGatewayChannelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentGatewaySettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_and_token_roundtrip_encrypted_and_are_readable_via_service(): void
    {
        $this->seed(PaymentGatewayChannelSeeder::class);
        $actor = User::factory()->create();
        $service = app(PaymentGatewaySettingsService::class);

        $service->update([
            'xendit_secret_key' => 'xnd_secret_sandbox_abc',
            'xendit_webhook_token' => 'webhook-token-xyz',
            'channels' => ['QRIS'],
        ], $actor);

        $this->assertSame('xnd_secret_sandbox_abc', $service->getSecretKey());
        $this->assertSame('webhook-token-xyz', $service->getWebhookToken());
        $this->assertTrue($service->isConfigured());

        // The DB column itself must not contain the plaintext value — this
        // is what makes the 'encrypted' cast actually load-bearing, not
        // just decorative.
        $raw = DB::table('payment_gateway_settings')->first();
        $this->assertStringNotContainsString('xnd_secret_sandbox_abc', $raw->xendit_secret_key);
    }

    public function test_blank_update_does_not_overwrite_previously_saved_secret(): void
    {
        $this->seed(PaymentGatewayChannelSeeder::class);
        $actor = User::factory()->create();
        $service = app(PaymentGatewaySettingsService::class);

        $service->update([
            'xendit_secret_key' => 'first-secret',
            'xendit_webhook_token' => 'first-token',
            'channels' => ['QRIS'],
        ], $actor);

        $service->update([
            'xendit_secret_key' => null,
            'xendit_webhook_token' => null,
            'channels' => ['QRIS'],
        ], $actor);

        $this->assertSame('first-secret', $service->getSecretKey());
        $this->assertSame('first-token', $service->getWebhookToken());
    }

    public function test_cache_is_invalidated_after_update(): void
    {
        $this->seed(PaymentGatewayChannelSeeder::class);
        $actor = User::factory()->create();
        $service = app(PaymentGatewaySettingsService::class);

        $service->update(['xendit_secret_key' => 'v1', 'xendit_webhook_token' => 'v1', 'channels' => ['QRIS']], $actor);
        $this->assertSame('v1', $service->getSecretKey());

        $service->update(['xendit_secret_key' => 'v2', 'xendit_webhook_token' => 'v2', 'channels' => ['QRIS']], $actor);

        // If the cache weren't invalidated, this would still return 'v1'
        // (the value cached by the first getSecretKey() call above).
        $this->assertSame('v2', $service->getSecretKey());
    }

    public function test_settings_table_never_has_more_than_one_row_even_after_repeated_updates(): void
    {
        $this->seed(PaymentGatewayChannelSeeder::class);
        $actor = User::factory()->create();
        $service = app(PaymentGatewaySettingsService::class);

        $service->update(['xendit_secret_key' => 'a', 'channels' => ['QRIS']], $actor);
        $service->update(['xendit_webhook_token' => 'b', 'channels' => ['QRIS']], $actor);
        $service->update(['xendit_secret_key' => 'c', 'channels' => ['BRI_VA']], $actor);

        $this->assertSame(1, PaymentGatewaySettings::query()->count());
    }

    public function test_enabled_channels_are_synced_and_grouped_by_category(): void
    {
        $this->seed(PaymentGatewayChannelSeeder::class);
        $actor = User::factory()->create();
        $service = app(PaymentGatewaySettingsService::class);

        $service->update(['channels' => ['QRIS', 'BRI_VA']], $actor);

        $this->assertTrue($service->isChannelEnabled('QRIS'));
        $this->assertTrue($service->isChannelEnabled('BRI_VA'));
        $this->assertFalse($service->isChannelEnabled('OVO'));

        $grouped = $service->enabledChannels();
        $this->assertCount(2, $grouped->flatten());

        $this->assertSame(2, PaymentGatewayChannel::query()->where('enabled', true)->count());
    }
}
