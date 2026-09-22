<?php

namespace Database\Factories;

use App\Enums\OnuRegistryStatus;
use App\Enums\OnuSyncStatus;
use App\Models\OltDevice;
use App\Models\OnuRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnuRegistry>
 */
class OnuRegistryFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Sama gotcha attribute-order yang sudah didokumentasikan
            // untuk OltDeviceFactory (CLAUDE.md "IP Pool Pelanggan
            // v0.14.2") — olt_device_id HARUS didefinisikan sebelum
            // tenant_id kalau closure tenant_id membaca $attributes
            // (di sini tidak, tapi urutan dipertahankan konsisten untuk
            // menghindari kelas bug yang sama kalau berubah nanti).
            'olt_device_id' => OltDevice::factory(),
            'tenant_id' => fn (array $attributes) => OltDevice::withoutGlobalScopes()->find($attributes['olt_device_id'])?->tenant_id,
            'vendor_identifier' => 'gpon-onu_1/3/'.$this->faker->numberBetween(1, 16).':'.$this->faker->numberBetween(1, 64),
            'serial_number' => null,
            'mac_address' => null,
            'status' => OnuRegistryStatus::Unknown,
            'sync_status' => OnuSyncStatus::Synced,
            'name' => null,
            'description' => null,
            'work_order_modem_unit_id' => null,
            'last_synced_at' => now(),
            'raw_payload' => null,
        ];
    }
}
