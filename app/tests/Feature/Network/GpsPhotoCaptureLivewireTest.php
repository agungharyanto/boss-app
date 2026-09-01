<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\GpsPhotoCapture;
use App\Models\FiberNode;
use App\Models\FiberNodePhoto;
use App\Models\Odp;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class GpsPhotoCaptureLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_mounts_against_a_fiber_node_owner(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'latitude' => -6.1, 'longitude' => 106.8]);

        Livewire::actingAs($this->admin($tenant))
            ->test(GpsPhotoCapture::class, ['ownerType' => FiberNode::class, 'ownerId' => $node->id])
            ->assertOk()
            ->assertSet('latitude', '-6.1000000')
            ->assertSet('longitude', '106.8000000');
    }

    public function test_mounts_against_an_odp_owner(): void
    {
        $tenant = Tenant::factory()->create();
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(GpsPhotoCapture::class, ['ownerType' => Odp::class, 'ownerId' => $odp->id])
            ->assertOk();
    }

    public function test_save_location_updates_the_owners_coordinates(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'latitude' => null, 'longitude' => null]);

        Livewire::actingAs($this->admin($tenant))
            ->test(GpsPhotoCapture::class, ['ownerType' => FiberNode::class, 'ownerId' => $node->id])
            ->set('latitude', '-6.2000000')
            ->set('longitude', '106.8000000')
            ->call('saveLocation')
            ->assertHasNoErrors();

        $node->refresh();
        $this->assertEquals(-6.2, (float) $node->latitude);
        $this->assertEquals(106.8, (float) $node->longitude);
    }

    public function test_uploading_a_photo_persists_a_fiber_node_photo_row(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(GpsPhotoCapture::class, ['ownerType' => FiberNode::class, 'ownerId' => $node->id])
            ->set('newPhotos', [UploadedFile::fake()->create('titik.jpg', 100, 'image/jpeg')])
            ->call('uploadPhotos')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fiber_node_photos', ['owner_type' => FiberNode::class, 'owner_id' => $node->id]);
    }

    public function test_deleting_a_photo_removes_the_row(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(GpsPhotoCapture::class, ['ownerType' => FiberNode::class, 'ownerId' => $node->id])
            ->set('newPhotos', [UploadedFile::fake()->create('titik.jpg', 100, 'image/jpeg')])
            ->call('uploadPhotos');

        $photoId = FiberNodePhoto::where('owner_id', $node->id)->firstOrFail()->id;

        $component->call('deletePhoto', $photoId);

        $this->assertDatabaseMissing('fiber_node_photos', ['id' => $photoId]);
    }
}
