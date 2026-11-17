<?php

namespace App\Livewire\Settings;

use App\Models\CompanyBankAccount;
use App\Models\CompanyProfile;
use App\Services\Company\CompanyBankAccountService;
use App\Services\Company\CompanyProfileService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use RuntimeException;

/**
 * v0.13.5.4 — kartu "Rekening Pembayaran" + "Metode Pembayaran di Invoice",
 * di-embed di halaman Pengaturan Perusahaan. Logika di
 * CompanyBankAccountService; komponen hanya UI. Izin lewat CompanyProfilePolicy
 * (company_profile.view/.manage).
 */
class PaymentSettings extends Component
{
    use AuthorizesRequests;

    // Form tambah rekening.
    public string $newBankName = '';

    public string $newAccountNumber = '';

    public string $newAccountHolder = '';

    // Edit inline.
    public ?int $editingId = null;

    public string $editBankName = '';

    public string $editAccountNumber = '';

    public string $editAccountHolder = '';

    // Saklar metode.
    public bool $manualEnabled = true;

    public bool $gatewayEnabled = false;

    public bool $agentEnabled = false;

    public bool $showLogs = false;

    public function mount(CompanyProfileService $profiles): void
    {
        $this->authorize('view', CompanyProfile::class);
        $profile = CompanyProfile::query()->where('tenant_id', auth()->user()->tenant_id)->first();
        $this->manualEnabled = $profile === null ? true : (bool) $profile->pay_manual_transfer_enabled;
        $this->gatewayEnabled = (bool) ($profile?->pay_gateway_enabled ?? false);
        $this->agentEnabled = (bool) ($profile?->pay_agent_enabled ?? false);
    }

    public function addBank(CompanyBankAccountService $service): void
    {
        $this->authorize('manage', CompanyProfile::class);
        try {
            $service->create(auth()->user()->tenant_id, [
                'bank_name' => $this->newBankName,
                'account_number' => $this->newAccountNumber,
                'account_holder' => $this->newAccountHolder,
            ], auth()->id());
        } catch (ValidationException $e) {
            throw $e;
        }
        $this->reset(['newBankName', 'newAccountNumber', 'newAccountHolder']);
        session()->flash('bank-status', 'Rekening ditambahkan.');
    }

    public function startEdit(int $id): void
    {
        $this->authorize('manage', CompanyProfile::class);
        $acc = $this->ownAccount($id);
        $this->editingId = $acc->id;
        $this->editBankName = $acc->bank_name;
        $this->editAccountNumber = $acc->account_number;
        $this->editAccountHolder = $acc->account_holder;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editBankName', 'editAccountNumber', 'editAccountHolder']);
    }

    public function saveEdit(CompanyBankAccountService $service): void
    {
        $this->authorize('manage', CompanyProfile::class);
        $acc = $this->ownAccount((int) $this->editingId);
        $service->update($acc, [
            'bank_name' => $this->editBankName,
            'account_number' => $this->editAccountNumber,
            'account_holder' => $this->editAccountHolder,
        ], auth()->id());
        $this->cancelEdit();
        session()->flash('bank-status', 'Rekening diperbarui.');
    }

    public function deleteBank(int $id, CompanyBankAccountService $service): void
    {
        $this->authorize('manage', CompanyProfile::class);
        $acc = $this->ownAccount($id);
        try {
            $service->delete($acc, auth()->id());
            session()->flash('bank-status', 'Rekening dihapus.');
        } catch (RuntimeException $e) {
            session()->flash('bank-error', $e->getMessage());
        }
    }

    public function makeDefault(int $id, CompanyBankAccountService $service): void
    {
        $this->authorize('manage', CompanyProfile::class);
        $acc = $this->ownAccount($id);
        try {
            $service->makeDefault($acc, auth()->id());
            session()->flash('bank-status', 'Rekening default diubah.');
        } catch (RuntimeException $e) {
            session()->flash('bank-error', $e->getMessage());
        }
    }

    public function toggleActive(int $id, CompanyBankAccountService $service): void
    {
        $this->authorize('manage', CompanyProfile::class);
        $acc = $this->ownAccount($id);
        try {
            $service->setActive($acc, ! $acc->is_active, auth()->id());
            session()->flash('bank-status', $acc->is_active ? 'Rekening dinonaktifkan.' : 'Rekening diaktifkan.');
        } catch (RuntimeException $e) {
            session()->flash('bank-error', $e->getMessage());
        }
    }

    public function saveSwitches(CompanyProfileService $profiles): void
    {
        $this->authorize('manage', CompanyProfile::class);
        $profile = $profiles->forTenant(auth()->user()->tenant_id);
        $profile->update([
            'pay_manual_transfer_enabled' => $this->manualEnabled,
            'pay_gateway_enabled' => $this->gatewayEnabled,
            'pay_agent_enabled' => $this->agentEnabled,
            'updated_by' => auth()->id(),
        ]);
        session()->flash('method-status', 'Metode pembayaran disimpan.');
    }

    private function ownAccount(int $id): CompanyBankAccount
    {
        return CompanyBankAccount::query()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->findOrFail($id);
    }

    public function render(CompanyBankAccountService $service)
    {
        return view('livewire.settings.payment-settings', [
            'accounts' => $service->allAccounts(auth()->user()->tenant_id),
            'logs' => $this->showLogs ? $service->logsFor(auth()->user()->tenant_id) : collect(),
        ]);
    }
}
