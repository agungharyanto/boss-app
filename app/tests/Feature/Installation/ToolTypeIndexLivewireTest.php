<?php

namespace Tests\Feature\Installation;

use App\Livewire\Installation\ToolTypeIndex;
use App\Models\ToolType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.13.4.1 — CRUD sederhana ToolType (master data untuk form klaim WO
 * signed-link). Lihat ToolTypeIndex's own docblock.
 */
class ToolTypeIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_a_user_without_tool_types_view_permission_cannot_mount(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(ToolTypeIndex::class)->assertForbidden();
    }

    public function test_admin_can_create_a_tool_type(): void
    {
        $admin = $this->adminUser();

        Livewire::actingAs($admin)
            ->test(ToolTypeIndex::class)
            ->set('name', 'Konektor RJ45')
            ->set('category', 'Konektor')
            ->call('createToolType')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tool_types', [
            'tenant_id' => $admin->tenant_id,
            'name' => 'Konektor RJ45',
            'category' => 'Konektor',
            'is_active' => true,
        ]);
    }

    public function test_duplicate_name_within_the_same_tenant_is_rejected(): void
    {
        $admin = $this->adminUser();
        ToolType::create(['tenant_id' => $admin->tenant_id, 'name' => 'Dropcore', 'is_active' => true]);

        Livewire::actingAs($admin)
            ->test(ToolTypeIndex::class)
            ->set('name', 'Dropcore')
            ->call('createToolType')
            ->assertHasErrors('name');
    }

    public function test_admin_can_edit_a_tool_type(): void
    {
        $admin = $this->adminUser();
        $toolType = ToolType::create(['tenant_id' => $admin->tenant_id, 'name' => 'Adapter', 'is_active' => true]);

        Livewire::actingAs($admin)
            ->test(ToolTypeIndex::class)
            ->call('edit', $toolType->id)
            ->set('editName', 'Adapter Fiber')
            ->call('updateToolType')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tool_types', [
            'id' => $toolType->id,
            'name' => 'Adapter Fiber',
        ]);
    }

    public function test_admin_can_toggle_active_status_instead_of_a_hard_delete(): void
    {
        $admin = $this->adminUser();
        $toolType = ToolType::create(['tenant_id' => $admin->tenant_id, 'name' => 'Patchcore', 'is_active' => true]);

        Livewire::actingAs($admin)
            ->test(ToolTypeIndex::class)
            ->call('toggleActive', $toolType->id);

        $this->assertDatabaseHas('tool_types', [
            'id' => $toolType->id,
            'is_active' => false,
        ]);
    }
}
