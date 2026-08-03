<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\PaymentGatewaySettings;
use App\Models\User;
use App\Services\Payment\PaymentGatewaySettingsService;
use Database\Seeders\PaymentGatewayChannelSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentGatewaySettingsLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PaymentGatewayChannelSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_non_admin_cannot_mount_the_settings_page(): void
    {
        $user = User::factory()->create();
        $user->assignRole('billing');

        Livewire::actingAs($user)->test(PaymentGatewaySettings::class)->assertForbidden();
    }

    public function test_saved_secret_is_never_rendered_back_only_a_masked_placeholder(): void
    {
        $admin = $this->admin();
        app(PaymentGatewaySettingsService::class)->update([
            'xendit_secret_key' => 'super-secret-value',
            'xendit_webhook_token' => 'super-token-value',
            'channels' => ['QRIS'],
        ], $admin);

        Livewire::actingAs($admin)
            ->test(PaymentGatewaySettings::class)
            ->assertSet('xenditSecretKey', '')
            ->assertSet('xenditWebhookToken', '')
            ->assertSet('isConfigured', true)
            ->assertDontSeeHtml('super-secret-value')
            ->assertDontSeeHtml('super-token-value');
    }

    public function test_submitting_blank_credential_fields_does_not_erase_saved_values(): void
    {
        $admin = $this->admin();
        $service = app(PaymentGatewaySettingsService::class);
        $service->update([
            'xendit_secret_key' => 'keep-me-secret',
            'xendit_webhook_token' => 'keep-me-token',
            'channels' => ['QRIS'],
        ], $admin);

        Livewire::actingAs($admin)
            ->test(PaymentGatewaySettings::class)
            ->set('xenditSecretKey', '')
            ->set('xenditWebhookToken', '')
            ->set('enabledChannelCodes', ['QRIS'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('keep-me-secret', $service->getSecretKey());
        $this->assertSame('keep-me-token', $service->getWebhookToken());
    }

    public function test_at_least_one_channel_must_be_enabled_to_save(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(PaymentGatewaySettings::class)
            ->set('enabledChannelCodes', [])
            ->call('save')
            ->assertHasErrors(['enabledChannelCodes']);
    }

    public function test_saving_persists_new_credentials_and_channel_selection(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(PaymentGatewaySettings::class)
            ->set('xenditSecretKey', 'brand-new-secret')
            ->set('xenditWebhookToken', 'brand-new-token')
            ->set('enabledChannelCodes', ['QRIS', 'BRI_VA'])
            ->call('save')
            ->assertHasNoErrors();

        $service = app(PaymentGatewaySettingsService::class);
        $this->assertSame('brand-new-secret', $service->getSecretKey());
        $this->assertTrue($service->isChannelEnabled('QRIS'));
        $this->assertTrue($service->isChannelEnabled('BRI_VA'));
        $this->assertFalse($service->isChannelEnabled('OVO'));
    }
}
