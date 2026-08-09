<?php

namespace App\Livewire\Network;

use App\Models\CpeDevice;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only murni (v0.7.1) — belum ada tombol aksi apa pun (reboot/ganti
 * SSID itu scope v0.7.3). BelongsToResellerScope pada CpeDevice sudah
 * menangani scoping otomatis, sama seperti NasIndex — tidak perlu filter
 * manual di sini.
 */
class CpeDeviceIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', CpeDevice::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $devices = CpeDevice::query()
            ->with(['customer', 'reseller'])
            ->when(
                $this->search,
                fn ($query) => $query->where('serial_number', 'like', "%{$this->search}%")
                    ->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            )
            ->latest()
            ->paginate(15);

        return view('livewire.network.cpe-device-index', [
            'devices' => $devices,
        ]);
    }
}
