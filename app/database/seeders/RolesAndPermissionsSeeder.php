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
        $this->seedRegistrationPermissions();
        $this->seedResellerPermissions();
        $this->seedTaxEnginePermissions();
        $this->seedInvoicingPermissions();
        $this->seedPaymentGatewaySettingsPermissions();
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

    /**
     * Permission modul Registration & Referral (v0.3.0). Hanya role yang
     * benar-benar melakukan registrasi pelanggan di lapangan/kantor yang
     * dapat permission ini: admin (super_admin), sales (sales_internal),
     * teknisi, dan freelance (sales_freelance) — bukan role administratif
     * lain seperti billing/finance/noc.
     */
    private function seedRegistrationPermissions(): void
    {
        Permission::firstOrCreate(['name' => 'register-customer', 'guard_name' => 'web']);

        foreach (['super_admin', 'sales_internal', 'teknisi', 'sales_freelance'] as $role) {
            Role::findByName($role, 'web')->givePermissionTo('register-customer');
        }
    }

    /**
     * Permission modul Multi-Tenant Reseller (v0.3.2). Reseller sendiri
     * hanya boleh dikelola (create/update/delete + kelola staff) oleh
     * super_admin — reseller owner/staff diotorisasi lewat keanggotaan
     * reseller_users mereka sendiri (lihat ResellerPolicy/CustomerPolicy/
     * ResellerPackagePricingPolicy), bukan lewat permission Spatie ini,
     * karena mereka adalah user eksternal (bisnis reseller), bukan staff
     * internal ISP dengan salah satu dari 8 role di atas.
     */
    private function seedResellerPermissions(): void
    {
        $permissions = ['resellers.view', 'resellers.manage'];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::findByName('super_admin', 'web')->givePermissionTo($permissions);
    }

    /**
     * Permission modul Regulatory Tax Engine (v0.3.3). Sama seperti
     * resellers.* di v0.3.2: hanya super_admin yang dapat permission ini
     * ("admin-only untuk semua action" per TaxComponentPolicy, "admin: full
     * akses" per ResellerTaxPolicyPolicy) — meski role billing/finance ada,
     * mereka TIDAK otomatis dapat akses tax engine, mengikuti pola ketat
     * yang sama dengan reseller management. Reseller owner/staff
     * diotorisasi lewat keanggotaan reseller_users mereka sendiri (lihat
     * ResellerTaxPolicyPolicy), bukan lewat permission Spatie ini.
     */
    private function seedTaxEnginePermissions(): void
    {
        $permissions = [
            'tax_components.view',
            'tax_components.manage',
            'reseller_tax_policies.view',
            'reseller_tax_policies.manage',
            'tax_ledger.view',
            'remittance_summary.view',
            'remittance_summary.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::findByName('super_admin', 'web')->givePermissionTo($permissions);
    }

    /**
     * Permission modul Invoicing Core (v0.3.4). Beda dari pola
     * super_admin-only yang ketat di reseller/tax-engine: `billing` role
     * (ada sejak v0.1.0, belum pernah dapat permission apa pun sampai
     * sprint ini) juga diberi akses — generate/lihat/ubah status invoice
     * adalah pekerjaan operasional harian staff billing, beda konteks
     * dengan konfigurasi reseller/kebijakan pajak yang memang keputusan
     * level admin. Reseller owner/staff tetap diotorisasi lewat
     * keanggotaan reseller_users mereka sendiri (lihat SubscriptionPolicy/
     * InvoicePolicy), read-only, bukan lewat permission Spatie ini.
     */
    private function seedInvoicingPermissions(): void
    {
        $permissions = [
            'subscriptions.view',
            'subscriptions.manage',
            'invoices.view',
            'invoices.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (['super_admin', 'billing'] as $role) {
            Role::findByName($role, 'web')->givePermissionTo($permissions);
        }
    }

    /**
     * Permission modul Payment Gateway Settings (v0.3.5 Fase H). Strictly
     * super_admin-only — same posture as resellers.* / tax_components.* — this
     * holds the actual Xendit API secret/webhook token, a security-critical
     * credential, not an operational-billing concern like invoices.*.
     * `billing` role can create/view payments (invoices.*) but must NOT be
     * able to see/change which channels are enabled or rotate credentials.
     */
    private function seedPaymentGatewaySettingsPermissions(): void
    {
        $permissions = ['payment_gateway_settings.view', 'payment_gateway_settings.manage'];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::findByName('super_admin', 'web')->givePermissionTo($permissions);
    }
}
