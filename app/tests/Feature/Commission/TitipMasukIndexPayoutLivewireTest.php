<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\TitipDepositStatus;
use App\Livewire\Commission\TitipMasukIndex;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.9.11 (Payout Komisi) — "Bayar Komisi Sekarang" (per baris) & "Bayar
 * Semua yang Bisa Dibayar" (per grup) di halaman Fee Komisi. Instan (tidak
 * ada guard tanggal), tapi wajib deposit_status=SudahSetor dan wajib
 * upload bukti bayar.
 */
class TitipMasukIndexPayoutLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    private function admin(Tenant $tenant, string $role = 'superadmin'): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function titipRow(
        Tenant $tenant,
        CommissionStatus $status = CommissionStatus::Eligible,
        TitipDepositStatus $deposit = TitipDepositStatus::SudahSetor,
        ?Referrer $referrer = null,
    ): CommissionLedger {
        $referrer ??= Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        return CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id,
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
            'status' => $status,
            'amount' => 3000,
            'gross_amount' => 150000,
            'deposit_status' => $deposit,
        ]);
    }

    public function test_pay_button_only_shows_for_rows_that_are_eligible_and_sudah_setor(): void
    {
        $tenant = Tenant::factory()->create();
        $payable = $this->titipRow($tenant, CommissionStatus::Eligible, TitipDepositStatus::SudahSetor);
        $this->titipRow($tenant, CommissionStatus::Eligible, TitipDepositStatus::BelumSetor);

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->html();

        $this->assertStringContainsString("openPayRowModal({$payable->id})", $html);
        $this->assertSame(1, substr_count($html, 'openPayRowModal('));
    }

    public function test_confirm_pay_row_requires_a_proof_image(): void
    {
        $tenant = Tenant::factory()->create();
        $row = $this->titipRow($tenant);

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->call('openPayRowModal', $row->id)
            ->call('confirmPayRow')
            ->assertHasErrors(['paymentProof' => 'required']);

        $this->assertSame(CommissionStatus::Eligible, $row->fresh()->status);
    }

    public function test_confirm_pay_row_succeeds_with_a_valid_proof(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $row = $this->titipRow($tenant);

        Livewire::actingAs($admin)
            ->test(TitipMasukIndex::class)
            ->call('openPayRowModal', $row->id)
            ->set('paymentProof', UploadedFile::fake()->create('bukti.jpg', 100, 'image/jpeg'))
            ->call('confirmPayRow')
            ->assertHasNoErrors()
            ->assertSet('payingLedgerId', null);

        $fresh = $row->fresh();
        $this->assertSame(CommissionStatus::Paid, $fresh->status);
        $this->assertSame($admin->id, $fresh->paid_by);
        $this->assertNotNull($fresh->payment_proof_path);
    }

    public function test_confirm_pay_row_is_rejected_server_side_even_if_called_directly_on_a_belum_setor_row(): void
    {
        // Defense-in-depth: tombol tidak pernah muncul untuk baris ini di
        // UI, tapi memanggil method Livewire-nya secara langsung (bypass
        // tombol) tetap harus ditolak oleh guard di service.
        $tenant = Tenant::factory()->create();
        $row = $this->titipRow($tenant, CommissionStatus::Eligible, TitipDepositStatus::BelumSetor);

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->call('openPayRowModal', $row->id)
            ->set('paymentProof', UploadedFile::fake()->create('bukti.jpg'))
            ->call('confirmPayRow')
            ->assertHasErrors('paymentProof');

        $this->assertSame(CommissionStatus::Eligible, $row->fresh()->status);
    }

    public function test_confirm_pay_referrer_batches_all_qualifying_rows_for_that_referrer(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $payable1 = $this->titipRow($tenant, CommissionStatus::Eligible, TitipDepositStatus::SudahSetor, $referrer);
        $payable2 = $this->titipRow($tenant, CommissionStatus::Eligible, TitipDepositStatus::SudahSetor, $referrer);
        $notReady = $this->titipRow($tenant, CommissionStatus::Eligible, TitipDepositStatus::BelumSetor, $referrer);

        Livewire::actingAs($admin)
            ->test(TitipMasukIndex::class)
            ->call('openPayReferrerModal', $referrer->id)
            ->set('paymentProof', UploadedFile::fake()->create('bukti-batch.jpg'))
            ->call('confirmPayReferrer')
            ->assertHasNoErrors();

        $this->assertSame(CommissionStatus::Paid, $payable1->fresh()->status);
        $this->assertSame(CommissionStatus::Paid, $payable2->fresh()->status);
        $this->assertSame(CommissionStatus::Eligible, $notReady->fresh()->status);
    }

    public function test_a_view_only_user_cannot_open_or_confirm_the_pay_modal(): void
    {
        $tenant = Tenant::factory()->create();
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo('commission_ledger.view');
        $row = $this->titipRow($tenant);

        Livewire::actingAs($viewer)
            ->test(TitipMasukIndex::class)
            ->call('openPayRowModal', $row->id)
            ->assertForbidden();
    }

    public function test_paid_row_shows_a_link_to_the_uploaded_proof(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $row = $this->titipRow($tenant);

        $component = Livewire::actingAs($admin)
            ->test(TitipMasukIndex::class)
            ->call('openPayRowModal', $row->id)
            ->set('paymentProof', UploadedFile::fake()->create('bukti.jpg'))
            ->call('confirmPayRow');

        $component->assertSee('Lihat Bukti');
        $this->assertStringContainsString(
            route('web.commission-payment-proofs.show', $row->id),
            $component->html()
        );
    }
}
