<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Role dasar BOSS App, sesuai bab "Hak Akses Pengguna" di dokumen modul.
     * Permission detail per modul ditambahkan bertahap seiring sprint modul
     * yang bersangkutan dibangun (Customer CRM, ACS, Network, dst).
     */
    public function run(): void
    {
        $roles = [
            'super_admin',
            'noc',
            'customer_service',
            'teknisi',
            'billing',
            'sales_internal',
            'sales_freelance',
            'finance',
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
