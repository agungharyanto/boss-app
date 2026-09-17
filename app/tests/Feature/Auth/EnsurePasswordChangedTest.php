<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\ChangePassword;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.22.6 — EnsurePasswordChanged (middleware `password.changed`), dipasang
 * HANYA di grup route `['auth', 'admin.panel']` (routes/web.php). Cakupan:
 * staff dipaksa halaman ganti password saat login pertama (password
 * random-generated, StaffService::create()), tidak bisa akses halaman lain
 * sebelum ganti, bisa akses normal setelah ganti, dan jalur login Referrer
 * sama sekali tidak terpengaruh.
 */
class EnsurePasswordChangedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function staffWithFlag(bool $mustChangePassword, string $password = 'oldpassword123'): User
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make($password),
            'must_change_password' => $mustChangePassword,
        ]);
        $user->assignRole('noc');

        return $user;
    }

    public function test_staff_with_must_change_password_is_redirected_to_change_password_page(): void
    {
        $staff = $this->staffWithFlag(true);

        $this->actingAs($staff)->get('/dashboard')
            ->assertRedirect(route('web.password.change'));
    }

    public function test_staff_with_must_change_password_cannot_reach_other_admin_pages(): void
    {
        $staff = $this->staffWithFlag(true);
        $staff->assignRole('superadmin');

        $this->actingAs($staff)->get('/staff')
            ->assertRedirect(route('web.password.change'));
    }

    public function test_staff_with_must_change_password_can_reach_the_change_password_page_itself(): void
    {
        $staff = $this->staffWithFlag(true);

        $this->actingAs($staff)->get('/change-password')->assertOk();
    }

    public function test_staff_without_the_flag_reaches_the_dashboard_normally(): void
    {
        $staff = $this->staffWithFlag(false);

        $this->actingAs($staff)->get('/dashboard')->assertOk();
    }

    public function test_submitting_the_correct_current_password_clears_the_flag_and_redirects_to_dashboard(): void
    {
        $staff = $this->staffWithFlag(true, 'oldpassword123');

        Livewire::actingAs($staff)
            ->test(ChangePassword::class)
            ->set('currentPassword', 'oldpassword123')
            ->set('password', 'BrandNewPass12345')
            ->set('password_confirmation', 'BrandNewPass12345')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('web.dashboard'));

        $staff->refresh();
        $this->assertFalse($staff->must_change_password);
        $this->assertTrue(Hash::check('BrandNewPass12345', $staff->password));

        // Setelah ganti, halaman lain sudah bisa diakses normal — tidak
        // ada lagi redirect ke /change-password.
        $this->actingAs($staff)->get('/dashboard')->assertOk();
    }

    public function test_wrong_current_password_is_rejected_and_flag_stays_true(): void
    {
        $staff = $this->staffWithFlag(true, 'oldpassword123');

        Livewire::actingAs($staff)
            ->test(ChangePassword::class)
            ->set('currentPassword', 'password-yang-salah')
            ->set('password', 'BrandNewPass12345')
            ->set('password_confirmation', 'BrandNewPass12345')
            ->call('submit')
            ->assertHasErrors(['currentPassword']);

        $this->assertTrue($staff->fresh()->must_change_password);
    }

    /**
     * Kasus edge — seharusnya `must_change_password` TIDAK PERNAH true untuk
     * akun Referrer murni (tidak ada jalur yang men-set-nya true selain
     * StaffService::create()/cleanup akun v0.22.6, keduanya operasi staff).
     * Dipaksa true di sini murni untuk membuktikan middleware `password.
     * changed` GENUINELY tidak terpasang di grup `referrer.portal` — bukan
     * cuma kebetulan aman karena datanya selalu false.
     */
    public function test_referrer_login_is_completely_unaffected_even_if_the_flag_is_somehow_true(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'must_change_password' => true,
        ]);
        $referrer = Referrer::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $this->actingAs($referrer->user)->get('/referrer-portal')->assertOk();
    }
}
