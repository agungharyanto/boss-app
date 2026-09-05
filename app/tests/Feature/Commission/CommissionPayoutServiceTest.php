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
use App\Services\Commission\CommissionPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.9.11 — Payout Komisi: Instan (Titip) & Batch Bulanan (Tanggal 5-7).
 * Level service, tidak lewat Livewire — komponen UI (TitipMasukIndex/
 * MonthlyPayoutIndex) sudah punya test Livewire terpisah untuk memastikan
 * wiring-nya benar; file ini membuktikan LOGIC-nya sendiri, termasuk
 * bahwa guard tanggal & guard setoran tidak bisa dilewati dengan
 * memanggil service secara langsung (bukan cuma disembunyikan di UI).
 */
class CommissionPayoutServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function ledgerRow(
        Tenant $tenant,
        CommissionScheme $scheme,
        CommissionStatus $status = CommissionStatus::Eligible,
        ?TitipDepositStatus $deposit = null,
        ?Referrer $referrer = null,
        float $amount = 3000,
    ): CommissionLedger {
        $referrer ??= Referrer::factory()->create(['tenant_id' => $tenant->id]);
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

    // ---------- Titip: instan ----------

    public function test_pay_titip_row_succeeds_when_eligible_and_sudah_setor(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->ledgerRow($tenant, CommissionScheme::Titip, CommissionStatus::Eligible, TitipDepositStatus::SudahSetor);

        $result = app(CommissionPayoutService::class)->payTitipRow(
            $row,
            $admin,
            UploadedFile::fake()->create('bukti.jpg', 100, 'image/jpeg')
        );

        $this->assertSame(CommissionStatus::Paid, $result->status);
        $this->assertNotNull($result->paid_at);
        $this->assertSame($admin->id, $result->paid_by);
        $this->assertNotNull($result->payment_proof_path);
        Storage::disk('local')->assertExists($result->payment_proof_path);
    }

    public function test_pay_titip_row_is_rejected_when_deposit_is_belum_setor(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->ledgerRow($tenant, CommissionScheme::Titip, CommissionStatus::Eligible, TitipDepositStatus::BelumSetor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum ditandai "Sudah Setor"');

        app(CommissionPayoutService::class)->payTitipRow($row, $admin, UploadedFile::fake()->create('bukti.jpg'));
    }

    public function test_pay_titip_row_is_rejected_when_already_paid(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->ledgerRow($tenant, CommissionScheme::Titip, CommissionStatus::Paid, TitipDepositStatus::SudahSetor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah pernah dibayar');

        app(CommissionPayoutService::class)->payTitipRow($row, $admin, UploadedFile::fake()->create('bukti.jpg'));
    }

    public function test_pay_titip_row_is_rejected_when_status_is_not_eligible(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->ledgerRow($tenant, CommissionScheme::Titip, CommissionStatus::Pending, TitipDepositStatus::SudahSetor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum berstatus');

        app(CommissionPayoutService::class)->payTitipRow($row, $admin, UploadedFile::fake()->create('bukti.jpg'));
    }

    public function test_pay_titip_row_is_rejected_for_non_titip_scheme(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->ledgerRow($tenant, CommissionScheme::Recurring, CommissionStatus::Eligible);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hanya berlaku untuk komisi skema Titip');

        app(CommissionPayoutService::class)->payTitipRow($row, $admin, UploadedFile::fake()->create('bukti.jpg'));
    }

    public function test_pay_titip_for_referrer_pays_only_qualifying_rows_and_skips_the_rest(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);

        $payable1 = $this->ledgerRow($tenant, CommissionScheme::Titip, CommissionStatus::Eligible, TitipDepositStatus::SudahSetor, $referrer);
        $payable2 = $this->ledgerRow($tenant, CommissionScheme::Titip, CommissionStatus::Eligible, TitipDepositStatus::SudahSetor, $referrer);
        $notYetDeposited = $this->ledgerRow($tenant, CommissionScheme::Titip, CommissionStatus::Eligible, TitipDepositStatus::BelumSetor, $referrer);
        $alreadyPaid = $this->ledgerRow($tenant, CommissionScheme::Titip, CommissionStatus::Paid, TitipDepositStatus::SudahSetor, $referrer);

        $affected = app(CommissionPayoutService::class)->payTitipForReferrer(
            $referrer->id,
            $admin,
            UploadedFile::fake()->create('bukti-batch.jpg')
        );

        $this->assertSame(2, $affected);
        $this->assertSame(CommissionStatus::Paid, $payable1->fresh()->status);
        $this->assertSame(CommissionStatus::Paid, $payable2->fresh()->status);
        $this->assertSame(CommissionStatus::Eligible, $notYetDeposited->fresh()->status);
        $this->assertNotNull($payable1->fresh()->payment_proof_path);
        // Satu bukti bayar yang sama dipakai untuk seluruh batch.
        $this->assertSame($payable1->fresh()->payment_proof_path, $payable2->fresh()->payment_proof_path);
    }

    public function test_pay_titip_for_referrer_returns_zero_when_nothing_qualifies(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $this->ledgerRow($tenant, CommissionScheme::Titip, CommissionStatus::Eligible, TitipDepositStatus::BelumSetor, $referrer);

        $affected = app(CommissionPayoutService::class)->payTitipForReferrer(
            $referrer->id,
            $admin,
            UploadedFile::fake()->create('bukti.jpg')
        );

        $this->assertSame(0, $affected);
    }

    // ---------- Bulanan: batch, jendela tanggal 5-7 ----------

    public function test_is_within_monthly_payout_window_true_for_day_5_6_7(): void
    {
        $service = app(CommissionPayoutService::class);

        $this->assertTrue($service->isWithinMonthlyPayoutWindow(Carbon::parse('2026-09-05')));
        $this->assertTrue($service->isWithinMonthlyPayoutWindow(Carbon::parse('2026-09-06')));
        $this->assertTrue($service->isWithinMonthlyPayoutWindow(Carbon::parse('2026-09-07')));
    }

    public function test_is_within_monthly_payout_window_false_outside_5_to_7(): void
    {
        $service = app(CommissionPayoutService::class);

        $this->assertFalse($service->isWithinMonthlyPayoutWindow(Carbon::parse('2026-09-04')));
        $this->assertFalse($service->isWithinMonthlyPayoutWindow(Carbon::parse('2026-09-08')));
        $this->assertFalse($service->isWithinMonthlyPayoutWindow(Carbon::parse('2026-09-01')));
        $this->assertFalse($service->isWithinMonthlyPayoutWindow(Carbon::parse('2026-09-30')));
    }

    public function test_pay_monthly_for_referrer_is_rejected_outside_the_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00'));

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $row = $this->ledgerRow($tenant, CommissionScheme::Recurring, CommissionStatus::Eligible, null, $referrer);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hanya bisa diproses tanggal 5-7');

        try {
            app(CommissionPayoutService::class)->payMonthlyForReferrer($referrer->id, $admin);
        } finally {
            // Baris tidak boleh berubah sama sekali.
            $this->assertSame(CommissionStatus::Eligible, $row->fresh()->status);
        }
    }

    public function test_pay_monthly_for_referrer_succeeds_and_batches_correctly_inside_the_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 09:00:00'));

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $otherReferrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);

        $recurring = $this->ledgerRow($tenant, CommissionScheme::Recurring, CommissionStatus::Eligible, null, $referrer);
        $limitedCount = $this->ledgerRow($tenant, CommissionScheme::LimitedCount, CommissionStatus::Eligible, null, $referrer);
        $titip = $this->ledgerRow($tenant, CommissionScheme::Titip, CommissionStatus::Eligible, TitipDepositStatus::SudahSetor, $referrer);
        $otherReferrerRow = $this->ledgerRow($tenant, CommissionScheme::Recurring, CommissionStatus::Eligible, null, $otherReferrer);
        $alreadyPending = $this->ledgerRow($tenant, CommissionScheme::Recurring, CommissionStatus::Pending, null, $referrer);

        $affected = app(CommissionPayoutService::class)->payMonthlyForReferrer($referrer->id, $admin);

        $this->assertSame(2, $affected);
        $this->assertSame(CommissionStatus::Paid, $recurring->fresh()->status);
        $this->assertSame(CommissionStatus::Paid, $limitedCount->fresh()->status);
        $this->assertNotNull($recurring->fresh()->paid_at);
        $this->assertSame($admin->id, $recurring->fresh()->paid_by);
        // Titip TIDAK ikut terbayar lewat jalur bulanan (mekanisme beda total).
        $this->assertSame(CommissionStatus::Eligible, $titip->fresh()->status);
        // Referrer lain tidak ikut terpengaruh.
        $this->assertSame(CommissionStatus::Eligible, $otherReferrerRow->fresh()->status);
        // Baris yang belum Eligible tidak ikut dibayar.
        $this->assertSame(CommissionStatus::Pending, $alreadyPending->fresh()->status);
        // Payout bulanan tidak mensyaratkan bukti bayar.
        $this->assertNull($recurring->fresh()->payment_proof_path);
    }
}
