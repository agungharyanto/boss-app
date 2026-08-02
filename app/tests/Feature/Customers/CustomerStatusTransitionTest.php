<?php

namespace Tests\Feature\Customers;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function customerServiceUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('customer_service');

        return $user;
    }

    public function test_valid_transition_prospek_to_aktif_succeeds(): void
    {
        $user = $this->customerServiceUser();
        $customer = Customer::factory()->create(['status' => CustomerStatus::Prospek]);

        $response = $this->actingAs($user)->patchJson("/api/v1/customers/{$customer->id}/status", [
            'status' => 'aktif',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'aktif');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'aktif']);
    }

    public function test_invalid_transition_prospek_to_suspend_is_rejected(): void
    {
        $user = $this->customerServiceUser();
        $customer = Customer::factory()->create(['status' => CustomerStatus::Prospek]);

        $response = $this->actingAs($user)->patchJson("/api/v1/customers/{$customer->id}/status", [
            'status' => 'suspend',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'prospek']);
    }

    public function test_any_status_can_transition_to_blacklist(): void
    {
        $user = $this->customerServiceUser();
        $customer = Customer::factory()->create(['status' => CustomerStatus::Aktif]);

        $this->actingAs($user)
            ->patchJson("/api/v1/customers/{$customer->id}/status", ['status' => 'blacklist'])
            ->assertOk()
            ->assertJsonPath('data.status', 'blacklist');
    }

    public function test_blacklist_is_terminal_and_rejects_any_further_transition(): void
    {
        $user = $this->customerServiceUser();
        $customer = Customer::factory()->create(['status' => CustomerStatus::Blacklist]);

        $response = $this->actingAs($user)->patchJson("/api/v1/customers/{$customer->id}/status", [
            'status' => 'aktif',
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'blacklist']);
    }

    public function test_aktif_suspend_non_aktif_can_move_freely_between_each_other(): void
    {
        $user = $this->customerServiceUser();
        $customer = Customer::factory()->create(['status' => CustomerStatus::Aktif]);

        $this->actingAs($user)
            ->patchJson("/api/v1/customers/{$customer->id}/status", ['status' => 'suspend'])
            ->assertOk();

        $this->actingAs($user)
            ->patchJson("/api/v1/customers/{$customer->id}/status", ['status' => 'non_aktif'])
            ->assertOk();

        $this->actingAs($user)
            ->patchJson("/api/v1/customers/{$customer->id}/status", ['status' => 'aktif'])
            ->assertOk();
    }

    public function test_status_change_is_recorded_in_timeline(): void
    {
        $user = $this->customerServiceUser();
        $customer = Customer::factory()->create(['status' => CustomerStatus::Prospek]);

        $this->actingAs($user)->patchJson("/api/v1/customers/{$customer->id}/status", ['status' => 'aktif']);

        $this->assertDatabaseHas('customer_timeline_entries', [
            'customer_id' => $customer->id,
            'event_type' => 'status_changed',
            'actor_id' => $user->id,
        ]);
    }
}
