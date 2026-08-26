<?php

namespace Tests\Feature\Referrers;

use App\Enums\ReferrerType;
use App\Livewire\Referrers\ReferrerIndex;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReferrerIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_admin_can_render_and_create_a_referrer(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(ReferrerIndex::class)
            ->set('name', 'Andi Referral')
            ->set('phone', '081298765432')
            ->set('type', ReferrerType::Sales->value)
            ->call('createReferrer')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('referrers', ['name' => 'Andi Referral', 'tenant_id' => $tenant->id]);
    }

    public function test_creating_with_login_account_reveals_a_generated_password(): void
    {
        $tenant = Tenant::factory()->create();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(ReferrerIndex::class)
            ->set('name', 'Rina Referral')
            ->set('phone', '081298765433')
            ->set('type', ReferrerType::Sales->value)
            ->set('createLoginAccount', true)
            ->call('createReferrer')
            ->assertHasNoErrors();

        $this->assertNotNull($component->get('generatedPassword'));
    }

    public function test_non_admin_tier_user_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        Livewire::actingAs($user)->test(ReferrerIndex::class)->assertForbidden();
    }

    public function test_deactivating_a_referrer(): void
    {
        $tenant = Tenant::factory()->create();
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);

        Livewire::actingAs($this->admin($tenant))
            ->test(ReferrerIndex::class)
            ->call('deactivateReferrer', $referrer->id);

        $this->assertFalse($referrer->fresh()->is_active);
    }
}
