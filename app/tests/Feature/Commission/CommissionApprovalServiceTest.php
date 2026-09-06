<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\TitipDepositStatus;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Commission\CommissionApprovalService;
use App\Services\Commission\CommissionPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.9.0 — sisa scope Commission: Approval (bulanan only) + Clawback
 * (semua skema, append-only). Level service — wiring Livewire ada di
 * CommissionApprovalLivewireTest.
 */
class CommissionApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function row(
        Tenant $tenant,
        CommissionScheme $scheme,
        CommissionStatus $status,
        float $amount = 5000,
        ?TitipDepositStatus $deposit = null,
    ): CommissionLedger {
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        return CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id,
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'scheme' => $scheme->value,
            'status' => $status,
            'amount' => $amount,
            'deposit_status' => $deposit,
        ]);
    }

    private function service(): CommissionApprovalService
    {
        return app(CommissionApprovalService::class);
    }

    // ---------- Approve ----------

    public function test_approve_moves_an_eligible_monthly_row_to_approved_with_reviewer_stamp(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->row($tenant, CommissionScheme::Recurring, CommissionStatus::Eligible);

        $result = $this->service()->approve($row, $admin);

        $this->assertSame(CommissionStatus::Approved, $result->status);
        $this->assertSame($admin->id, $result->reviewed_by);
        $this->assertNotNull($result->reviewed_at);
    }

    public function test_approve_rejects_a_titip_row(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->row($tenant, CommissionScheme::Titip, CommissionStatus::Eligible, deposit: TitipDepositStatus::SudahSetor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Approval hanya untuk komisi bulanan');

        $this->service()->approve($row, $admin);
    }

    public function test_approve_rejects_a_row_that_is_not_eligible(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->row($tenant, CommissionScheme::Recurring, CommissionStatus::Approved);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Layak Dibayar');

        $this->service()->approve($row, $admin);
    }

    // ---------- Reject ----------

    public function test_reject_moves_to_rejected_and_stores_the_reason_in_notes(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bu Admin']);
        $row = $this->row($tenant, CommissionScheme::LimitedCount, CommissionStatus::Eligible);

        $result = $this->service()->reject($row, $admin, 'pelanggan batal sebelum instalasi');

        $this->assertSame(CommissionStatus::Rejected, $result->status);
        $this->assertSame($admin->id, $result->reviewed_by);
        $this->assertStringContainsString('pelanggan batal sebelum instalasi', $result->notes);
        $this->assertStringContainsString('Bu Admin', $result->notes);
    }

    public function test_reject_requires_a_reason(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->row($tenant, CommissionScheme::Recurring, CommissionStatus::Eligible);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Alasan penolakan wajib');

        $this->service()->reject($row, $admin, '   ');
    }

    public function test_a_rejected_monthly_row_is_ignored_by_the_batch_payout(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->row($tenant, CommissionScheme::Recurring, CommissionStatus::Eligible);
        $referrerId = $row->referrer_id;

        $this->service()->reject($row, $admin, 'salah atribusi');

        $paid = app(CommissionPayoutService::class)
            ->payMonthlyForReferrer($referrerId, $admin);

        $this->assertSame(0, $paid);
        $this->assertSame(CommissionStatus::Rejected, $row->fresh()->status);
    }

    // ---------- Clawback (append-only) ----------

    public function test_clawback_creates_a_negative_reversal_row_and_leaves_the_original_untouched(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $original = $this->row($tenant, CommissionScheme::Recurring, CommissionStatus::Approved, amount: 5000);

        $reversal = $this->service()->clawback($original, $admin, 'pelanggan refund');

        // Baris asli TIDAK berubah.
        $original->refresh();
        $this->assertSame(CommissionStatus::Approved, $original->status);
        $this->assertEquals(5000, (float) $original->amount);

        // Baris reversal baru, negatif, menunjuk ke asli.
        $this->assertTrue($reversal->exists);
        $this->assertNotSame($original->id, $reversal->id);
        $this->assertSame(CommissionStatus::Clawback, $reversal->status);
        $this->assertEquals(-5000, (float) $reversal->amount);
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame($original->referrer_id, $reversal->referrer_id);
        $this->assertSame($admin->id, $reversal->reviewed_by);
        $this->assertStringContainsString('pelanggan refund', $reversal->notes);
    }

    public function test_clawback_of_a_paid_row_marks_the_reversal_as_a_debt(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $original = $this->row($tenant, CommissionScheme::Titip, CommissionStatus::Paid, amount: 3000, deposit: TitipDepositStatus::SudahSetor);

        $reversal = $this->service()->clawback($original, $admin, 'komisi kelebihan bayar');

        $this->assertStringContainsString('[UTANG]', $reversal->notes);
        $this->assertStringContainsString('SUDAH DIBAYAR', $reversal->notes);
        // Original Paid row stays Paid (append-only — the debt is a new record, not a status flip).
        $this->assertSame(CommissionStatus::Paid, $original->fresh()->status);
    }

    public function test_clawback_of_a_titip_eligible_row_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $original = $this->row($tenant, CommissionScheme::Titip, CommissionStatus::Eligible, amount: 3000, deposit: TitipDepositStatus::BelumSetor);

        $reversal = $this->service()->clawback($original, $admin, 'salah catat');

        $this->assertSame(CommissionStatus::Clawback, $reversal->status);
        $this->assertStringNotContainsString('[UTANG]', $reversal->notes);
    }

    public function test_clawback_is_rejected_for_pending_rejected_and_already_clawed_back_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);

        $pending = $this->row($tenant, CommissionScheme::Recurring, CommissionStatus::Pending);
        try {
            $this->service()->clawback($pending, $admin, 'x');
            $this->fail('Pending row should not be clawback-able');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Pending', $e->getMessage());
        }

        $rejected = $this->row($tenant, CommissionScheme::Recurring, CommissionStatus::Rejected);
        try {
            $this->service()->clawback($rejected, $admin, 'x');
            $this->fail('Rejected row should not be clawback-able');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Ditolak', $e->getMessage());
        }

        $paid = $this->row($tenant, CommissionScheme::Recurring, CommissionStatus::Paid);
        $this->service()->clawback($paid, $admin, 'first clawback');
        try {
            $this->service()->clawback($paid->fresh(), $admin, 'second clawback');
            $this->fail('Double clawback should not be allowed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah pernah dibatalkan', $e->getMessage());
        }
    }

    public function test_a_clawback_row_cannot_itself_be_clawed_back(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $original = $this->row($tenant, CommissionScheme::Recurring, CommissionStatus::Approved);
        $reversal = $this->service()->clawback($original, $admin, 'batal');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak bisa di-clawback lagi');

        $this->service()->clawback($reversal, $admin, 'lagi');
    }
}
