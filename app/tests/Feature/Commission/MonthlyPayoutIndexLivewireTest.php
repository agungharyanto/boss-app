<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Livewire\Commission\MonthlyPayoutIndex;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.9.11 (Payout Komisi) — halaman batch payout komisi bulanan
 * (recurring/limited_count), HANYA bisa diproses tanggal 5-7. Guard
 * sesungguhnya ada di `CommissionPayoutService` (lihat
 * CommissionPayoutServiceTest) — file ini membuktikan wiring UI-nya:
 * banner/tombol reaktif terhadap tanggal, dan memanggil method Livewire
 * secara langsung di luar jendela tetap ditolak (bukan cuma disembunyikan
 * di tombol).
 */
class MonthlyPayoutIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(Tenant $tenant, string $role = 'superadmin'): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function monthlyRow(Tenant $tenant, Referrer $referrer, CommissionScheme $scheme = CommissionScheme::Recurring): CommissionLedger
    {
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        return CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id,
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'scheme' => $scheme->value,
            'status' => CommissionStatus::Eligible,
            'amount' => 5000,
        ]);
    }

    public function test_page_is_forbidden_without_the_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $plain = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($plain)->test(MonthlyPayoutIndex::class)->assertForbidden();
    }

    public function test_lists_eligible_recurring_and_limited_count_rows_grouped_by_referrer(): void
    {
        $tenant = Tenant::factory()->create();
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Kamisem']);
        $this->monthlyRow($tenant, $referrer, CommissionScheme::Recurring);
        $this->monthlyRow($tenant, $referrer, CommissionScheme::LimitedCount);

        Livewire::actingAs($this->admin($tenant))
            ->test(MonthlyPayoutIndex::class)
            ->assertSee('Kamisem')
            ->assertSee('2 baris');
    }

    public function test_banner_shown_and_process_button_disabled_outside_the_5_to_7_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00'));

        $tenant = Tenant::factory()->create();
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $this->monthlyRow($tenant, $referrer);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(MonthlyPayoutIndex::class)
            ->assertSee('hanya bisa diproses tanggal')
            ->html();

        $this->assertMatchesRegularExpression('/wire:click="payReferrer\(\d+\)"[^>]*\bdisabled\b(?!:)/s', $html);
    }

    public function test_process_payout_button_is_enabled_and_not_disabled_inside_the_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00'));

        $tenant = Tenant::factory()->create();
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $this->monthlyRow($tenant, $referrer);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(MonthlyPayoutIndex::class)
            ->assertDontSee('hanya bisa diproses tanggal')
            ->html();

        $this->assertDoesNotMatchRegularExpression('/wire:click="payReferrer\(\d+\)"[^>]*\bdisabled\b(?!:)/s', $html);
    }

    public function test_calling_pay_referrer_directly_outside_the_window_is_rejected_not_just_hidden_by_the_button(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00'));

        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->monthlyRow($tenant, $referrer);

        Livewire::actingAs($admin)
            ->test(MonthlyPayoutIndex::class)
            ->call('payReferrer', $referrer->id)
            ->assertSee('hanya bisa diproses tanggal');

        $this->assertSame(CommissionStatus::Eligible, $row->fresh()->status);
    }

    public function test_process_payout_succeeds_and_batches_correctly_inside_the_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 23:59:00'));

        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $row1 = $this->monthlyRow($tenant, $referrer, CommissionScheme::Recurring);
        $row2 = $this->monthlyRow($tenant, $referrer, CommissionScheme::LimitedCount);

        Livewire::actingAs($admin)
            ->test(MonthlyPayoutIndex::class)
            ->call('payReferrer', $referrer->id)
            ->assertSee('2 baris komisi bulanan ditandai dibayar');

        $this->assertSame(CommissionStatus::Paid, $row1->fresh()->status);
        $this->assertSame(CommissionStatus::Paid, $row2->fresh()->status);
        $this->assertSame($admin->id, $row1->fresh()->paid_by);
    }

    public function test_a_view_only_user_cannot_process_payout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00'));

        $tenant = Tenant::factory()->create();
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo('commission_ledger.view');
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->monthlyRow($tenant, $referrer);

        Livewire::actingAs($viewer)
            ->test(MonthlyPayoutIndex::class)
            ->call('payReferrer', $referrer->id)
            ->assertForbidden();

        $this->assertSame(CommissionStatus::Eligible, $row->fresh()->status);
    }
}
