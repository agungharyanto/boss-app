<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\FiberNodeForm;
use App\Models\FiberNode;
use App\Models\FiberNodePhoto;
use App\Models\Odp;
use App\Models\Splitter;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FiberNodeFormLivewireTest extends TestCase
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

    public function test_create_form_renders(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->assertOk()
            ->assertSee('Perangkat Passive Baru');
    }

    public function test_edit_form_renders_with_existing_values(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'local_label' => 'OTB-Edit']);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class, ['fiber_node' => $node])
            ->assertOk()
            ->assertSet('localLabel', 'OTB-Edit')
            ->assertSee('Edit Perangkat Passive');
    }

    public function test_creating_an_otb_node_succeeds_without_loss_values(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'otb')
            ->set('localLabel', 'OTB-New')
            ->set('portCount', '8')
            ->set('latitude', '-6.2000')
            ->set('longitude', '106.8000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fiber_nodes', ['local_label' => 'OTB-New', 'tenant_id' => $tenant->id]);
    }

    public function test_creating_an_odc_node_without_loss_values_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odc')
            ->set('localLabel', 'ODC-New')
            ->call('save');

        $component->assertHasErrors(['lossInDb', 'lossOutDb']);
        $this->assertDatabaseMissing('fiber_nodes', ['local_label' => 'ODC-New']);
    }

    public function test_creating_an_odc_node_with_loss_values_succeeds(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odc')
            ->set('localLabel', 'ODC-New')
            ->set('lossInDb', '1.2')
            ->set('lossOutDb', '1.5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fiber_nodes', ['local_label' => 'ODC-New', 'loss_in_db' => 1.2, 'loss_out_db' => 1.5]);
    }

    public function test_editing_an_existing_node_updates_it(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb', 'local_label' => 'Before']);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class, ['fiber_node' => $node])
            ->set('localLabel', 'After')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('web.fiber-nodes.index'));

        $this->assertDatabaseHas('fiber_nodes', ['id' => $node->id, 'local_label' => 'After']);
    }

    public function test_a_successful_create_redirects_to_the_list_a_failed_one_stays_put(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'otb')
            ->set('portCount', '8')
            ->set('localLabel', 'OTB-Redirect')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('web.fiber-nodes.index'));

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odc')
            ->set('localLabel', 'ODC-NoRedirect')
            ->call('save')
            ->assertHasErrors(['lossInDb', 'lossOutDb'])
            ->assertNoRedirect();
    }

    /**
     * v0.16.0 Langkah 5 reversed the deliberate Langkah 3 limitation —
     * "Ambil lokasi saya" (and a photo picker + Leaflet map) is now
     * available in create mode too, not only edit.
     */
    public function test_gps_and_photo_capture_available_in_both_create_and_edit_mode(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $createHtml = Livewire::actingAs($this->admin($tenant))->test(FiberNodeForm::class)->html();
        $editHtml = Livewire::actingAs($this->admin($tenant))->test(FiberNodeForm::class, ['fiber_node' => $node])->html();

        $this->assertStringContainsString('Ambil lokasi saya', $createHtml);
        $this->assertStringContainsString('fiberLocationMap(', $createHtml);
        $this->assertStringContainsString('Ambil lokasi saya', $editHtml);
        $this->assertStringNotContainsString('GPS & foto tersedia setelah titik ini disimpan', $createHtml);
    }

    public function test_splitter_section_is_shown_for_splitting_points_odc_and_odp_only(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'otb')
            ->assertDontSee('splitter-ratio-suggestions')
            ->set('nodeType', 'closure')
            ->assertDontSee('splitter-ratio-suggestions')
            ->set('nodeType', 'odc')
            ->assertSee('splitter-ratio-suggestions')
            ->set('nodeType', 'odp')
            ->assertSee('splitter-ratio-suggestions');
    }

    public function test_creating_an_odc_node_with_a_splitter_persists_the_splitter(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odc')
            ->set('localLabel', 'ODC-Splitter')
            ->set('lossInDb', '1.0')
            ->set('lossOutDb', '1.2')
            ->set('splitterRatio', '1:8')
            ->set('splitterModel', 'FBT-8')
            ->call('save')
            ->assertHasNoErrors();

        $node = FiberNode::where('local_label', 'ODC-Splitter')->firstOrFail();
        $this->assertDatabaseHas('splitters', [
            'owner_type' => FiberNode::class,
            'owner_id' => $node->id,
            'ratio' => '1:8',
            'model' => 'FBT-8',
        ]);
    }

    public function test_a_stale_splitter_ratio_is_ignored_when_the_node_is_not_odc(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odc')
            ->set('splitterRatio', '1:16')
            ->set('nodeType', 'otb')
            ->set('portCount', '8')
            ->set('localLabel', 'OTB-NoSplitter')
            ->call('save')
            ->assertHasNoErrors();

        $node = FiberNode::where('local_label', 'OTB-NoSplitter')->firstOrFail();
        $this->assertDatabaseMissing('splitters', ['owner_id' => $node->id, 'owner_type' => FiberNode::class]);
    }

    public function test_photos_are_attached_and_stored_on_disk_only_after_a_successful_create(): void
    {
        Storage::fake('local');
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'otb')
            ->set('localLabel', 'OTB-Photo')
            ->set('portCount', '8')
            ->set('newPhotos', [UploadedFile::fake()->create('titik.jpg', 80, 'image/jpeg')])
            ->call('save')
            ->assertHasNoErrors();

        $node = FiberNode::where('local_label', 'OTB-Photo')->firstOrFail();
        $photo = FiberNodePhoto::where('owner_type', FiberNode::class)->where('owner_id', $node->id)->firstOrFail();
        $this->assertTrue(Storage::disk('local')->exists($photo->photo_path), 'photo file was not written to disk');
    }

    public function test_camera_and_gallery_picks_both_merge_into_the_same_preview_batch(): void
    {
        Storage::fake('local');
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('cameraPhotos', [UploadedFile::fake()->create('cam.jpg', 60, 'image/jpeg')])
            ->assertCount('newPhotos', 1)
            ->assertSet('cameraPhotos', [])
            ->set('galleryPhotos', [UploadedFile::fake()->create('gal.jpg', 60, 'image/jpeg')])
            ->assertCount('newPhotos', 2)
            ->assertSet('galleryPhotos', []);
    }

    public function test_port_count_is_required_for_an_otb_and_the_field_is_hidden_for_closure(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'closure')
            ->assertDontSee('fn-port-count')
            ->set('nodeType', 'otb')
            ->assertSee('fn-port-count')
            ->set('localLabel', 'OTB-NoPort')
            ->call('save')
            ->assertHasErrors('portCount');

        $this->assertDatabaseMissing('fiber_nodes', ['local_label' => 'OTB-NoPort']);
    }

    public function test_creating_an_otb_with_a_port_count_persists_it(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'otb')
            ->set('localLabel', 'OTB-16')
            ->set('portCount', '16')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fiber_nodes', ['local_label' => 'OTB-16', 'port_count' => 16]);
    }

    public function test_a_failed_create_persists_neither_the_node_nor_its_photos(): void
    {
        Storage::fake('local');
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odc') // loss required for ODC, left blank -> save aborts
            ->set('localLabel', 'ODC-Fail')
            ->set('newPhotos', [UploadedFile::fake()->create('titik.jpg', 80, 'image/jpeg')])
            ->call('save')
            ->assertHasErrors(['lossInDb', 'lossOutDb']);

        $this->assertDatabaseMissing('fiber_nodes', ['local_label' => 'ODC-Fail']);
        $this->assertSame(0, Splitter::count());
        $this->assertDatabaseCount('fiber_node_photos', 0);
    }

    public function test_editing_an_odc_node_can_attach_a_splitter(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create([
            'tenant_id' => $tenant->id,
            'node_type' => 'odc',
            'loss_in_db' => 1.0,
            'loss_out_db' => 1.0,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class, ['fiber_node' => $node])
            ->set('splitterRatio', '1:4')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('splitters', ['owner_id' => $node->id, 'owner_type' => FiberNode::class, 'ratio' => '1:4']);
    }

    /**
     * v0.16.0 penutupan — the offline-draft feature (Langkah 3) is pure
     * client-side Alpine + localStorage, but it depends on ONE server
     * contract: save() must dispatch `fiber-node-saved` so the browser
     * listener wipes the saved draft. Langkah 6 (photo pre-save) and
     * Langkah 7 (redirect-to-list) reworked save() heavily — this guards
     * that the event still fires on BOTH create and edit.
     */
    public function test_successful_save_dispatches_fiber_node_saved_so_the_offline_draft_gets_cleared(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'otb')
            ->set('portCount', '8')
            ->set('localLabel', 'OTB-Draft')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('fiber-node-saved');

        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb']);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class, ['fiber_node' => $node])
            ->set('localLabel', 'OTB-Draft-Edited')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('fiber-node-saved');
    }

    /**
     * The draft UI/machinery itself must still be present in the form
     * after all the Langkah 6-13 blade churn (new portCount field, photo
     * picker split, splitter section, map partial, heading rename).
     */
    public function test_offline_draft_machinery_is_still_wired_into_the_form(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id]);

        $createHtml = Livewire::actingAs($this->admin($tenant))->test(FiberNodeForm::class)->html();
        $editHtml = Livewire::actingAs($this->admin($tenant))->test(FiberNodeForm::class, ['fiber_node' => $node])->html();

        foreach ([$createHtml, $editHtml] as $html) {
            $this->assertStringContainsString('saveDraft()', $html);
            $this->assertStringContainsString('applyDraft()', $html);
            $this->assertStringContainsString('dismissDraft()', $html);
            $this->assertStringContainsString("addEventListener('fiber-node-saved'", $html);
            $this->assertStringContainsString('Draft tersimpan ditemukan.', $html);
            // the debounced draft-writer is bound to the form
            $this->assertStringContainsString('x-on:input.debounce.500ms="saveDraft()"', $html);
        }

        // per-form localStorage key: distinct for new vs a specific node
        $this->assertStringContainsString('fiber_node_draft_new', $createHtml);
        $this->assertStringContainsString('fiber_node_draft_'.$node->id, $editHtml);
    }

    /* ---------- v0.16.1 Poin D — ODP di form "Titik Baru" ---------- */

    public function test_odp_option_is_only_offered_on_create_not_edit(): void
    {
        $tenant = Tenant::factory()->create();
        $node = FiberNode::factory()->create(['tenant_id' => $tenant->id, 'node_type' => 'otb']);

        $createHtml = Livewire::actingAs($this->admin($tenant))->test(FiberNodeForm::class)->html();
        $editHtml = Livewire::actingAs($this->admin($tenant))->test(FiberNodeForm::class, ['fiber_node' => $node])->html();

        $this->assertStringContainsString('<option value="odp">', $createHtml);
        $this->assertStringNotContainsString('<option value="odp">', $editHtml);
    }

    public function test_creating_an_odp_writes_to_the_odps_table_and_provisions_ports(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odp')
            ->set('odpCode', 'ODP-KLW-01')
            ->set('odpName', 'ODP Kaliwungu 01')
            ->set('portCount', '8')
            ->set('lossInDb', '0.9')
            ->set('lossOutDb', '1.1')
            ->set('latitude', '-6.9000')
            ->set('longitude', '109.6500')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('web.fiber-nodes.index'));

        $odp = Odp::withoutGlobalScopes()->where('code', 'ODP-KLW-01')->firstOrFail();
        $this->assertSame($tenant->id, $odp->tenant_id);
        $this->assertNull($odp->reseller_id);
        $this->assertSame(8, $odp->total_ports);
        $this->assertSame('0.90', (string) $odp->loss_in_db);
        $this->assertSame('1.10', (string) $odp->loss_out_db);
        // provisionPorts() ran
        $this->assertSame(8, $odp->ports()->count());
        // NOT written to fiber_nodes
        $this->assertDatabaseMissing('fiber_nodes', ['local_label' => 'ODP Kaliwungu 01']);
    }

    public function test_creating_an_odp_without_loss_values_is_rejected_consistent_with_odpedit(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odp')
            ->set('odpCode', 'ODP-NOLOSS')
            ->set('odpName', 'ODP tanpa loss')
            ->set('portCount', '8')
            ->set('latitude', '-6.9')
            ->set('longitude', '109.65')
            ->call('save')
            ->assertHasErrors(['lossInDb', 'lossOutDb']);

        $this->assertDatabaseMissing('odps', ['code' => 'ODP-NOLOSS']);
    }

    public function test_creating_an_odp_requires_code_name_coords_and_port_count(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odp')
            ->set('lossInDb', '1')
            ->set('lossOutDb', '1')
            ->call('save')
            ->assertHasErrors(['odpCode', 'odpName', 'latitude', 'longitude']);

        $this->assertSame(0, Odp::withoutGlobalScopes()->count());
    }

    public function test_creating_an_odp_rejects_a_duplicate_code_within_the_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        Odp::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ODP-DUP', 'reseller_id' => null]);

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odp')
            ->set('odpCode', 'ODP-DUP')
            ->set('odpName', 'ODP duplikat')
            ->set('portCount', '8')
            ->set('lossInDb', '1')
            ->set('lossOutDb', '1')
            ->set('latitude', '-6.9')
            ->set('longitude', '109.65')
            ->call('save')
            ->assertHasErrors('odpCode');

        $this->assertSame(1, Odp::withoutGlobalScopes()->where('code', 'ODP-DUP')->count());
    }

    public function test_creating_an_odp_with_a_splitter_persists_it_on_the_odp(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odp')
            ->set('odpCode', 'ODP-SPL')
            ->set('odpName', 'ODP dengan splitter')
            ->set('portCount', '16')
            ->set('lossInDb', '1')
            ->set('lossOutDb', '1')
            ->set('latitude', '-6.9')
            ->set('longitude', '109.65')
            ->set('splitterRatio', '1:16')
            ->call('save')
            ->assertHasNoErrors();

        $odp = Odp::withoutGlobalScopes()->where('code', 'ODP-SPL')->firstOrFail();
        $this->assertDatabaseHas('splitters', [
            'owner_type' => Odp::class,
            'owner_id' => $odp->id,
            'ratio' => '1:16',
        ]);
    }

    public function test_odp_form_shows_code_name_and_splitter_fields_when_type_is_odp(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(FiberNodeForm::class)
            ->set('nodeType', 'odp')
            ->assertSee('Kode ODP')
            ->assertSee('Nama ODP')
            ->assertSee('Jumlah Port ODP')
            ->assertSee('Splitter')
            ->assertDontSee('Jumlah Port OTB');
    }
}
