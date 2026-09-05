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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v0.9.11 (Payout Komisi) — `commission-payment-proofs/{commission_ledger}`
 * mirror `FiberNodePhotoController` (v0.16.0): file bukti bayar disimpan
 * di disk 'local' (privat), hanya bisa diakses lewat endpoint ber-auth
 * ini, tidak pernah dari storage publik.
 */
class CommissionPaymentProofControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    private function paidTitipRow(Tenant $tenant, User $actor): CommissionLedger
    {
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        $row = CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id,
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
            'status' => CommissionStatus::Eligible,
            'amount' => 3000,
            'deposit_status' => TitipDepositStatus::SudahSetor,
        ]);

        app(CommissionPayoutService::class)->payTitipRow($row, $actor, UploadedFile::fake()->create('bukti.jpg'));

        return $row->fresh();
    }

    public function test_an_authorized_admin_can_view_the_proof(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $row = $this->paidTitipRow($tenant, $admin);

        $this->actingAs($admin)
            ->get(route('web.commission-payment-proofs.show', $row->id))
            ->assertOk();
    }

    public function test_a_user_without_the_permission_gets_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $row = $this->paidTitipRow($tenant, $admin);

        $plain = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($plain)
            ->get(route('web.commission-payment-proofs.show', $row->id))
            ->assertForbidden();
    }

    public function test_a_row_from_another_tenant_is_not_found(): void
    {
        $tenantA = Tenant::factory()->create();
        $adminA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $adminA->assignRole('superadmin');
        $row = $this->paidTitipRow($tenantA, $adminA);

        $tenantB = Tenant::factory()->create();
        $adminB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $adminB->assignRole('superadmin');

        $this->actingAs($adminB)
            ->get(route('web.commission-payment-proofs.show', $row->id))
            ->assertNotFound();
    }

    public function test_a_row_with_no_proof_uploaded_yet_is_not_found(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $row = CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id,
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
            'status' => CommissionStatus::Eligible,
        ]);

        $this->actingAs($admin)
            ->get(route('web.commission-payment-proofs.show', $row->id))
            ->assertNotFound();
    }
}
