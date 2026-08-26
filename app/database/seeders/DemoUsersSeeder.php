<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    /**
     * Deliberately does NOT use WithoutModelEvents — this seeder relies on
     * Tenant's creating() hook to auto-generate the uuid column.
     *
     * One demo tenant with one user per role, for manual login testing.
     * Password for every account: "password".
     *
     * Run RolesAndPermissionsSeeder first (this seeder assumes the 9 roles already exist).
     */
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'isp-demo'],
            ['name' => 'ISP Demo', 'is_active' => true]
        );

        $roles = [
            'superadmin',
            'administrator',
            'noc',
            'customer_service',
            'teknisi',
            'billing',
            'sales_internal',
            'sales_freelance',
            'finance',
        ];

        foreach ($roles as $role) {
            $user = User::firstOrCreate(
                ['email' => "{$role}@boss.local"],
                [
                    'tenant_id' => $tenant->id,
                    'name' => ucwords(str_replace('_', ' ', $role)),
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }
    }
}
