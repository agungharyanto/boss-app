<?php

namespace Tests\Feature\Referrers;

use App\Enums\ReferrerType;
use App\Models\CommissionLedger;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReferrerService;
use App\Support\WhatsappPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * v0.22.3 — test unit murni untuk `activate()`/`createAndLinkToStaff()`
 * (dipakai `StaffService`, sudah dites tidak langsung lewat
 * `StaffServiceTest`) — di sini digenapi test yang menyasar service ini
 * secara terisolasi, termasuk edge case yang tidak lewat `StaffService`
 * sama sekali.
 */
class ReferrerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_sets_is_active_true(): void
    {
        $referrer = Referrer::factory()->create(['is_active' => false]);

        $activated = (new ReferrerService)->activate($referrer);

        $this->assertTrue($activated->is_active);
    }

    public function test_deactivate_and_activate_are_idempotent(): void
    {
        $referrer = Referrer::factory()->create(['is_active' => true]);
        $service = new ReferrerService;

        $service->deactivate($referrer);
        $this->assertFalse($referrer->fresh()->is_active);

        // Panggil deactivate() lagi terhadap Referrer yang sudah nonaktif
        // — tidak boleh error, tetap nonaktif.
        $service->deactivate($referrer->fresh());
        $this->assertFalse($referrer->fresh()->is_active);

        $service->activate($referrer->fresh());
        $this->assertTrue($referrer->fresh()->is_active);
    }

    public function test_create_and_link_to_staff_creates_a_referrer_and_links_it(): void
    {
        $tenant = Tenant::factory()->create();
        $staffUser = User::factory()->create(['tenant_id' => $tenant->id, 'is_disabled' => false]);

        $referrer = (new ReferrerService)->createAndLinkToStaff([
            'tenant_id' => $tenant->id,
            'name' => 'Staff Jadi Referrer',
            'phone' => WhatsappPhone::normalize('081234700000'),
            'type' => ReferrerType::Sales->value,
        ], $staffUser);

        $this->assertSame($staffUser->id, $referrer->user_id);
        $this->assertSame('Staff Jadi Referrer', $referrer->name);
        $this->assertSame(ReferrerType::Sales, $referrer->type);
        $this->assertTrue($referrer->is_active);
    }

    /**
     * `is_active` Referrer baru mengikuti status staff yang di-link SAAT
     * DIBUAT — kalau (kasus yang seharusnya tidak pernah genuinely
     * terjadi di alur create staff normal, tapi tetap perlu benar) staff
     * itu sudah `is_disabled=true`, Referrer barunya juga langsung
     * nonaktif, bukan default `true` begitu saja.
     */
    public function test_create_and_link_to_staff_mirrors_the_staff_disabled_status(): void
    {
        $tenant = Tenant::factory()->create();
        $disabledStaff = User::factory()->create(['tenant_id' => $tenant->id, 'is_disabled' => true]);

        $referrer = (new ReferrerService)->createAndLinkToStaff([
            'tenant_id' => $tenant->id,
            'name' => 'Staff Nonaktif',
            'phone' => WhatsappPhone::normalize('081234711111'),
            'type' => ReferrerType::Teknisi->value,
        ], $disabledStaff);

        $this->assertFalse($referrer->is_active);
    }

    /**
     * v0.22.8 (REVISI) — Referrer yang collision ORPHAN (`user_id: null`)
     * DAN genuinely kosong (0 data nyantol): row lama DIHAPUS PERMANEN,
     * lanjut buat Referrer BARU yang fresh untuk staff ini (id BEDA dari
     * yang lama) — TIDAK ADA lagi ReferrerOrphanCollisionException/tombol
     * link manual. Lihat 2 test lain untuk kasus orphan-berisi-data dan
     * taken (keduanya hard block, row lama tidak disentuh).
     */
    public function test_create_and_link_to_staff_replaces_an_empty_orphan_referrer_with_a_fresh_one(): void
    {
        $tenant = Tenant::factory()->create();
        $oldReferrer = Referrer::factory()->create([
            'tenant_id' => $tenant->id,
            'phone' => WhatsappPhone::normalize('081234722222'),
            'user_id' => null,
            'name' => 'Referrer Orphan Kosong',
        ]);
        $staffUser = User::factory()->create(['tenant_id' => $tenant->id]);

        $newReferrer = (new ReferrerService)->createAndLinkToStaff([
            'tenant_id' => $tenant->id,
            'name' => 'Staff Bentrok',
            'phone' => WhatsappPhone::normalize('081234722222'),
            'type' => ReferrerType::Sales->value,
        ], $staffUser);

        $this->assertNotSame($oldReferrer->id, $newReferrer->id);
        $this->assertSame($staffUser->id, $newReferrer->user_id);
        $this->assertSame('Staff Bentrok', $newReferrer->name);
        $this->assertDatabaseMissing('referrers', ['id' => $oldReferrer->id]);
        $this->assertSame(1, Referrer::withoutGlobalScopes()->count());
    }

    /**
     * v0.22.8 (REVISI) — Referrer yang collision ORPHAN TAPI punya data
     * nyantol (commission_ledger) — TIDAK di-auto-hapus, hard block.
     */
    public function test_create_and_link_to_staff_hard_blocks_when_the_orphan_referrer_still_has_linked_data(): void
    {
        $tenant = Tenant::factory()->create();
        $oldReferrer = Referrer::factory()->create([
            'tenant_id' => $tenant->id,
            'phone' => WhatsappPhone::normalize('081234722223'),
            'user_id' => null,
        ]);
        CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id,
            'referrer_id' => $oldReferrer->id,
        ]);
        $staffUser = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sudah pernah dipakai');

        try {
            (new ReferrerService)->createAndLinkToStaff([
                'tenant_id' => $tenant->id,
                'name' => 'Staff Bentrok Ada Ledger',
                'phone' => WhatsappPhone::normalize('081234722223'),
                'type' => ReferrerType::Sales->value,
            ], $staffUser);
        } finally {
            $this->assertDatabaseHas('referrers', ['id' => $oldReferrer->id]);
        }
    }

    /**
     * v0.22.8 — kebalikan test di atas: Referrer yang collision SUDAH
     * terhubung ke user lain — hard block generik, row lama tidak
     * disentuh sama sekali.
     */
    public function test_create_and_link_to_staff_hard_blocks_a_phone_collision_with_a_referrer_already_taken(): void
    {
        $tenant = Tenant::factory()->create();
        $otherUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $takenReferrer = Referrer::factory()->create([
            'tenant_id' => $tenant->id,
            'phone' => WhatsappPhone::normalize('081234733333'),
            'user_id' => $otherUser->id,
        ]);
        $staffUser = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sudah pernah dipakai');

        try {
            (new ReferrerService)->createAndLinkToStaff([
                'tenant_id' => $tenant->id,
                'name' => 'Staff Bentrok Taken',
                'phone' => WhatsappPhone::normalize('081234733333'),
                'type' => ReferrerType::Sales->value,
            ], $staffUser);
        } finally {
            $this->assertDatabaseHas('referrers', ['id' => $takenReferrer->id, 'user_id' => $otherUser->id]);
        }
    }

    /**
     * Nomor HP yang sama TAPI di tenant BERBEDA tidak boleh dianggap
     * bentrok — `referrers.phone` unik PER TENANT, bukan global (beda
     * dari `users.phone` sejak v0.22.2).
     */
    public function test_create_and_link_to_staff_allows_the_same_phone_in_a_different_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        Referrer::factory()->create([
            'tenant_id' => $tenantA->id,
            'phone' => WhatsappPhone::normalize('081234733333'),
            'user_id' => null,
        ]);
        $staffUser = User::factory()->create(['tenant_id' => $tenantB->id]);

        $referrer = (new ReferrerService)->createAndLinkToStaff([
            'tenant_id' => $tenantB->id,
            'name' => 'Staff Tenant Lain',
            'phone' => WhatsappPhone::normalize('081234733333'),
            'type' => ReferrerType::Sales->value,
        ], $staffUser);

        $this->assertSame($tenantB->id, $referrer->tenant_id);
        $this->assertSame(2, Referrer::withoutGlobalScopes()->where('phone', WhatsappPhone::normalize('081234733333'))->count());
    }

    /**
     * `createAndLinkToStaff()` reuse `linkExistingUser()` di baliknya —
     * guard-nya ("User sudah terhubung ke Referrer lain") ikut berlaku di
     * sini juga, tanpa duplikat logic.
     */
    public function test_create_and_link_to_staff_rejects_a_staff_user_already_linked_to_another_referrer(): void
    {
        $tenant = Tenant::factory()->create();
        $staffUser = User::factory()->create(['tenant_id' => $tenant->id]);
        Referrer::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $staffUser->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sudah terhubung ke Referrer lain');

        (new ReferrerService)->createAndLinkToStaff([
            'tenant_id' => $tenant->id,
            'name' => 'Staff Sudah Ter-link',
            'phone' => WhatsappPhone::normalize('081234744444'),
            'type' => ReferrerType::Sales->value,
        ], $staffUser);
    }
}
