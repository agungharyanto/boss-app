<?php

namespace App\Livewire\Network;

use App\Enums\CpeUsageHistoryRange;
use App\Livewire\Concerns\ValidatesCustomHistoryRange;
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
 *
 * v0.12.6 (revisi) — modal "Riwayat", pola PERSIS CpeSignalHistoryGraph
 * (RX Power): dua chart instance independen (grafik utama fixed 30 hari,
 * modal punya range state sendiri), reuse
 * App\Livewire\Concerns\ValidatesCustomHistoryRange untuk tab "Custom",
 * reuse partial livewire.network.partials.history-range-tabs, reuse
 * <x-modal> — TIDAK ada pattern UI baru. Vocab tab-nya SENGAJA beda dari
 * CpeSignalHistoryRange (lihat App\Enums\CpeUsageHistoryRange's own
 * docblock: data radacct cuma final per-sesi, tidak ada granularitas
 * sub-harian genuinely ada untuk direpresentasikan sebagai "per jam").
 *
 * Tombol "Riwayat" HANYA muncul saat $state === 'ok' (BEDA dari RX Power
 * yang tombolnya selalu ada terlepas dari state grafik utama) — keputusan
 * desain yang disengaja, bukan kelalaian: CpeSignalHistoryGraph's
 * 'no_history' cuma berarti "kosong di window Day (24 jam) SEKARANG",
 * histori lebih lama BISA saja ada di rentang lain (RX Power terus
 * di-poll tiap ~20 menit sejak kapan pun mulai dicatat). Grafik Pemakaian
 * 'no_data' BEDA SECARA STRUKTURAL — dari hasAnyRecordedUsage(), yang
 * dicek TANPA BATAS TANGGAL SAMA SEKALI (lihat method itu sendiri) — jadi
 * kalau true, TIDAK ADA satu pun row radacct di SELURUH waktu, modal
 * dengan rentang berapa pun pun pasti kosong juga. Menampilkan tombol
 * "Riwayat" di state itu cuma akan membuka modal yang selalu kosong,
 * tidak informatif — pesan "Belum ada data pemakaian" di grafik utama
 * sudah cukup jelas tanpa perlu modal tambahan.
 */
class CpeUsageHistoryGraph extends Component
{
    use AuthorizesRequests, ValidatesCustomHistoryRange;

    public int $cpeDeviceId;

    public int $customerId;

    public string $state = 'no_data';

    /** @var array<int, array{date: string, upload_mb: float, download_mb: float}> */
    public array $series = [];

    public bool $showHistoryModal = false;

    public string $modalRange = '30_days';

    public string $modalState = 'no_data';

    /** @var array<int, array{date: string, upload_mb: float, download_mb: float}> */
    public array $modalSeries = [];

    public function mount(int $cpeDeviceId, ?CpeUsageHistoryService $service = null): void
    {
        $device = CpeDevice::findOrFail($cpeDeviceId);
        $this->authorize('view', $device);

        $this->cpeDeviceId = $cpeDeviceId;
        $this->customerId = $device->customer_id;

        $customer = Customer::withoutGlobalScopes()->findOrFail($this->customerId);

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

    public function openHistoryModal(): void
    {
        $this->showHistoryModal = true;
        $this->customRangeMode = false;
        $this->modalRange = CpeUsageHistoryRange::default()->value;

        $this->loadModalSeries();
    }

    public function closeHistoryModal(): void
    {
        $this->showHistoryModal = false;
    }

    public function changeModalRange(string $range): void
    {
        // Validates via the enum's own backing — nilai tak dikenal cuma
        // tidak pernah cocok case mana pun, jadi payload wire:click palsu
        // tidak bisa memaksa rentang di luar 4 yang genuinely ada.
        $this->customRangeMode = false;
        $this->modalRange = CpeUsageHistoryRange::from($range)->value;

        $this->loadModalSeries();
    }

    public function loadModalSeries(?CpeUsageHistoryService $service = null): void
    {
        $service ??= app(CpeUsageHistoryService::class);
        $customer = Customer::withoutGlobalScopes()->findOrFail($this->customerId);
        $range = CpeUsageHistoryRange::from($this->modalRange);

        if (! $service->hasAnyRecordedUsage($customer)) {
            $this->modalState = 'no_data';
            $this->modalSeries = [];
        } else {
            $this->modalState = 'ok';
            $this->modalSeries = $service->dailyUsageForCustomer($customer, $range->windowDays());
        }

        $this->dispatch('usage-history-modal-series-updated', series: $this->modalSeries);
    }

    /**
     * "Custom" tab — App\Livewire\Concerns\ValidatesCustomHistoryRange
     * menangani validasi tanggalnya, granularitas TETAP per hari (lihat
     * App\Enums\CpeUsageHistoryRange's own docblock).
     */
    public function applyCustomRange(?CpeUsageHistoryService $service = null): void
    {
        $bounds = $this->validateCustomRange();

        if ($bounds === null) {
            return;
        }

        [$from, $to] = $bounds;
        $service ??= app(CpeUsageHistoryService::class);
        $customer = Customer::withoutGlobalScopes()->findOrFail($this->customerId);

        if (! $service->hasAnyRecordedUsage($customer)) {
            $this->modalState = 'no_data';
            $this->modalSeries = [];
        } else {
            $this->modalState = 'ok';
            $this->modalSeries = $service->customDailyUsageForCustomer($customer, $from, $to);
        }

        $this->dispatch('usage-history-modal-series-updated', series: $this->modalSeries);
    }

    public function render()
    {
        return view('livewire.network.cpe-usage-history-graph', [
            'ranges' => CpeUsageHistoryRange::cases(),
        ]);
    }
}
