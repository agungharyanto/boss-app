<?php

namespace Database\Seeders;

use App\Models\ProvisioningAdapter as ProvisioningAdapterModel;
use App\Services\Network\Provisioning\Contracts\OltProvisioningAdapter;
use App\Services\Network\Provisioning\Contracts\ProvisioningAdapter;
use App\Services\Network\Provisioning\CpeAdapterRegistry;
use App\Services\Network\Provisioning\OltAdapterRegistry;
use Illuminate\Database\Seeder;

/**
 * Isi tabel mirror `provisioning_adapters` DARI registry KODE (sumber
 * kebenaran, keputusan #1 ZTP v0.23.8). Idempoten (updateOrCreate) — aman
 * dijalankan ulang; adapter baru cukup ditambah di registry lalu seeder
 * di-run lagi.
 */
class ProvisioningAdapterSeeder extends Seeder
{
    public function run(): void
    {
        $registries = [
            app(CpeAdapterRegistry::class)->all(),
            app(OltAdapterRegistry::class)->all(),
        ];

        foreach ($registries as $adapters) {
            foreach ($adapters as $adapter) {
                $this->mirror($adapter);
            }
        }
    }

    private function mirror(ProvisioningAdapter $adapter): void
    {
        $ponMode = $adapter instanceof OltProvisioningAdapter ? $adapter->ponMode()->value : null;

        ProvisioningAdapterModel::updateOrCreate(
            [
                'layer' => $adapter->layer()->value,
                'vendor_key' => $adapter->vendorKey(),
                'pon_mode' => $ponMode,
            ],
            [
                'transport' => $adapter->transport(),
                'is_supported' => $adapter->isSupported(),
                'capabilities' => $adapter->capabilities()->toArray(),
            ],
        );
    }
}
