<?php

namespace Tests\Feature\Registration;

use App\Enums\CommissionStatus;
use App\Enums\RegistrationChannel;
use App\Models\CommissionLedger;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_with_a_referrer_attributes_the_referral_and_creates_a_pending_commission_row(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);

        $customer = app(RegistrationService::class)->register([
            'name' => 'Pelanggan Via Sales',
            'address' => 'Jl. Sales No. 1',
            'phone_number' => '081377777001',
        ], $referrer);

        $this->assertSame($referrer->id, $customer->referred_by_referrer_id);
        $this->assertSame(RegistrationChannel::Sales, $customer->registration_channel);

        $this->assertDatabaseHas('commission_ledger', [
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'status' => CommissionStatus::Pending->value,
            'amount' => null,
        ]);
    }

    public function test_registering_without_a_referrer_creates_no_commission_row(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $customer = app(RegistrationService::class)->register([
            'name' => 'Pelanggan Tanpa Referral',
            'address' => 'Jl. Admin No. 1',
            'phone_number' => '081377777002',
        ]);

        $this->assertNull($customer->referred_by_referrer_id);
        $this->assertSame(RegistrationChannel::Admin, $customer->registration_channel);
        $this->assertSame(0, CommissionLedger::where('customer_id', $customer->id)->count());
    }
}
