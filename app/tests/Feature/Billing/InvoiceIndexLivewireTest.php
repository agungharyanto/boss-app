<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Livewire\Billing\InvoiceIndex;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * v0.12.2 — 4 card ringkasan + filter rentang tanggal (due_date) di menu
 * Invoice. Belum ada test file untuk InvoiceIndex sebelum ini sama sekali.
 */
class InvoiceIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'invoices.view', 'guard_name' => 'web']));

        return $user;
    }

    private function invoice(Tenant $tenant, InvoiceStatus $status, string $dueDate, ?string $paidAt = null): Invoice
    {
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id]);

        return Invoice::factory()->create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
            'status' => $status,
            'due_date' => $dueDate,
            'paid_at' => $paidAt,
        ]);
    }

    public function test_summary_cards_count_overdue_and_paid_correctly(): void
    {
        $tenant = Tenant::factory()->create();
        $now = Carbon::now();

        // Overdue bulan ini.
        $this->invoice($tenant, InvoiceStatus::Overdue, $now->copy()->startOfMonth()->addDays(2)->toDateString());
        // Overdue bulan LALU — masuk "Overdue Semua" tapi bukan "Overdue Bulan Ini".
        $this->invoice($tenant, InvoiceStatus::Overdue, $now->copy()->subMonth()->toDateString());
        // Paid bulan ini (paid_at bulan ini).
        $this->invoice($tenant, InvoiceStatus::Paid, $now->copy()->startOfMonth()->addDays(5)->toDateString(), $now->copy()->toDateTimeString());
        // Pending bulan ini — masuk "Total Invoice Bulan Ini" saja.
        $this->invoice($tenant, InvoiceStatus::Pending, $now->copy()->startOfMonth()->addDays(10)->toDateString());

        $component = Livewire::actingAs($this->viewer($tenant))->test(InvoiceIndex::class);

        $summary = $component->viewData('summary');

        $this->assertSame(1, $summary['overdue_this_month']);
        $this->assertSame(2, $summary['overdue_all']);
        $this->assertSame(3, $summary['total_this_month']); // 3 invoice due bulan ini (overdue+paid+pending)
        $this->assertSame(1, $summary['paid_this_month']);
    }

    public function test_date_range_filter_narrows_the_table_by_due_date(): void
    {
        $tenant = Tenant::factory()->create();
        $inRange = $this->invoice($tenant, InvoiceStatus::Pending, '2026-06-15');
        $outOfRange = $this->invoice($tenant, InvoiceStatus::Pending, '2026-08-01');

        Livewire::actingAs($this->viewer($tenant))
            ->test(InvoiceIndex::class)
            ->set('dateFrom', '2026-06-01')
            ->set('dateTo', '2026-06-30')
            ->assertSee($inRange->invoice_number)
            ->assertDontSee($outOfRange->invoice_number);
    }

    public function test_reset_date_filter_clears_both_fields(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->viewer($tenant))
            ->test(InvoiceIndex::class)
            ->set('dateFrom', '2026-06-01')
            ->set('dateTo', '2026-06-30')
            ->call('resetDateFilter')
            ->assertSet('dateFrom', '')
            ->assertSet('dateTo', '');
    }

    public function test_summary_cards_are_scoped_per_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $now = Carbon::now();

        $this->invoice($tenantA, InvoiceStatus::Overdue, $now->copy()->startOfMonth()->addDay()->toDateString());
        $this->invoice($tenantB, InvoiceStatus::Overdue, $now->copy()->startOfMonth()->addDay()->toDateString());

        $summary = Livewire::actingAs($this->viewer($tenantA))
            ->test(InvoiceIndex::class)
            ->viewData('summary');

        $this->assertSame(1, $summary['overdue_this_month']);
        $this->assertSame(1, $summary['overdue_all']);
    }
}
