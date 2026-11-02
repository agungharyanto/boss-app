<?php

namespace App\Livewire\Settings;

use App\Models\CompanyProfile;
use App\Services\Company\CompanyProfileService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * v0.19.1 — "Pengaturan > Profil Perusahaan". Per tenant, admin-only,
 * dimodelkan dari PaymentGatewaySettings. Semua field editable lewat UI,
 * ramah HP (lihat view). Logo diunggah ke disk PRIVAT dan di-stream lewat
 * CompanyLogoController (tidak pernah dari storage publik).
 */
class CompanyProfileSettings extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public string $legalName = '';

    public string $brandName = '';

    public string $address = '';

    public string $city = '';

    public string $postalCode = '';

    public string $npwp = '';

    public string $csWhatsapp = '';

    public string $csPhone = '';

    public string $email = '';

    public string $operatingHours = '';

    public string $bankName = '';

    public string $bankAccountNumber = '';

    public string $bankAccountHolder = '';

    public ?TemporaryUploadedFile $logo = null;

    public bool $hasLogo = false;

    public ?string $lastUpdatedAt = null;

    /**
     * MIME tervalidasi → ekstensi yang dipakai menyimpan file (ekstensi
     * TIDAK pernah diambil dari nama file unggahan — selalu diturunkan dari
     * MIME yang sudah lolos validasi mimes:jpg,jpeg,png,webp).
     *
     * @var array<string, string>
     */
    private const MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function mount(CompanyProfileService $service): void
    {
        $this->authorize('view', CompanyProfile::class);

        $profile = $service->forTenant(auth()->user()->tenant_id);
        $this->fillFromProfile($profile);
    }

    public function save(CompanyProfileService $service): void
    {
        $this->authorize('manage', CompanyProfile::class);

        $validated = $this->validate([
            'legalName' => ['nullable', 'string', 'max:255'],
            'brandName' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:255'],
            'postalCode' => ['nullable', 'string', 'max:20'],
            'npwp' => ['nullable', 'string', 'max:50'],
            'csWhatsapp' => ['nullable', 'string', 'max:30'],
            'csPhone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'operatingHours' => ['nullable', 'string', 'max:255'],
            'bankName' => ['nullable', 'string', 'max:255'],
            'bankAccountNumber' => ['nullable', 'string', 'max:50'],
            'bankAccountHolder' => ['nullable', 'string', 'max:255'],
            // GD/Imagick ABSEN — jangan pakai rule `dimensions` (butuh GD).
            // `mimes` cukup: cek tipe dari isi/MIME, SVG ditolak karena tak
            // ada di daftar.
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        $profile = $service->forTenant($tenantId);

        $data = [
            'legal_name' => $validated['legalName'] ?: null,
            'brand_name' => $validated['brandName'] ?: null,
            'address' => $validated['address'] ?: null,
            'city' => $validated['city'] ?: null,
            'postal_code' => $validated['postalCode'] ?: null,
            'npwp' => $validated['npwp'] ?: null,
            'cs_whatsapp' => $validated['csWhatsapp'] ?: null,
            'cs_phone' => $validated['csPhone'] ?: null,
            'email' => $validated['email'] ?: null,
            'operating_hours' => $validated['operatingHours'] ?: null,
            'bank_name' => $validated['bankName'] ?: null,
            'bank_account_number' => $validated['bankAccountNumber'] ?: null,
            'bank_account_holder' => $validated['bankAccountHolder'] ?: null,
            'updated_by' => auth()->id(),
        ];

        if ($this->logo !== null) {
            $data['logo_path'] = $this->storeLogo($tenantId, $profile->logo_path);
        }

        $profile->update($data);

        $this->reset('logo');
        $this->fillFromProfile($profile->fresh());

        session()->flash('status', 'Profil perusahaan berhasil disimpan.');
    }

    public function removeLogo(CompanyProfileService $service): void
    {
        $this->authorize('manage', CompanyProfile::class);

        $profile = $service->forTenant(auth()->user()->tenant_id);

        if ($profile->logo_path !== null) {
            Storage::disk('local')->delete($profile->logo_path);
            $profile->update(['logo_path' => null, 'updated_by' => auth()->id()]);
        }

        $this->reset('logo');
        $this->fillFromProfile($profile->fresh());

        session()->flash('status', 'Logo perusahaan dihapus.');
    }

    public function render(CompanyProfileService $service)
    {
        return view('livewire.settings.company-profile-settings', [
            'missing' => $service->missing(auth()->user()->tenant_id),
        ]);
    }

    private function storeLogo(int $tenantId, ?string $oldPath): string
    {
        $ext = self::MIME_TO_EXT[$this->logo->getMimeType()] ?? 'png';
        $path = $this->logo->storeAs('company-logos', $tenantId.'.'.$ext, 'local');

        // Hapus logo lama kalau ekstensinya beda (jadi path beda) supaya
        // tidak meninggalkan file yatim.
        if ($oldPath !== null && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }

        return $path;
    }

    private function fillFromProfile(CompanyProfile $profile): void
    {
        $this->legalName = (string) ($profile->legal_name ?? '');
        $this->brandName = (string) ($profile->brand_name ?? '');
        $this->address = (string) ($profile->address ?? '');
        $this->city = (string) ($profile->city ?? '');
        $this->postalCode = (string) ($profile->postal_code ?? '');
        $this->npwp = (string) ($profile->npwp ?? '');
        $this->csWhatsapp = (string) ($profile->cs_whatsapp ?? '');
        $this->csPhone = (string) ($profile->cs_phone ?? '');
        $this->email = (string) ($profile->email ?? '');
        $this->operatingHours = (string) ($profile->operating_hours ?? '');
        $this->bankName = (string) ($profile->bank_name ?? '');
        $this->bankAccountNumber = (string) ($profile->bank_account_number ?? '');
        $this->bankAccountHolder = (string) ($profile->bank_account_holder ?? '');
        $this->hasLogo = $profile->logo_path !== null;
        $this->lastUpdatedAt = $profile->updated_at?->translatedFormat('d M Y H:i');
    }
}
