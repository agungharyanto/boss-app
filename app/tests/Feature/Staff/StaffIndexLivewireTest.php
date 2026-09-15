<?php

namespace Tests\Feature\Staff;

use App\Livewire\Staff\StaffIndex;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffIndexLivewireTest extends TestCase
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

    public static function allRoles(): array
    {
        return [
            'superadmin' => ['superadmin'],
            'administrator' => ['administrator'],
            'noc' => ['noc'],
            'customer_service' => ['customer_service'],
            'teknisi' => ['teknisi'],
            'billing' => ['billing'],
            'sales_internal' => ['sales_internal'],
            'sales_freelance' => ['sales_freelance'],
            'finance' => ['finance'],
        ];
    }

    /**
     * @dataProvider allRoles
     */
    public function test_creating_a_staff_account_works_for_every_selectable_role(string $role): void
    {
        $tenant = Tenant::factory()->create();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('name', "Staff {$role}")
            ->set('email', "staff-{$role}@boss.local")
            ->set('role', $role)
            ->call('createStaff')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => "staff-{$role}@boss.local",
            'tenant_id' => $tenant->id,
        ]);

        $created = User::where('email', "staff-{$role}@boss.local")->firstOrFail();
        $this->assertTrue($created->hasRole($role));

        // Password acak yang di-generate harus muncul sekali di layar.
        $component->assertSet('generatedPasswordForName', "Staff {$role}");
        $this->assertNotNull($component->get('generatedPassword'));
    }

    public function test_role_is_required_when_creating_a_staff_account(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(StaffIndex::class)
            ->set('name', 'Tanpa Role')
            ->set('email', 'tanpa-role@boss.local')
            ->set('role', '')
            ->call('createStaff')
            ->assertHasErrors(['role' => 'required']);

        $this->assertDatabaseMissing('users', ['email' => 'tanpa-role@boss.local']);
    }

    public function test_access_is_denied_for_a_role_other_than_superadmin_or_administrator(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        Livewire::actingAs($user)->test(StaffIndex::class)->assertForbidden();
    }

    public function test_disabling_then_enabling_a_staff_account_toggles_the_flag(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id]);
        $staff->assignRole('noc');

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('toggleDisable', $staff->id);

        $this->assertTrue($staff->fresh()->is_disabled);

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('toggleDisable', $staff->id);

        $this->assertFalse($staff->fresh()->is_disabled);
    }

    public function test_editing_a_staff_account_changes_role_without_touching_the_password(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'edit-me@boss.local']);
        $staff->assignRole('billing');
        $originalHash = $staff->password;

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->call('edit', $staff->id)
            ->assertSet('editRole', 'billing')
            ->set('editName', 'Staff Diedit')
            ->set('editEmail', 'edit-me@boss.local')
            ->set('editRole', 'finance')
            ->call('updateStaff')
            ->assertHasNoErrors();

        $fresh = $staff->fresh();
        $this->assertSame('Staff Diedit', $fresh->name);
        $this->assertTrue($fresh->hasRole('finance'));
        $this->assertFalse($fresh->hasRole('billing'));
        $this->assertSame($originalHash, $fresh->password);
    }

    public function test_a_disabled_staff_user_still_shows_up_in_the_list_with_the_disabled_badge(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Staff Disabled', 'is_disabled' => true]);
        $staff->assignRole('teknisi');

        Livewire::actingAs($admin)
            ->test(StaffIndex::class)
            ->assertSee('Staff Disabled')
            ->assertSee('Disabled');
    }
}
