<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\PaymentSettings;
use App\Models\CompanyBankAccount;
use App\Models\CompanyProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Company\CompanyBankAccountService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.13.5.4 — kartu Rekening + Metode di Pengaturan Perusahaan.
 */
class PaymentSettingsLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo('company_profile.view');
        $user->givePermissionTo('company_profile.manage');

        return $user;
    }

    public function test_add_bank_creates_default_account(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->admin($tenant);

        Livewire::actingAs($user)->test(PaymentSettings::class)
            ->set('newBankName', 'BCA')
            ->set('newAccountNumber', '1234567890')
            ->set('newAccountHolder', 'PT Uji')
            ->call('addBank');

        $acc = CompanyBankAccount::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($acc);
        $this->assertTrue($acc->is_default);
        $this->assertSame('BCA', $acc->bank_name);
    }

    public function test_delete_default_blocked_flashes_error(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->admin($tenant);
        $svc = app(CompanyBankAccountService::class);
        $a = $svc->create($tenant->id, ['bank_name' => 'BCA', 'account_number' => '111', 'account_holder' => 'PT Uji']);
        $svc->create($tenant->id, ['bank_name' => 'Mandiri', 'account_number' => '222', 'account_holder' => 'PT Uji']);

        Livewire::actingAs($user)->test(PaymentSettings::class)->call('deleteBank', $a->id);

        $this->assertDatabaseHas('company_bank_accounts', ['id' => $a->id]);
    }

    public function test_make_default_switches(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->admin($tenant);
        $svc = app(CompanyBankAccountService::class);
        $svc->create($tenant->id, ['bank_name' => 'BCA', 'account_number' => '111', 'account_holder' => 'PT Uji']);
        $b = $svc->create($tenant->id, ['bank_name' => 'Mandiri', 'account_number' => '222', 'account_holder' => 'PT Uji']);

        Livewire::actingAs($user)->test(PaymentSettings::class)->call('makeDefault', $b->id);

        $this->assertTrue($b->fresh()->is_default);
    }

    public function test_toggle_active(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->admin($tenant);
        $svc = app(CompanyBankAccountService::class);
        $svc->create($tenant->id, ['bank_name' => 'BCA', 'account_number' => '111', 'account_holder' => 'PT Uji']);
        $b = $svc->create($tenant->id, ['bank_name' => 'Mandiri', 'account_number' => '222', 'account_holder' => 'PT Uji']);

        Livewire::actingAs($user)->test(PaymentSettings::class)->call('toggleActive', $b->id);
        $this->assertFalse($b->fresh()->is_active);
    }

    public function test_save_switches_updates_profile(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->admin($tenant);

        Livewire::actingAs($user)->test(PaymentSettings::class)
            ->set('manualEnabled', true)
            ->set('gatewayEnabled', true)
            ->set('agentEnabled', false)
            ->call('saveSwitches');

        $profile = CompanyProfile::where('tenant_id', $tenant->id)->first();
        $this->assertTrue($profile->pay_manual_transfer_enabled);
        $this->assertTrue($profile->pay_gateway_enabled);
        $this->assertFalse($profile->pay_agent_enabled);
    }

    public function test_user_without_manage_cannot_add_bank(): void
    {
        $tenant = Tenant::factory()->create();
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo('company_profile.view'); // view saja, tanpa manage

        Livewire::actingAs($viewer)->test(PaymentSettings::class)
            ->set('newBankName', 'BCA')
            ->set('newAccountNumber', '111')
            ->set('newAccountHolder', 'PT Uji')
            ->call('addBank')
            ->assertForbidden();

        $this->assertDatabaseCount('company_bank_accounts', 0);
    }
}
