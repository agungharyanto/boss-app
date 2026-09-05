<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\NetworkProfileGroupType;
use App\Enums\TitipDepositStatus;
use App\Models\BandwidthProfile;
use App\Models\CommissionLedger;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
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
 * v0.9.11 — Payout Komisi: Instan (Titip) & Batch Bulanan. Level service,
 * tidak lewat Livewire — komponen UI (TitipMasukIndex/MonthlyPayoutIndex)
 * sudah punya test Livewire terpisah untuk memastikan wiring-nya benar;
 * file ini membuktikan LOGIC-nya sendiri, termasuk bahwa guard setoran
 * (Titip) & guard jendela tanggal per-rate (Bulanan) tidak bisa dilewati
 * dengan memanggil service secara langsung (bukan cuma disembunyikan di
 * UI).
 *
 * AMANDEMEN (2026-09-05) — jendela tanggal payout bulanan BUKAN LAGI
 * hardcode global "5-7" — sekarang per `CommissionRate` (lihat
 * `CommissionRate::payout_window_start_day`/`_end_day`). Bagian "Bulanan"
 * di bawah ditulis ulang total untuk desain baru ini.
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
        ?PppPackage $package = null,
    ): CommissionLedger {
        $referrer ??= Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $package?->id,
        ]);

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

    /**
     * PppPackage lengkap (Grup Profil + Bandwidth Profile) dengan
     * CommissionRate ber-jendela tanggal tertentu (atau tanpa jendela sama
     * sekali kalau kedua parameter null) — untuk menguji resolusi
     * `isRowPayableNow()` yang membaca dari rate paket customer-nya.
     */
    private function packageWithWindow(Tenant $tenant, ?int $start, ?int $end): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => NetworkProfileGroupType::Ppp,
        ]);

        $package = PppPackage::factory()->create([
            'tenant_id' => $tenant->id,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $tenant->id])->id,
        ]);

        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => 5000,
            'payout_window_start_day' => $start,
            'payout_window_end_day' => $end,
        ]);

        return $package;
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

    // ---------- Bulanan: batch, jendela tanggal PER RATE ----------

    public function test_is_row_payable_now_is_true_when_customer_has_no_package_at_all(): void
    {
        $tenant = Tenant::factory()->create();
        // ledgerRow() tanpa $package -> customer.ppp_package_id null.
        $row = $this->ledgerRow($tenant, CommissionScheme::Recurring);

        $this->assertTrue(app(CommissionPayoutService::class)->isRowPayableNow($row));
    }

    public function test_is_row_payable_now_is_true_when_the_rate_has_no_window_configured(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->packageWithWindow($tenant, null, null);
        $row = $this->ledgerRow($tenant, CommissionScheme::Recurring, package: $package);

        $this->assertTrue(app(CommissionPayoutService::class)->isRowPayableNow($row, Carbon::parse('2026-09-20')));
    }

    public function test_is_row_payable_now_respects_the_rates_own_window(): void
    {
        $tenant = Tenant::factory()->create();
        $package = $this->packageWithWindow($tenant, 5, 7);
        $row = $this->ledgerRow($tenant, CommissionScheme::Recurring, package: $package);

        $service = app(CommissionPayoutService::class);
        $this->assertTrue($service->isRowPayableNow($row, Carbon::parse('2026-09-05')));
        $this->assertTrue($service->isRowPayableNow($row, Carbon::parse('2026-09-07')));
        $this->assertFalse($service->isRowPayableNow($row, Carbon::parse('2026-09-04')));
        $this->assertFalse($service->isRowPayableNow($row, Carbon::parse('2026-09-08')));
    }

    public function test_pay_monthly_for_referrer_pays_only_rows_whose_own_rate_window_is_currently_open(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00'));

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);

        // Referrer ini punya komisi dari 2 paket dengan jendela BERBEDA —
        // satu terbuka (5-7, hari ini tanggal 6), satu tertutup (20-25).
        $openPackage = $this->packageWithWindow($tenant, 5, 7);
        $closedPackage = $this->packageWithWindow($tenant, 20, 25);
        $unrestrictedPackage = $this->packageWithWindow($tenant, null, null);

        $openRow = $this->ledgerRow($tenant, CommissionScheme::Recurring, CommissionStatus::Eligible, null, $referrer, package: $openPackage);
        $closedRow = $this->ledgerRow($tenant, CommissionScheme::LimitedCount, CommissionStatus::Eligible, null, $referrer, package: $closedPackage);
        $unrestrictedRow = $this->ledgerRow($tenant, CommissionScheme::Recurring, CommissionStatus::Eligible, null, $referrer, package: $unrestrictedPackage);
        $titip = $this->ledgerRow($tenant, CommissionScheme::Titip, CommissionStatus::Eligible, TitipDepositStatus::SudahSetor, $referrer, package: $openPackage);

        $affected = app(CommissionPayoutService::class)->payMonthlyForReferrer($referrer->id, $admin);

        // Hanya 2 dari 3 baris bulanan yang genuinely payable sekarang —
        // baris dari paket tertutup DILEWATI, bukan menggagalkan batch.
        $this->assertSame(2, $affected);
        $this->assertSame(CommissionStatus::Paid, $openRow->fresh()->status);
        $this->assertSame(CommissionStatus::Paid, $unrestrictedRow->fresh()->status);
        $this->assertSame(CommissionStatus::Eligible, $closedRow->fresh()->status);
        $this->assertNotNull($openRow->fresh()->paid_at);
        $this->assertSame($admin->id, $openRow->fresh()->paid_by);
        // Titip TIDAK ikut terbayar lewat jalur bulanan (mekanisme beda total).
        $this->assertSame(CommissionStatus::Eligible, $titip->fresh()->status);
        // Payout bulanan tidak mensyaratkan bukti bayar.
        $this->assertNull($openRow->fresh()->payment_proof_path);
    }

    public function test_pay_monthly_for_referrer_returns_zero_when_every_row_is_currently_closed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00'));

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $closedPackage = $this->packageWithWindow($tenant, 5, 7);
        $row = $this->ledgerRow($tenant, CommissionScheme::Recurring, CommissionStatus::Eligible, null, $referrer, package: $closedPackage);

        $affected = app(CommissionPayoutService::class)->payMonthlyForReferrer($referrer->id, $admin);

        $this->assertSame(0, $affected);
        $this->assertSame(CommissionStatus::Eligible, $row->fresh()->status);
    }

    public function test_pay_monthly_for_referrer_ignores_other_referrers_and_non_eligible_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00'));

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $otherReferrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);

        $recurring = $this->ledgerRow($tenant, CommissionScheme::Recurring, CommissionStatus::Eligible, null, $referrer);
        $otherReferrerRow = $this->ledgerRow($tenant, CommissionScheme::Recurring, CommissionStatus::Eligible, null, $otherReferrer);
        $alreadyPending = $this->ledgerRow($tenant, CommissionScheme::Recurring, CommissionStatus::Pending, null, $referrer);

        $affected = app(CommissionPayoutService::class)->payMonthlyForReferrer($referrer->id, $admin);

        $this->assertSame(1, $affected);
        $this->assertSame(CommissionStatus::Paid, $recurring->fresh()->status);
        // Referrer lain tidak ikut terpengaruh.
        $this->assertSame(CommissionStatus::Eligible, $otherReferrerRow->fresh()->status);
        // Baris yang belum Eligible tidak ikut dibayar.
        $this->assertSame(CommissionStatus::Pending, $alreadyPending->fresh()->status);
    }
}
