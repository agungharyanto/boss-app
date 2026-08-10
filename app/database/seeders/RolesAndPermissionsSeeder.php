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
        $this->seedWhatsappGatewayPermissions();
        $this->seedInstallationPermissions();
        $this->seedNetworkPermissions();
        $this->seedGenieAcsPermissions();
        $this->seedCpeParameterMapPermissions();
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

    /**
     * Permission modul WhatsApp Gateway (v0.4.0). Two separate namespaces,
     * both super_admin-only, same posture as payment_gateway_settings.*:
     * whatsapp_gateway.* covers the ISP-admin overview (all sessions +
     * default templates + combined queue), whatsapp_gateway_settings.*
     * covers the platform-wide rate-limit policy. Reseller owner/staff
     * access their OWN reseller's session/templates/queue instead via
     * reseller_users membership (see WhatsappSessionPolicy /
     * WhatsappMessageTemplatePolicy / WhatsappMessageLogPolicy), never via
     * these Spatie permissions — same pattern as resellers.* / tax engine.
     */
    private function seedWhatsappGatewayPermissions(): void
    {
        $permissions = [
            'whatsapp_gateway.view',
            'whatsapp_gateway.manage',
            'whatsapp_gateway_settings.view',
            'whatsapp_gateway_settings.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::findByName('super_admin', 'web')->givePermissionTo($permissions);
    }

    /**
     * Permission modul Installation / Work Order (v0.5.0). Same
     * super_admin-only posture as resellers.* / tax engine — reseller
     * owner/staff access their OWN reseller's ODPs/technicians/work orders
     * instead via reseller_users membership (see OdpPolicy/TechnicianPolicy/
     * WorkOrderPolicy), never via these Spatie permissions. The existing
     * `teknisi` role (an Agent type used for field registration/commission,
     * unrelated to the new Technician model) deliberately does NOT get
     * these automatically — a technician's own scoped access, if ever
     * needed, is new scope for a later sprint, not assumed here.
     */
    private function seedInstallationPermissions(): void
    {
        $permissions = [
            'odps.view',
            'odps.manage',
            'technicians.view',
            'technicians.manage',
            'work_orders.view',
            'work_orders.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::findByName('super_admin', 'web')->givePermissionTo($permissions);
    }

    /**
     * Permission modul Network / FreeRADIUS (v0.6.1). Sama pola dengan
     * seedInstallationPermissions() — super_admin-only; reseller mengelola
     * NAS miliknya sendiri lewat reseller_users membership (dicek di
     * NasPolicy), bukan lewat permission Spatie ini.
     */
    private function seedNetworkPermissions(): void
    {
        $permissions = [
            'nas.view',
            'nas.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::findByName('super_admin', 'web')->givePermissionTo($permissions);
    }

    /**
     * v0.7.1 GenieACS Core — read-only originally (binding is fully
     * automatic from Installation, no manual create/manage action existed
     * yet), so only a `.view` permission at first.
     *
     * v0.7.4 adds `cpe_devices.manage` (reboot / WiFi credential remote
     * actions) — same reseller-ownership carve-out as odps/nas/
     * whatsapp_gateway: an admin with `.manage` can act on every device
     * including direct/no-reseller ones, and a reseller's own active
     * `reseller_users` membership (owner OR staff) can act on that
     * reseller's own devices via CpeDevicePolicy::manage(), without needing
     * this Spatie permission at all.
     */
    private function seedGenieAcsPermissions(): void
    {
        Permission::firstOrCreate(['name' => 'cpe_devices.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'cpe_devices.manage', 'guard_name' => 'web']);

        Role::findByName('super_admin', 'web')->givePermissionTo(['cpe_devices.view', 'cpe_devices.manage']);
    }

    /**
     * v0.7.2 — strictly super_admin-only, same posture as
     * payment_gateway_settings/whatsapp_gateway_settings: this is
     * platform-level technical config (per-vendor TR-069 parameter maps),
     * not a per-reseller concern.
     */
    private function seedCpeParameterMapPermissions(): void
    {
        Permission::firstOrCreate(['name' => 'cpe_parameter_maps.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'cpe_parameter_maps.manage', 'guard_name' => 'web']);

        Role::findByName('super_admin', 'web')->givePermissionTo([
            'cpe_parameter_maps.view',
            'cpe_parameter_maps.manage',
        ]);
    }
}
