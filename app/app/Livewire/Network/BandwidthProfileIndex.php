<?php

namespace App\Livewire\Network;

use App\Models\BandwidthProfile;
use App\Services\Network\BandwidthProfileService;
use App\Support\ProfilPaketAttributeLabels;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * v0.14.1 — fondasi cluster "Profil Paket". The 4 raw bandwidth values are
 * always entered by the admin in ONE unit at a time (Kbps or Mbps, picked
 * via $unit) purely for input convenience — converted to Kbps (this
 * codebase's one consistent internal storage unit, see the migration's own
 * docblock) right before validation/save, never stored with a separate
 * unit column. BandwidthProfileService/the REST API both only ever see
 * already-Kbps values.
 */
class BandwidthProfileIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'name';

    public string $sortDir = 'asc';

    public bool $showCreateForm = false;

    public string $unit = 'Kbps';

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|numeric|min:0.001')]
    public string $uploadMin = '';

    #[Validate('required|numeric|min:0.001')]
    public string $uploadMax = '';

    #[Validate('required|numeric|min:0.001')]
    public string $downloadMin = '';

    #[Validate('required|numeric|min:0.001')]
    public string $downloadMax = '';

    public bool $isActive = true;

    public ?int $editingProfileId = null;

    public string $editUnit = 'Kbps';

    public string $editName = '';

    public string $editUploadMin = '';

    public string $editUploadMax = '';

    public string $editDownloadMin = '';

    public string $editDownloadMax = '';

    public bool $editIsActive = true;

    public function mount(): void
    {
        $this->authorize('viewAny', BandwidthProfile::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    /**
     * Whole Kbps only — a value entered in Mbps that doesn't land on an
     * exact Kbps boundary (e.g. "1.5" Mbps -> 1500 Kbps is fine, but a
     * pathological "1.0005" Mbps would round) is rounded, never truncated
     * silently without the admin's own typed precision being respected
     * beyond what Kbps (this codebase's one storage unit) can represent.
     */
    private function toKbps(string $value, string $unit): int
    {
        $numeric = (float) $value;

        return (int) round($unit === 'Mbps' ? $numeric * 1000 : $numeric);
    }

    public function createProfile(BandwidthProfileService $service): void
    {
        $this->authorize('manage', BandwidthProfile::class);

        // Real bug found via manual UI testing: "10Mbps" vs "10Mbps " (a
        // trailing space) are byte-distinct, so Rule::unique() below never
        // caught them — Livewire's inline validate() doesn't go through
        // FormRequest::prepareForValidation(), so this needs its own trim
        // (see StoreBandwidthProfileRequest's own comment for the full story).
        $this->name = trim($this->name);

        $this->validate([
            // whereNull('deleted_at') needed — see StoreBandwidthProfileRequest's own comment.
            'name' => ['required', 'string', 'max:255', Rule::unique(BandwidthProfile::class, 'name')->where('tenant_id', auth()->user()->tenant_id)->whereNull('deleted_at')],
            'uploadMin' => 'required|numeric|min:0.001',
            'uploadMax' => 'required|numeric|min:0.001',
            'downloadMin' => 'required|numeric|min:0.001',
            'downloadMax' => 'required|numeric|min:0.001',
        ]);

        $uploadMinKbps = $this->toKbps($this->uploadMin, $this->unit);
        $uploadMaxKbps = $this->toKbps($this->uploadMax, $this->unit);
        $downloadMinKbps = $this->toKbps($this->downloadMin, $this->unit);
        $downloadMaxKbps = $this->toKbps($this->downloadMax, $this->unit);

        if ($uploadMaxKbps < $uploadMinKbps) {
            $this->addError('uploadMax', 'Upload max harus >= upload min.');

            return;
        }

        if ($downloadMaxKbps < $downloadMinKbps) {
            $this->addError('downloadMax', 'Download max harus >= download min.');

            return;
        }

        $service->create([
            'name' => $this->name,
            'upload_min' => $uploadMinKbps,
            'upload_max' => $uploadMaxKbps,
            'download_min' => $downloadMinKbps,
            'download_max' => $downloadMaxKbps,
            'is_active' => $this->isActive,
        ]);

        $this->reset(['name', 'uploadMin', 'uploadMax', 'downloadMin', 'downloadMax', 'isActive', 'showCreateForm', 'unit']);
        $this->isActive = true;
        $this->unit = 'Kbps';
    }

    public function edit(int $profileId): void
    {
        $profile = BandwidthProfile::findOrFail($profileId);
        $this->authorize('manage', BandwidthProfile::class);

        $this->editingProfileId = $profile->id;
        $this->editUnit = 'Kbps';
        $this->editName = $profile->name;
        $this->editUploadMin = (string) $profile->upload_min;
        $this->editUploadMax = (string) $profile->upload_max;
        $this->editDownloadMin = (string) $profile->download_min;
        $this->editDownloadMax = (string) $profile->download_max;
        $this->editIsActive = $profile->is_active;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingProfileId', 'editUnit', 'editName', 'editUploadMin', 'editUploadMax', 'editDownloadMin', 'editDownloadMax', 'editIsActive']);
    }

    public function updateProfile(BandwidthProfileService $service): void
    {
        $profile = BandwidthProfile::findOrFail($this->editingProfileId);
        $this->authorize('manage', BandwidthProfile::class);

        // Same trim-before-validate fix as createProfile() above.
        $this->editName = trim($this->editName);

        $this->validate([
            'editName' => ['required', 'string', 'max:255', Rule::unique(BandwidthProfile::class, 'name')->where('tenant_id', auth()->user()->tenant_id)->whereNull('deleted_at')->ignore($profile->id)],
            'editUploadMin' => 'required|numeric|min:0.001',
            'editUploadMax' => 'required|numeric|min:0.001',
            'editDownloadMin' => 'required|numeric|min:0.001',
            'editDownloadMax' => 'required|numeric|min:0.001',
        ]);

        $uploadMinKbps = $this->toKbps($this->editUploadMin, $this->editUnit);
        $uploadMaxKbps = $this->toKbps($this->editUploadMax, $this->editUnit);
        $downloadMinKbps = $this->toKbps($this->editDownloadMin, $this->editUnit);
        $downloadMaxKbps = $this->toKbps($this->editDownloadMax, $this->editUnit);

        if ($uploadMaxKbps < $uploadMinKbps) {
            $this->addError('editUploadMax', 'Upload max harus >= upload min.');

            return;
        }

        if ($downloadMaxKbps < $downloadMinKbps) {
            $this->addError('editDownloadMax', 'Download max harus >= download min.');

            return;
        }

        $service->update($profile, [
            'name' => $this->editName,
            'upload_min' => $uploadMinKbps,
            'upload_max' => $uploadMaxKbps,
            'download_min' => $downloadMinKbps,
            'download_max' => $downloadMaxKbps,
            'is_active' => $this->editIsActive,
        ]);

        $this->cancelEdit();
    }

    public function deleteProfile(int $profileId, BandwidthProfileService $service): void
    {
        $this->authorize('manage', BandwidthProfile::class);

        $service->delete(BandwidthProfile::findOrFail($profileId));
    }

    public function render()
    {
        $profiles = BandwidthProfile::query()
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(15);

        return view('livewire.network.bandwidth-profile-index', [
            'profiles' => $profiles,
            'canManage' => auth()->user()->can('manage', BandwidthProfile::class),
        ]);
    }

    /**
     * Revisi Pesan Error Bahasa Indonesia — nama field di pesan validasi
     * (mis. "Harga Jual wajib diisi." bukan "The sell price field is
     * required.") lewat satu sumber tunggal dipakai lintas seluruh cluster
     * "Profil Paket" — lihat ProfilPaketAttributeLabels sendiri. Mencakup
     * juga varian `edit`-prefixed (mis. editSellPrice) secara otomatis.
     */
    public function validationAttributes(): array
    {
        return ProfilPaketAttributeLabels::forLivewire();
    }
}
