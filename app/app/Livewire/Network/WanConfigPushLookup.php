<?php

namespace App\Livewire\Network;

use App\Models\CpeDevice;
use App\Models\RemoteWanConfig;
use App\Services\Network\WanConfigPushService;
use App\Services\Network\WanConfigTemplateResolverService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * v0.12.6 (revisi) — section "Push Konfig" pada halaman `/remote-config`,
 * DI BAWAH form Auto-WAN existing (yang TIDAK disentuh sama sekali).
 * Search pelanggan/device -> tampilkan Template Konfig CPE yang
 * ter-resolve (App\Services\Network\WanConfigTemplateResolverService,
 * sama persis yang dipakai WanConfigPushService::push() sendiri saat
 * push) -> tombol "Push Sekarang" memanggil
 * App\Services\Network\WanConfigPushService::push() — SERVICE YANG SAMA
 * PERSIS dengan tombol "Push Konfig Sekarang" di Detail Perangkat CPE
 * (App\Http\Controllers\Api\Internal\CpeDeviceActionController::
 * pushWanConfig()) dan hook otomatis di SubscriptionRenewalService::renew()
 * — tidak ada logic push kedua yang duplikat di mana pun.
 *
 * Gate `remote_config.manage` (App\Policies\RemoteWanConfigPolicy) —
 * BUKAN `cpe_devices.manage` — section ini bagian dari halaman Konfig
 * Remote (NOC + tier-admin), konsisten dengan gate form Auto-WAN di
 * komponen sebelahnya (RemoteConfigSettings), bukan permission CPE
 * terpisah.
 */
class WanConfigPushLookup extends Component
{
    use AuthorizesRequests;

    public string $search = '';

    public ?int $selectedDeviceId = null;

    public ?string $pushMessage = null;

    public bool $pushWasError = false;

    public function mount(): void
    {
        $this->authorize('view', RemoteWanConfig::class);
    }

    public function updatingSearch(): void
    {
        $this->selectedDeviceId = null;
        $this->pushMessage = null;
    }

    public function selectDevice(int $deviceId): void
    {
        // findOrFail() sudah tenant-scoped (global scope CpeDevice) —
        // id dari tenant lain 404 sebelum sampai ke sini.
        CpeDevice::findOrFail($deviceId);

        $this->selectedDeviceId = $deviceId;
        $this->pushMessage = null;
    }

    public function clearSelection(): void
    {
        $this->selectedDeviceId = null;
        $this->pushMessage = null;
    }

    /**
     * Pesan hasil PERSIS sama dengan CpeDeviceActionController::
     * pushWanConfig() — satu kalimat per status, tidak ditulis ulang beda
     * kata di sini.
     */
    public function pushNow(WanConfigPushService $service): void
    {
        $this->authorize('manage', RemoteWanConfig::class);

        if ($this->selectedDeviceId === null) {
            return;
        }

        $device = CpeDevice::findOrFail($this->selectedDeviceId);
        $log = $service->push($device, auth()->user());

        $this->pushMessage = match ($log->status->value) {
            'delivered' => 'Push Konfig terkirim — akan diterapkan saat perangkat terhubung berikutnya (atau langsung kalau Connection Request kebetulan berhasil). Ini BUKAN konfirmasi perangkat sudah menjalankannya.',
            'skipped' => 'Push Konfig dilewati: '.$log->failed_reason,
            default => 'Push Konfig GAGAL dikirim: '.$log->failed_reason,
        };
        $this->pushWasError = $log->status->value === 'failed';
    }

    public function render(WanConfigTemplateResolverService $resolver)
    {
        $needle = trim($this->search);

        // Dibungkus dalam satu closure ->where() supaya OR-nya benar-benar
        // ter-parenthesize terhadap TenantScope global (kalau ditulis
        // ->whereHas(...)->orWhere(...) rata di top level, ada risiko OR
        // itu bocor keluar dari kondisi tenant_id yang sudah ditambahkan
        // global scope) — pola sama seperti guard cross-tenant di seluruh
        // codebase ini.
        $results = $needle === '' ? collect() : CpeDevice::query()
            ->with('customer')
            ->where(function ($q) use ($needle) {
                $q->whereHas('customer', function ($sub) use ($needle) {
                    $sub->where('name', 'like', "%{$needle}%")
                        ->orWhere('cid', 'like', "%{$needle}%")
                        ->orWhere('phone_number', 'like', "%{$needle}%");
                })->orWhere('serial_number', 'like', "%{$needle}%");
            })
            ->limit(10)
            ->get();

        $selectedDevice = null;
        $resolvedTemplate = null;

        if ($this->selectedDeviceId !== null) {
            $selectedDevice = CpeDevice::query()->with(['customer.pppPackage', 'modemType'])->find($this->selectedDeviceId);

            if ($selectedDevice !== null) {
                $resolvedTemplate = $resolver->resolveTemplateForDevice($selectedDevice);
            }
        }

        return view('livewire.network.wan-config-push-lookup', [
            'results' => $results,
            'selectedDevice' => $selectedDevice,
            'resolvedTemplate' => $resolvedTemplate,
            'canManage' => auth()->user()->can('manage', RemoteWanConfig::class),
        ]);
    }
}
