<?php

namespace App\Livewire\ReferrerPortal;

use App\Enums\CommissionScheme;
use App\Models\Referrer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Portal Referrer — beranda: Profil Saya (nomor HP read-only, nama
 * editable) + Rekap Komisi (scheme != titip) + Rekap Titip (scheme =
 * titip), keduanya read-only.
 *
 * Sprint "perpanjang-daftar-pelanggan" — daftar pelanggan + alur pencatatan
 * (dulu "Catat Titip") DIPINDAH ke Daftar Pelanggan bersama
 * (App\Livewire\Customers\CustomerIndex, aksi "Perpanjang"). Komponen ini
 * tidak lagi membuat baris commission_ledger apa pun — murni tampilan.
 *
 * CREATE-ONLY tetap berlaku: tidak ada aksi edit/hapus baris komisi.
 *
 * Referrer aktif di-resolve oleh EnsureReferrerPortalAccess dan disimpan di
 * request (`referrer` attribute); komponen membacanya, lalu re-authorize
 * sendiri (defense in depth).
 */
#[Layout('layouts.referrer-portal')]
class Dashboard extends Component
{
    public int $referrerId;

    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $phone = '';

    public string $typeLabel = '';

    public bool $nameUpdated = false;

    public function mount(): void
    {
        $referrer = request()->attributes->get('referrer')
            ?? Referrer::where('user_id', auth()->id())->where('is_active', true)->first();

        abort_if($referrer === null, 403);

        $this->referrerId = $referrer->id;
        $this->name = $referrer->name;
        $this->phone = $referrer->phone;
        $this->typeLabel = $referrer->type->label();
    }

    public function updateName(): void
    {
        $referrer = $this->referrer();

        $this->validate();

        $referrer->update(['name' => $this->name]);

        $this->nameUpdated = true;
    }

    private function referrer(): Referrer
    {
        $referrer = Referrer::findOrFail($this->referrerId);

        abort_if($referrer->user_id !== auth()->id(), 403);

        return $referrer;
    }

    public function render(): View
    {
        $referrer = Referrer::findOrFail($this->referrerId);

        $ledger = $referrer->commissionLedgerEntries()
            ->with('customer:id,name')
            ->orderByDesc('id')
            ->get();

        $isTitip = fn ($e) => $e->scheme?->value === CommissionScheme::Titip->value;

        return view('livewire.referrer-portal.dashboard', [
            'commissionEntries' => $ledger->reject($isTitip)->values(),
            'titipEntries' => $ledger->filter($isTitip)->values(),
        ]);
    }
}
