<?php

namespace Tests\Feature\Network;

use App\Models\FiberNode;
use App\Models\FiberNodePhoto;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\FiberTopologyService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v0.16.1 Revisi 3 D — the streaming photo endpoint. Regression guard for
 * the return-type bug: `Storage::disk('local')->response()` returns a
 * `StreamedResponse` (real server + Storage::fake both), so the old
 * `: Illuminate\Http\Response` hint threw a TypeError -> 500 -> broken
 * <img> everywhere a fiber-node/ODP photo was shown, since v0.16.0. No
 * HTTP test existed for this endpoint before.
 */
class FiberNodePhotoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    private function photo(Tenant $tenant): FiberNodePhoto
    {
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'closure']);

        return app(FiberTopologyService::class)->addPhoto(
            $node,
            UploadedFile::fake()->create('closure.jpg', 30, 'image/jpeg'),
            'Depan closure',
        );
    }

    public function test_an_authorised_user_gets_the_streamed_photo_not_a_500(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');
        $photo = $this->photo($tenant);

        $response = $this->actingAs($user)->get(route('web.fiber-node-photos.show', $photo->id));

        $response->assertOk();
        $this->assertNotSame(500, $response->getStatusCode());
    }

    public function test_a_user_without_network_infrastructure_permission_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $photo = $this->photo($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]); // no roles

        $this->actingAs($user)
            ->get(route('web.fiber-node-photos.show', $photo->id))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $tenant = Tenant::factory()->create();
        $photo = $this->photo($tenant);

        $this->get(route('web.fiber-node-photos.show', $photo->id))->assertRedirect(route('login'));
    }
}
