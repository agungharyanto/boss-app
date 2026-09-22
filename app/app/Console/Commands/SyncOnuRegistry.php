<?php

namespace App\Console\Commands;

use App\Models\OltDevice;
use App\Services\Network\OnuRegistryService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Trigger MANUAL untuk registry ONU (v0.23.4) — ON-DEMAND SAJA, TIDAK
 * dijadwalkan (tidak ada entri di routes/console.php). Satu-satunya cara
 * memicu sync di sub-versi ini (belum ada UI/route HTTP, sesuai
 * instruksi eksplisit). Lihat docs/omci/onu-registry-design.md.
 */
class SyncOnuRegistry extends Command
{
    protected $signature = 'onu:sync {olt_device_id : ID olt_devices} {vendor_identifier : Identifier ONU dalam format CLI vendor, mis. gpon-onu_1/3/12:2}';

    protected $description = 'Sinkronkan satu baris registry ONU dari OLT nyata via sidecar (on-demand, tidak ada job berkala)';

    public function handle(OnuRegistryService $service): int
    {
        $oltDevice = OltDevice::withoutGlobalScopes()->find((int) $this->argument('olt_device_id'));
        if ($oltDevice === null) {
            $this->error("OltDevice #{$this->argument('olt_device_id')} tidak ditemukan.");

            return self::FAILURE;
        }

        $vendorIdentifier = (string) $this->argument('vendor_identifier');

        try {
            $registry = $service->syncOnu($oltDevice, $vendorIdentifier);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Registry ONU #{$registry->id} tersimpan.");
        $this->line("OLT: #{$oltDevice->id} ({$oltDevice->name})");
        $this->line("Vendor identifier: {$registry->vendor_identifier}");
        $this->line('Status ONU: '.$registry->status->label());
        $this->line('Status sync: '.$registry->sync_status->label());
        $this->line('Punya serial_number: '.($registry->serial_number !== null ? 'ya' : 'tidak'));
        $this->line('Punya mac_address: '.($registry->mac_address !== null ? 'ya' : 'tidak'));
        $this->line('Punya name: '.($registry->name !== null ? 'ya' : 'tidak'));
        $this->line('work_order_modem_unit_id: '.($registry->work_order_modem_unit_id !== null ? "#{$registry->work_order_modem_unit_id}" : 'tidak ada match'));
        $this->line("Terakhir sync: {$registry->last_synced_at}");

        return self::SUCCESS;
    }
}
