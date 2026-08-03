<?php

namespace Database\Seeders;

use App\Enums\ResellerPackagePricingStatus;
use App\Enums\ResellerStatus;
use App\Enums\ResellerUserRole;
use App\Models\Customer;
use App\Models\Reseller;
use App\Models\ResellerPackagePricing;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Dummy reseller data for VISUAL verification of the v0.3.2 UI only —
 * local environment exclusively (guarded below, RULE BOSS-005 posture: this
 * must never seed a real deployment). Owner/staff passwords are generated
 * fresh on every run and printed once to the console — never hardcoded here
 * or persisted anywhere in plaintext.
 *
 * No subscriptions dummy data — the `subscriptions` table doesn't exist yet
 * (see CHANGELOG.md v0.3.2 / docs/ROADMAP.md: planned for v0.3.4).
 */
class ResellerDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->error('ResellerDemoSeeder only runs in the local environment — aborting.');

            return;
        }

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'isp-demo'],
            ['name' => 'ISP Demo', 'is_active' => true]
        );

        $reseller = Reseller::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'reseller-demo-a'],
            [
                'name' => 'Reseller Demo A',
                'email' => 'contact@reseller-demo-a.test',
                'phone' => '081200000001',
                'address' => 'Jl. Reseller Demo No. 1',
                'status' => ResellerStatus::Active,
            ]
        );

        $ownerPassword = Str::password(14);
        $staffPassword = Str::password(14);

        $owner = User::firstOrCreate(
            ['email' => 'owner@reseller-demo-a.test'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Reseller Demo A - Owner',
                'password' => Hash::make($ownerPassword),
                'email_verified_at' => now(),
            ]
        );

        $staff = User::firstOrCreate(
            ['email' => 'staff@reseller-demo-a.test'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Reseller Demo A - Staff',
                'password' => Hash::make($staffPassword),
                'email_verified_at' => now(),
            ]
        );

        $reseller->users()->syncWithoutDetaching([
            $owner->id => ['role' => ResellerUserRole::Owner->value, 'status' => 'active'],
            $staff->id => ['role' => ResellerUserRole::Staff->value, 'status' => 'active'],
        ]);

        $standardPackage = ResellerPackagePricing::firstOrCreate(
            ['reseller_id' => $reseller->id, 'name' => 'Paket 20 Mbps (Reseller Demo A)'],
            [
                'description' => 'Paket standar — harga jual reseller berbeda dari harga dasar ISP.',
                'price' => 165000,
                'is_custom' => false,
                'status' => ResellerPackagePricingStatus::Active,
            ]
        );

        $customPackage = ResellerPackagePricing::firstOrCreate(
            ['reseller_id' => $reseller->id, 'name' => 'Bundle Internet + Netflix'],
            [
                'description' => 'Bundle custom di luar paket standar.',
                'price' => 275000,
                'is_custom' => true,
                'status' => ResellerPackagePricingStatus::Active,
            ]
        );

        $resellerCustomers = Customer::factory()
            ->count(3)
            ->for($tenant)
            ->sequence(
                ['name' => 'Rina Kusuma (Reseller Demo A)'],
                ['name' => 'Budi Santoso (Reseller Demo A)'],
                ['name' => 'Sari Wulandari (Reseller Demo A)'],
            )
            ->create(['reseller_id' => $reseller->id]);

        $directCustomer = Customer::factory()->for($tenant)->create([
            'reseller_id' => null,
            'name' => 'Andi Direct (Customer ISP langsung)',
        ]);

        $this->command?->info('== ResellerDemoSeeder selesai (LOCAL ONLY) ==');
        $this->command?->info("Reseller: {$reseller->name} (id={$reseller->id}, slug={$reseller->slug})");
        $this->command?->newLine();
        $this->command?->warn('Simpan kredensial berikut SEKARANG — tidak akan ditampilkan lagi:');
        $this->command?->line("  Owner : {$owner->email} / {$ownerPassword}");
        $this->command?->line("  Staff : {$staff->email} / {$staffPassword}");
        $this->command?->newLine();
        $this->command?->info("Package pricing: '{$standardPackage->name}' (standar), '{$customPackage->name}' (custom)");
        $this->command?->info('Customer milik reseller: '.$resellerCustomers->pluck('name')->implode(', '));
        $this->command?->info("Customer direct (reseller_id null): {$directCustomer->name}");
    }
}
