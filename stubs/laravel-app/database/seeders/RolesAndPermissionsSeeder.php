<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
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

        $this->seedCustomerCrmPermissions($roles);
    }

    /**
     * Permission modul Customer CRM (v0.2.0). Semua role bisa melihat data
     * pelanggan dan timeline (butuh untuk kerja masing-masing), tapi hanya
     * customer_service dan super_admin yang bisa mengubah data pelanggan
     * dan kontak keluarga.
     */
    private function seedCustomerCrmPermissions(array $allRoles): void
    {
        $viewPermissions = [
            'customers.view',
            'customer_timeline.view',
        ];

        $managePermissions = [
            'customers.manage',
            'customer_contacts.manage',
        ];

        foreach ([...$viewPermissions, ...$managePermissions] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach ($allRoles as $role) {
            Role::findByName($role, 'web')->givePermissionTo($viewPermissions);
        }

        Role::findByName('customer_service', 'web')->givePermissionTo($managePermissions);
        Role::findByName('super_admin', 'web')->givePermissionTo($managePermissions);
    }
}
