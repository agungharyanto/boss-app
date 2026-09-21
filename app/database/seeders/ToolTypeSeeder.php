<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\ToolType;
use Illuminate\Database\Seeder;

/**
 * v0.13.4.1 — 4 alat awal per tenant (Dropcore/Adapter/Patchcore/Adaptor
 * Modem), murni titik mulai — admin bisa tambah/edit sendiri lewat UI
 * (App\Livewire\Installation\ToolTypeIndex). firstOrCreate by (tenant_id,
 * name) — safe diulang, tidak pernah menimpa alat yang sudah diedit admin.
 */
class ToolTypeSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = ['Dropcore', 'Adapter', 'Patchcore', 'Adaptor Modem'];

        Tenant::all()->each(function (Tenant $tenant) use ($defaults) {
            foreach ($defaults as $name) {
                ToolType::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $name],
                    ['is_active' => true],
                );
            }
        });
    }
}
