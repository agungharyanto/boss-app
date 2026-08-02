<?php

namespace Tests\Feature\Registration;

use App\Enums\CommissionStatus;
use App\Enums\RegistrationChannel;
use App\Models\Agent;
use App\Models\CommissionLedger;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_with_an_agent_attributes_the_referral_and_creates_a_pending_commission_row(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $agent = Agent::factory()->create(['tenant_id' => $tenant->id]);

        $customer = (new RegistrationService)->register([
            'name' => 'Pelanggan Via Sales',
            'address' => 'Jl. Sales No. 1',
            'phone_number' => '081377777001',
        ], $agent);

        $this->assertSame($agent->id, $customer->referred_by_agent_id);
        $this->assertSame(RegistrationChannel::Sales, $customer->registration_channel);

        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $agent->id,
            'customer_id' => $customer->id,
            'status' => CommissionStatus::Pending->value,
            'amount' => null,
        ]);
    }

    public function test_registering_without_an_agent_creates_no_commission_row(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $customer = (new RegistrationService)->register([
            'name' => 'Pelanggan Tanpa Referral',
            'address' => 'Jl. Admin No. 1',
            'phone_number' => '081377777002',
        ]);

        $this->assertNull($customer->referred_by_agent_id);
        $this->assertSame(RegistrationChannel::Admin, $customer->registration_channel);
        $this->assertSame(0, CommissionLedger::where('customer_id', $customer->id)->count());
    }
}
