<?php

namespace App\Livewire\Network;

use App\Models\CpeDevice;
use App\Models\Customer;
use App\Services\Network\CpeUsageHistoryService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * v0.12.6 — "Grafik Pemakaian" pada Detail Perangkat CPE, sumber data
 * `radacct` (30 hari terakhir, per hari — lihat CpeUsageHistoryService's
 * own docblock untuk alasan agregasi dari `acctstoptime`). Pola resolusi
 * device -> customer PERSIS CpeDialupHistory (v0.8.4) — self-authorizes
 * independen, `withoutGlobalScopes()` untuk customer karena CpeDevice
 * sendiri sudah tenant-scoped saat di-fetch.
 *
 * DUA state saja (bukan tiga seperti CpeSignalHistoryGraph) — sesuai
 * instruksi sprint: 'no_data' (customer ini TIDAK PUNYA satu pun row
 * radacct SAMA SEKALI, bukan cuma kosong di 30 hari terakhir — lihat
 * CpeUsageHistoryService::hasAnyRecordedUsage()) vs 'ok' (render chart,
 * hari tanpa sesi selesai tetap tampil sebagai 0, bukan gap/dilewati —
 * beda dari RX Power yang null berarti "genuinely tidak terbaca", di sini
 * 0 genuinely berarti "tidak ada pemakaian tercatat hari itu").
 */
class CpeUsageHistoryGraph extends Component
{
    use AuthorizesRequests;

    public int $cpeDeviceId;

    public string $state = 'no_data';

    /** @var array<int, array{date: string, upload_mb: float, download_mb: float}> */
    public array $series = [];

    public function mount(int $cpeDeviceId, ?CpeUsageHistoryService $service = null): void
    {
        $device = CpeDevice::findOrFail($cpeDeviceId);
        $this->authorize('view', $device);

        $this->cpeDeviceId = $cpeDeviceId;

        $customer = Customer::withoutGlobalScopes()->findOrFail($device->customer_id);

        $service ??= app(CpeUsageHistoryService::class);

        if (! $service->hasAnyRecordedUsage($customer)) {
            $this->state = 'no_data';
            $this->series = [];

            return;
        }

        $this->state = 'ok';
        $this->series = $service->dailyUsageForCustomer($customer);

        // Chart.js's canvas lives inside a `wire:ignore` wrapper — sama
        // mekanisme DeviceTrafficGraph/CpeSignalHistoryGraph, bukan pola
        // baru.
        $this->dispatch('usage-history-series-updated', series: $this->series);
    }

    public function render()
    {
        return view('livewire.network.cpe-usage-history-graph');
    }
}
