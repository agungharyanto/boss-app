<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Livewire\Whatsapp\WhatsappGatewayIndex;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageTemplate;
use App\Support\ResellerContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regresi bug nyata kedua (dilaporkan Agung 2026-09-15, setelah fix tab
 * "Pesan Masuk"): reseller klik "Hubungkan Nomor WhatsApp Reseller" -> 403
 * "Tidak terikat ke reseller manapun" — dikonfirmasi via reproduksi HTTP
 * NYATA (curl langsung ke endpoint Livewire update, sama pola bug tab
 * sebelumnya) SEBELUM fix ini ditulis.
 *
 * Akar masalah KELAS SAMA dengan tab "Pesan Masuk": createSession()/
 * editTemplate()/saveTemplate()/resetTemplateToDefault() masing-masing
 * memanggil app(ResellerContext::class) LANGSUNG di dalam method-nya
 * sendiri — SETIAP wire:click action (bukan cuma tab switch) adalah
 * request Livewire (`POST .../livewire/update`) yang tidak pernah
 * dibungkus middleware `reseller.context`, jadi
 * app(ResellerContext::class)->hasReseller() SELALU false, bahkan pada
 * KLIK PERTAMA — beda dari bug tab yang baru muncul di interaksi KEDUA.
 *
 * Fix: semua 4 method baca $this->resellerId (di-resolve sekali di
 * mount(), fix v0.13.1 sebelumnya) — bukan re-resolve dari container.
 *
 * GOTCHA METODOLOGI TEST (ditemukan sendiri saat menulis test ini —
 * percobaan pertama LOLOS walau bug SENGAJA dikembalikan): `Livewire::
 * test()` membuat komponen (mount() jalan sekali) LALU `->call()` method
 * aksi — keduanya terjadi dalam SATU PHP process/container test yang
 * SAMA, jadi app(ResellerContext::class) di dalam action method tetap
 * melihat context yang sama yang di-bind sebelum test dimulai — TIDAK
 * pernah benar-benar mensimulasikan "request Livewire kedua, container
 * baru, context kosong lagi" yang terjadi di PRODUKSI NYATA. Setiap test
 * di bawah SECARA EKSPLISIT `app(ResellerContext::class)->set(null)`
 * SETELAH komponen di-mount (mount() sudah menangkap $resellerId lebih
 * dulu) tapi SEBELUM `->call()` aksi — persis mensimulasikan request
 * Livewire kedua yang genuinely terjadi. Tanpa reset ini, test bisa
 * "lolos" walau bug-nya masih ada (false positive) — pelajaran yang sama
 * seperti WhatsappIncomingMessageTabTest::
 * test_pesan_masuk_tab_survives_reseller_context_reset_between_livewire_requests.
 */
class WhatsappGatewayActionsResellerContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config(['services.whatsapp_gateway.url' => 'http://whatsapp-gateway-test']);

        Http::fake([
            'whatsapp-gateway-test/*' => Http::response(['qr_code_data' => null], 200),
        ]);
    }

    private function resellerOwner(Tenant $tenant, Reseller $reseller): User
    {
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        app(ResellerContext::class)->set($reseller);

        return $owner;
    }

    /**
     * Mount komponen (menangkap $resellerId dari context yang SUDAH
     * di-bind), LALU reset context ke kosong — mensimulasikan persis
     * request Livewire KEDUA di produksi (aksi), yang genuinely tidak
     * pernah lewat middleware reseller.context lagi.
     */
    private function mountThenSimulateFreshLivewireRequest(User $actingAs): Testable
    {
        $component = Livewire::actingAs($actingAs)->test(WhatsappGatewayIndex::class);
        app(ResellerContext::class)->set(null);

        return $component;
    }

    public function test_reseller_can_create_session_via_mount_resolved_context(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = $this->resellerOwner($tenant, $reseller);

        $this->mountThenSimulateFreshLivewireRequest($owner)
            ->call('createSession')
            ->assertHasNoErrors()
            ->assertStatus(200);

        $this->assertDatabaseHas('whatsapp_sessions', [
            'tenant_id' => $tenant->id,
            'reseller_id' => $reseller->id,
            'status' => 'qr_pending',
        ]);
    }

    public function test_reseller_can_edit_template_via_mount_resolved_context(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = $this->resellerOwner($tenant, $reseller);
        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => $reseller->id,
            'event_type' => WhatsappEventType::PaymentReceived,
            'content' => 'Override milik reseller ini',
        ]);

        $this->mountThenSimulateFreshLivewireRequest($owner)
            ->call('editTemplate', WhatsappEventType::PaymentReceived->value)
            ->assertSet('editingContent', 'Override milik reseller ini')
            ->assertSet('showTemplateForm', true);
    }

    public function test_reseller_can_save_template_via_mount_resolved_context(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = $this->resellerOwner($tenant, $reseller);

        $this->mountThenSimulateFreshLivewireRequest($owner)
            ->set('editingEventType', WhatsappEventType::PaymentReceived->value)
            ->set('editingContent', 'Template baru dari reseller')
            ->set('editingIsActive', true)
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('whatsapp_message_templates', [
            'tenant_id' => $tenant->id,
            'reseller_id' => $reseller->id,
            'event_type' => WhatsappEventType::PaymentReceived->value,
            'content' => 'Template baru dari reseller',
        ]);
    }

    public function test_reseller_can_reset_template_to_default_via_mount_resolved_context(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = $this->resellerOwner($tenant, $reseller);
        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::PaymentReceived,
            'content' => 'Default ISP',
        ]);
        $override = WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => $reseller->id,
            'event_type' => WhatsappEventType::PaymentReceived,
            'content' => 'Override yang mau direset',
        ]);

        $this->mountThenSimulateFreshLivewireRequest($owner)
            ->call('resetTemplateToDefault', WhatsappEventType::PaymentReceived->value)
            ->assertHasNoErrors();

        $this->assertModelMissing($override);
    }

    // --- Isolasi lintas-reseller (bukan cuma "tidak 403", tapi juga
    //     "tidak bisa akses/ubah data reseller lain") ---

    public function test_reseller_b_creating_a_session_via_livewire_action_never_gets_attributed_to_reseller_a(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $ownerB = $this->resellerOwner($tenant, $resellerB);

        $this->mountThenSimulateFreshLivewireRequest($ownerB)
            ->call('createSession')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('whatsapp_sessions', ['reseller_id' => $resellerB->id]);
        $this->assertDatabaseMissing('whatsapp_sessions', ['reseller_id' => $resellerA->id]);
    }

    public function test_reseller_a_and_b_each_see_only_their_own_override_when_editing_the_same_event_type(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => $resellerA->id,
            'event_type' => WhatsappEventType::PaymentReceived,
            'content' => 'Punya A — rahasia',
        ]);

        $ownerB = $this->resellerOwner($tenant, $resellerB);

        // Reseller B edit event_type yang SAMA — harus dapat template
        // KOSONG (belum punya override sendiri), BUKAN konten milik A.
        $this->mountThenSimulateFreshLivewireRequest($ownerB)
            ->call('editTemplate', WhatsappEventType::PaymentReceived->value)
            ->assertSet('editingContent', '')
            ->assertDontSee('Punya A — rahasia');
    }

    public function test_reseller_b_saving_a_template_does_not_touch_reseller_as_override(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $templateA = WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => $resellerA->id,
            'event_type' => WhatsappEventType::PaymentReceived,
            'content' => 'Punya A — tidak boleh berubah',
        ]);

        $ownerB = $this->resellerOwner($tenant, $resellerB);

        $this->mountThenSimulateFreshLivewireRequest($ownerB)
            ->set('editingEventType', WhatsappEventType::PaymentReceived->value)
            ->set('editingContent', 'Punya B')
            ->set('editingIsActive', true)
            ->call('saveTemplate')
            ->assertHasNoErrors();

        // Baris A sama sekali tidak tersentuh — baris BARU dibuat untuk B.
        $this->assertSame('Punya A — tidak boleh berubah', $templateA->fresh()->content);
        $this->assertDatabaseHas('whatsapp_message_templates', [
            'reseller_id' => $resellerB->id,
            'content' => 'Punya B',
        ]);
    }

    public function test_reseller_b_cannot_reset_reseller_as_override_to_default(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::PaymentReceived,
            'content' => 'Default ISP',
        ]);
        $overrideA = WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => $resellerA->id,
            'event_type' => WhatsappEventType::PaymentReceived,
            'content' => 'Override A — tidak boleh direset paksa B',
        ]);

        $ownerB = $this->resellerOwner($tenant, $resellerB);

        // resetTemplateToDefault() TIDAK menerima parameter reseller_id
        // target — ia SELALU beroperasi atas $this->resellerId milik
        // acting user sendiri (resellerB), jadi baris A dijamin tidak
        // tersentuh secara struktural, bukan cuma kebetulan.
        $this->mountThenSimulateFreshLivewireRequest($ownerB)
            ->call('resetTemplateToDefault', WhatsappEventType::PaymentReceived->value)
            ->assertHasNoErrors();

        $this->assertModelExists($overrideA);
        $this->assertSame('Override A — tidak boleh direset paksa B', $overrideA->fresh()->content);
    }

    public function test_admin_actions_are_unaffected_by_this_fix(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->call('createSession')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('whatsapp_sessions', [
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
        ]);
    }
}
