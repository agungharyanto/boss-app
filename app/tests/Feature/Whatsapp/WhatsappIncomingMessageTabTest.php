<?php

namespace Tests\Feature\Whatsapp;

use App\Livewire\Whatsapp\WhatsappGatewayIndex;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappIncomingMessage;
use App\Support\ResellerContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.13.1 perluasan — tab "Pesan Masuk" di WhatsappGatewayIndex, SEKARANG
 * visible untuk admin MAUPUN reseller (bukan admin-only lagi sejak
 * whatsapp_incoming_messages.reseller_id ada, migration
 * 2026_09_14_110000_...). Scoping ditegakkan di QUERY (render()), bukan di
 * blade — reseller HANYA boleh melihat baris `reseller_id` miliknya
 * sendiri, tidak pernah reseller lain maupun baris Direct (`reseller_id`
 * null).
 */
class WhatsappIncomingMessageTabTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Tenant $tenant): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    /**
     * `Livewire::test()` TIDAK menjalankan middleware HTTP `reseller.context`
     * (`ResolveResellerContext`) — bind `ResellerContext` singleton
     * langsung, pola persis `WhatsappSessionCreationTest::
     * test_reseller_owner_can_create_their_own_session()` (dan
     * `ResellerTaxPolicyIndexLivewireTest`, sumber pola aslinya).
     */
    private function resellerOwner(Tenant $tenant, Reseller $reseller): User
    {
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        app(ResellerContext::class)->set($reseller);

        return $owner;
    }

    public function test_admin_sees_incoming_messages_in_the_tab(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        WhatsappIncomingMessage::create([
            'session_key' => 'direct',
            'reseller_id' => null,
            'sender_phone' => '087884374939',
            'chat_jid' => '6287884374939@s.whatsapp.net',
            'message_id' => 'MSG-1',
            'text' => 'Halo, ini pesan test dari pelanggan',
            'push_name' => 'Agung Test',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'pesan-masuk')
            ->assertOk()
            ->assertSee('087884374939')
            ->assertSee('Direct (ISP A)')
            ->assertSee('Halo, ini pesan test dari pelanggan')
            ->assertSee('Agung Test');
    }

    public function test_empty_state_shows_belum_ada_pesan_masuk(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'pesan-masuk')
            ->assertSee('Belum ada pesan masuk.');
    }

    public function test_admin_sees_reseller_owned_message_resolved_to_reseller_name(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Reseller Uji Coba']);
        $admin = $this->admin($tenant);
        WhatsappIncomingMessage::create([
            'session_key' => (string) $reseller->id,
            'reseller_id' => $reseller->id,
            'sender_phone' => '081234567890',
            'chat_jid' => '6281234567890@s.whatsapp.net',
            'message_id' => 'MSG-2',
            'text' => 'Pesan dari pelanggan reseller',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'pesan-masuk')
            ->assertSee('Reseller Uji Coba');
    }

    // --- placeholder "Nomor tidak tersedia" (fix bug LID, 2026-09-15 —
    //     badge "LID" kecil DIGANTI placeholder ini, raw LID digit TIDAK
    //     PERNAH ditampilkan ke user meski tetap tersimpan di DB) ---

    public function test_placeholder_is_shown_and_raw_lid_digits_never_render_for_a_message_with_is_lid_true(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        WhatsappIncomingMessage::create([
            'session_key' => 'direct',
            'reseller_id' => null,
            'sender_phone' => '44435932971043',
            'is_lid' => true,
            'chat_jid' => '44435932971043@lid',
            'message_id' => 'MSG-LID-1',
            'text' => 'Pesan dari kontak LID',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'pesan-masuk')
            ->assertSee('Nomor tidak tersedia')
            // Digit LID mentah TIDAK PERNAH boleh terlihat seperti nomor
            // telepon di UI — ini persis masalah yang mau ditutup.
            ->assertDontSee('44435932971043');

        // Nilai mentahnya TETAP tersimpan di DB untuk debugging developer —
        // cuma tidak ditampilkan ke user.
        $this->assertDatabaseHas('whatsapp_incoming_messages', [
            'message_id' => 'MSG-LID-1',
            'sender_phone' => '44435932971043',
            'is_lid' => true,
        ]);
    }

    public function test_placeholder_is_not_shown_for_a_normal_phone_number_message(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        WhatsappIncomingMessage::create([
            'session_key' => 'direct',
            'reseller_id' => null,
            'sender_phone' => '087884374939',
            'is_lid' => false,
            'chat_jid' => '6287884374939@s.whatsapp.net',
            'message_id' => 'MSG-NON-LID-1',
            'text' => 'Pesan dari nomor biasa',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'pesan-masuk')
            ->assertSee('087884374939')
            ->assertDontSee('Nomor tidak tersedia');
    }

    /**
     * $resellerFilter DISHARE dengan tab Pesan Keluar (reuse PERSIS pola yang
     * sama, satu properti) — set lewat filter di sini juga valid.
     */
    public function test_admin_reseller_filter_narrows_the_list(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Reseller X']);
        $admin = $this->admin($tenant);
        WhatsappIncomingMessage::create([
            'session_key' => 'direct', 'reseller_id' => null, 'sender_phone' => '081100000001', 'chat_jid' => 'x@s.whatsapp.net',
            'message_id' => 'MSG-DIRECT', 'text' => 'pesan direct', 'received_at' => now(),
        ]);
        WhatsappIncomingMessage::create([
            'session_key' => (string) $reseller->id, 'reseller_id' => $reseller->id, 'sender_phone' => '081100000002', 'chat_jid' => 'y@s.whatsapp.net',
            'message_id' => 'MSG-RESELLER', 'text' => 'pesan reseller', 'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'pesan-masuk')
            ->set('resellerFilter', $reseller->id)
            ->assertSee('081100000002')
            ->assertDontSee('081100000001');
    }

    public function test_reseller_sees_the_pesan_masuk_tab_button(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = $this->resellerOwner($tenant, $reseller);

        Livewire::actingAs($owner)
            ->test(WhatsappGatewayIndex::class)
            ->assertSee('Pesan Masuk');
    }

    /**
     * Regresi bug nyata (dilaporkan Agung 2026-09-15): tab "Pesan Masuk"
     * ADA di load awal, tapi HILANG setelah pindah tab lewat Livewire (klik
     * "Template Pesan"/"Pesan Keluar"), dan tetap hilang walau balik lagi —
     * cuma pulih dengan full page reload.
     *
     * Akar masalah (dikonfirmasi `php artisan route:list -vv`): route
     * internal Livewire (`POST .../livewire/update`) TIDAK PERNAH dibungkus
     * middleware `reseller.context` (yang cuma jalan di route GET awal
     * `/whatsapp-gateway`) — jadi App\Support\ResellerContext (singleton
     * container) genuinely KOSONG lagi di setiap request Livewire
     * SETELAH request GET pertama, di PRODUKSI SUNGGUHAN.
     *
     * `Livewire::test()` yang biasa TIDAK bisa mereproduksi ini — container
     * (dan singleton ResellerContext di dalamnya) tetap SATU instance yang
     * sama sepanjang satu method test PHPUnit, beda dari produksi nyata
     * (setiap request HTTP = container baru). Test ini secara eksplisit
     * mereset ResellerContext DI TENGAH pengujian (mensimulasikan persis
     * apa yang genuinely terjadi di request Livewire kedua produksi) untuk
     * membuktikan fix (resolve $resellerId SEKALI di mount(), simpan
     * sebagai public property — Livewire mem-persist property lewat
     * hydrate/dehydrate, bukan lewat middleware) benar-benar menutup bug
     * ini, bukan cuma "kebetulan lolos" karena container test tidak pernah
     * di-reset.
     */
    public function test_pesan_masuk_tab_survives_reseller_context_reset_between_livewire_requests(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = $this->resellerOwner($tenant, $reseller);

        $component = Livewire::actingAs($owner)
            ->test(WhatsappGatewayIndex::class)
            ->assertSee('Pesan Masuk');

        // Simulasikan persis apa yang genuinely terjadi di request Livewire
        // KEDUA di produksi — middleware `reseller.context` tidak pernah
        // jalan lagi, jadi singleton-nya balik ke default kosong.
        app(ResellerContext::class)->set(null);

        $component->set('tab', 'template')
            ->assertSee('Pesan Masuk')
            ->assertSee('Template Pesan');

        // Reset lagi sebelum interaksi ketiga — membuktikan ini tahan
        // berulang kali, bukan cuma sekali kebetulan lolos.
        app(ResellerContext::class)->set(null);

        $component->set('tab', 'antrian')
            ->assertSee('Pesan Masuk')
            ->assertSee('Pesan Keluar');

        app(ResellerContext::class)->set(null);

        $component->set('tab', 'pesan-masuk')
            ->assertOk()
            ->assertSee('Pesan Masuk')
            ->assertSee('Belum ada pesan masuk.');
    }

    public function test_reseller_sees_their_own_incoming_messages(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = $this->resellerOwner($tenant, $reseller);
        WhatsappIncomingMessage::create([
            'session_key' => (string) $reseller->id, 'reseller_id' => $reseller->id,
            'sender_phone' => '081200000001', 'chat_jid' => 'a@s.whatsapp.net',
            'message_id' => 'MSG-OWN', 'text' => 'pesan milik reseller ini', 'received_at' => now(),
        ]);

        Livewire::actingAs($owner)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'pesan-masuk')
            ->assertOk()
            ->assertSee('081200000001')
            ->assertSee('pesan milik reseller ini');
    }

    /**
     * Isolasi lintas-reseller nyata — reseller A tidak boleh melihat baris
     * milik reseller B, walau keduanya sama-sama punya `reseller_id`
     * (bukan cuma direct/null yang perlu diuji).
     */
    public function test_reseller_a_cannot_see_reseller_bs_messages(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $ownerA = $this->resellerOwner($tenant, $resellerA);
        WhatsappIncomingMessage::create([
            'session_key' => (string) $resellerB->id, 'reseller_id' => $resellerB->id,
            'sender_phone' => '081300000002', 'chat_jid' => 'b@s.whatsapp.net',
            'message_id' => 'MSG-B', 'text' => 'rahasia reseller B', 'received_at' => now(),
        ]);

        Livewire::actingAs($ownerA)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'pesan-masuk')
            ->assertOk()
            ->assertDontSee('rahasia reseller B')
            ->assertDontSee('081300000002');
    }

    /**
     * Baris `reseller_id` NULL (Direct/ISP A) juga tidak boleh bocor ke
     * reseller mana pun.
     */
    public function test_reseller_cannot_see_direct_messages(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = $this->resellerOwner($tenant, $reseller);
        WhatsappIncomingMessage::create([
            'session_key' => 'direct', 'reseller_id' => null,
            'sender_phone' => '089900000000', 'chat_jid' => 'z@s.whatsapp.net',
            'message_id' => 'MSG-SHOULD-NOT-LEAK', 'text' => 'rahasia pelanggan direct', 'received_at' => now(),
        ]);

        Livewire::actingAs($owner)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'pesan-masuk')
            ->assertOk()
            ->assertDontSee('rahasia pelanggan direct')
            ->assertDontSee('089900000000');
    }

    /**
     * Pengetatan nyata: reseller memaksa set $resellerFilter ke id
     * reseller LAIN tetap tidak berpengaruh — query non-admin di
     * render() tidak pernah membaca $resellerFilter sama sekali
     * (match(true) langsung ke cabang "$context->hasReseller()" untuk
     * non-admin, terlepas dari nilai $resellerFilter apa pun).
     */
    public function test_reseller_forcing_reseller_filter_to_another_reseller_has_no_effect(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $ownerA = $this->resellerOwner($tenant, $resellerA);
        WhatsappIncomingMessage::create([
            'session_key' => (string) $resellerB->id, 'reseller_id' => $resellerB->id,
            'sender_phone' => '081400000002', 'chat_jid' => 'c@s.whatsapp.net',
            'message_id' => 'MSG-B2', 'text' => 'rahasia reseller B lagi', 'received_at' => now(),
        ]);
        WhatsappIncomingMessage::create([
            'session_key' => (string) $resellerA->id, 'reseller_id' => $resellerA->id,
            'sender_phone' => '081400000001', 'chat_jid' => 'd@s.whatsapp.net',
            'message_id' => 'MSG-A2', 'text' => 'pesan milik A', 'received_at' => now(),
        ]);

        Livewire::actingAs($ownerA)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'pesan-masuk')
            ->set('resellerFilter', $resellerB->id)
            ->assertOk()
            ->assertDontSee('rahasia reseller B lagi')
            ->assertSee('pesan milik A');
    }

    public function test_pesan_masuk_tab_has_no_aksi_retry_column(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        WhatsappIncomingMessage::create([
            'session_key' => 'direct', 'reseller_id' => null, 'sender_phone' => '081234567890', 'chat_jid' => 'x@s.whatsapp.net',
            'message_id' => 'MSG-3', 'text' => 'pesan test', 'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'pesan-masuk')
            ->assertDontSee('retryMessage');
    }

    public function test_existing_antrian_tab_is_unaffected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'antrian')
            ->assertOk()
            ->assertSee('Belum ada pesan.');
    }
}
