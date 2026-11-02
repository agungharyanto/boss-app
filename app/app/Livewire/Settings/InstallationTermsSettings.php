<?php

namespace App\Livewire\Settings;

use App\Services\Company\InstallationTermsService;
use App\Services\Company\TermsRenderer;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * v0.19.1 — "Pengaturan > Syarat & Ketentuan Pemasangan". Editor teks
 * INSERT-ONLY: tiap simpan = versi baru (versi lama tetap utuh, terikat ke
 * tanda tangan masing-masing nanti). Semua logic server-side (tanpa factory
 * JS) — tombol sisip variabel, pratinjau data contoh, dan "pakai ulang
 * versi ini" semuanya aksi Livewire.
 *
 * Gate pakai permission string company_profile.view/.manage (sama izin
 * dengan Profil Perusahaan) — bukan Policy model, karena tidak ada satu
 * baris singleton untuk di-authorize (banyak versi per tenant).
 */
class InstallationTermsSettings extends Component
{
    public const BODY_MAX = 20000;

    public string $body = '';

    public ?string $preview = null;

    public ?int $loadedFromVersion = null;

    public function mount(InstallationTermsService $service): void
    {
        abort_unless(auth()->user()?->can('company_profile.view'), 403);

        $active = $service->active(auth()->user()->tenant_id);
        if ($active !== null) {
            $this->body = $active->body;
            $this->loadedFromVersion = $active->version;
        }
    }

    public function insertVariable(string $variable): void
    {
        abort_unless(auth()->user()?->can('company_profile.manage'), 403);

        // Hanya variabel whitelist yang boleh disisipkan dari tombol.
        if (! in_array($variable, TermsRenderer::WHITELIST, true)) {
            return;
        }

        $token = '{'.$variable.'}';
        $this->body = $this->body === '' ? $token : $this->body.$token;
        $this->preview = null;
    }

    public function previewTerms(TermsRenderer $renderer): void
    {
        abort_unless(auth()->user()?->can('company_profile.view'), 403);

        $this->preview = $renderer->render($this->body, $renderer->sampleContext());
    }

    public function useVersion(int $termsId, InstallationTermsService $service): void
    {
        abort_unless(auth()->user()?->can('company_profile.manage'), 403);

        $terms = $service->history(auth()->user()->tenant_id)
            ->firstWhere('id', $termsId);

        if ($terms === null) {
            return;
        }

        // Muat ke editor sebagai draft — simpan nanti = versi BARU, bukan
        // menimpa versi yang dipakai ulang.
        $this->body = $terms->body;
        $this->loadedFromVersion = $terms->version;
        $this->preview = null;

        session()->flash('status', 'Teks versi '.$terms->version.' dimuat ke editor. Klik Simpan untuk menjadikannya versi baru.');
    }

    public function save(InstallationTermsService $service, TermsRenderer $renderer): void
    {
        abort_unless(auth()->user()?->can('company_profile.manage'), 403);

        $body = trim($this->body);

        $this->validate([
            'body' => ['required', 'string', 'max:'.self::BODY_MAX],
        ], [
            'body.required' => 'Isi teks Syarat & Ketentuan tidak boleh kosong.',
            'body.max' => 'Teks terlalu panjang (maksimal '.number_format(self::BODY_MAX, 0, ',', '.').' karakter).',
        ]);

        $unknown = $renderer->unknownVariables($body);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'body' => 'Variabel tidak dikenal: '.implode(', ', array_map(fn ($v) => '{'.$v.'}', $unknown))
                    .'. Hanya variabel pada daftar yang boleh dipakai.',
            ]);
        }

        $saved = $service->saveNewVersion(auth()->user()->tenant_id, $body, auth()->id());

        $this->body = $saved->body;
        $this->loadedFromVersion = $saved->version;
        $this->preview = null;

        session()->flash('status', 'Tersimpan sebagai versi '.$saved->version.'.');
    }

    public function render(InstallationTermsService $service)
    {
        $active = $service->active(auth()->user()->tenant_id);

        return view('livewire.settings.installation-terms-settings', [
            'variables' => TermsRenderer::WHITELIST,
            'activeVersion' => $active?->version,
            'history' => $service->history(auth()->user()->tenant_id),
        ]);
    }
}
