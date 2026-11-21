<?php

namespace Tests\Feature\Customers;

use App\Livewire\Customers\CustomerShow;
use App\Models\Customer;
use App\Models\CustomerNotificationOptOutLog;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.28.1 (T1) — UI Detail Pelanggan opt-out: otorisasi (customers.manage),
 * toggle lewat service (1 log), alasan wajib, status sudah sama ditolak, dan
 * banner "OPT-OUT" di modal kirim MANUAL. Livewire HANYA memanggil service.
 */
class CustomerOptOutLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        return $admin;
    }

    public function test_admin_can_opt_out_via_modal(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->call('openOptOutModal')
            ->assertSet('showOptOutModal', true)
            ->set('optOutReason', 'permintaan pelanggan lewat telepon')
            ->call('submitOptOut')
            ->assertHasNoErrors()
            ->assertSet('showOptOutModal', false);

        $this->assertTrue($customer->fresh()->auto_notify_opt_out);
        $this->assertSame(1, CustomerNotificationOptOutLog::count());
    }

    public function test_opt_in_toggle_when_already_opted_out(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id, 'reseller_id' => null, 'auto_notify_opt_out' => true,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->call('openOptOutModal')
            ->set('optOutReason', 'pelanggan minta aktif lagi')
            ->call('submitOptOut')
            ->assertHasNoErrors();

        $this->assertFalse($customer->fresh()->auto_notify_opt_out);
        $this->assertSame(1, CustomerNotificationOptOutLog::count());
    }

    public function test_reason_required_shows_error_and_does_not_change(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->call('openOptOutModal')
            ->set('optOutReason', 'ab')
            ->call('submitOptOut')
            ->assertHasErrors('optOutReason');

        $this->assertFalse($customer->fresh()->auto_notify_opt_out);
        $this->assertSame(0, CustomerNotificationOptOutLog::count());
    }

    public function test_viewer_without_manage_cannot_submit_opt_out(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo('customers.view'); // bisa lihat, TIDAK bisa manage

        Livewire::actingAs($viewer)
            ->test(CustomerShow::class, ['customer' => $customer])
            ->set('optOutReason', 'coba-coba')
            ->call('submitOptOut')
            ->assertForbidden();

        $this->assertFalse($customer->fresh()->auto_notify_opt_out);
        $this->assertSame(0, CustomerNotificationOptOutLog::count());
    }

    public function test_banner_shows_in_manual_pdf_modal_when_opted_out(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id, 'reseller_id' => null,
            'auto_notify_opt_out' => true, 'phone_number' => '6287884374939',
        ]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            'subscription_id' => $subscription->id, 'reseller_id' => null,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->call('confirmSendPdf', $invoice->id)
            ->assertSee('OPT-OUT dari notifikasi otomatis');
    }

    public function test_banner_absent_in_manual_pdf_modal_when_not_opted_out(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id, 'reseller_id' => null,
            'auto_notify_opt_out' => false, 'phone_number' => '6287884374939',
        ]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            'subscription_id' => $subscription->id, 'reseller_id' => null,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->call('confirmSendPdf', $invoice->id)
            ->assertDontSee('OPT-OUT dari notifikasi otomatis');
    }
}
