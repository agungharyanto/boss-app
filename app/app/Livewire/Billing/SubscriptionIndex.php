<?php

namespace App\Livewire\Billing;

use App\Models\Customer;
use App\Models\Subscription;
use App\Services\Billing\RenewalInvoiceService;
use App\Services\Installation\WorkOrderService;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

class SubscriptionIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public bool $showCreateForm = false;

    public string $customer_id = '';

    public string $name = '';

    public string $monthly_amount = '';

    public string $reseller_package_pricing_id = '';

    public string $billing_cycle_day = '';

    /**
     * v0.26.2b — "Janji Kunjungan" (opsional). Kosong = "segera, tanpa
     * janji" (keputusan bisnis yang sudah dikunci sejak decision-gate
     * v0.26.0). Diteruskan ke WorkOrderService::createFromSubscription()
     * SEGERA setelah Subscription baru berhasil dibuat — dikunci Agung di
     * kickoff v0.26.2b: form "Registrasi Pelanggan" TIDAK PERNAH sampai ke
     * titik createFromSubscription() sama sekali (investigasi Langkah 0),
     * form "Buat Langganan" INI yang genuinely jadi titik WO lahir.
     */
    public ?string $scheduledVisitAt = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Subscription::class);
    }

    public function createSubscription(SubscriptionService $service, WorkOrderService $workOrderService): void
    {
        $this->authorize('create', Subscription::class);

        // Explicit rules (not #[Validate] attributes) — name/monthly_amount
        // are only required when reseller_package_pricing_id is blank, and
        // relying on bare $this->validate() to correctly cross-reference a
        // plain (non-#[Validate]) property for required_without has bitten
        // us before (see TaxComponentIndex's mini-form bug).
        $hasPricing = $this->reseller_package_pricing_id !== '';

        // String kosong TIDAK dianggap null oleh rule 'nullable' Laravel
        // (cuma NULL genuine yang di-skip) — dinormalisasi eksplisit di
        // sini SEBELUM validate(), sama disiplin StaffIndex::createStaff().
        $this->scheduledVisitAt = $this->scheduledVisitAt !== '' ? $this->scheduledVisitAt : null;

        $data = $this->validate([
            'customer_id' => 'required|exists:customers,id',
            'name' => $hasPricing ? 'nullable|string|max:255' : 'required|string|max:255',
            'monthly_amount' => $hasPricing ? 'nullable|numeric|min:0' : 'required|numeric|min:0',
            'billing_cycle_day' => 'required|integer|min:1|max:31',
            // v0.26.2b — kosong = "segera, tanpa janji" (valid, TIDAK
            // required). Diisi = wajib di masa depan, minimal beberapa
            // saat dari sekarang — 'after:now' cukup ketat untuk menolak
            // input jam yang sudah lewat tanpa perlu ambang tambahan.
            'scheduledVisitAt' => ['nullable', 'date', 'after:now'],
        ]);

        $customer = Customer::findOrFail($data['customer_id']);
        unset($data['customer_id']);
        $data['reseller_package_pricing_id'] = $hasPricing ? $this->reseller_package_pricing_id : null;
        $scheduledVisitAt = $data['scheduledVisitAt'] ?? null;
        unset($data['scheduledVisitAt']);

        $subscription = $service->create($customer, $data);

        // v0.26.2b — WO lahir SEGERA saat Subscription dibuat (keputusan
        // Agung di kickoff v0.26.2b, lihat docblock $scheduledVisitAt di
        // atas) — bukan aksi terpisah yang perlu authorize() sendiri,
        // murni efek samping otomatis dari create Subscription yang sudah
        // ter-authorize di atas. WO tanpa scheduledVisitAt dispatch
        // LANGSUNG (v0.26.2's hook di createFromSubscription()); WO
        // dengan scheduledVisitAt menunggu DispatchWorkOrders command
        // sesuai window offset — TIDAK dispatch immediately meski di titik
        // create ini.
        $workOrderService->createFromSubscription($subscription, $scheduledVisitAt);

        $this->reset(['customer_id', 'name', 'monthly_amount', 'reseller_package_pricing_id', 'billing_cycle_day', 'scheduledVisitAt', 'showCreateForm']);
    }

    public function suspend(int $id, SubscriptionService $service): void
    {
        $subscription = Subscription::findOrFail($id);
        $this->authorize('update', $subscription);
        $service->suspend($subscription);
    }

    public function reactivate(int $id, SubscriptionService $service): void
    {
        $subscription = Subscription::findOrFail($id);
        $this->authorize('update', $subscription);
        $service->reactivate($subscription);
    }

    public function cancelSubscription(int $id, SubscriptionService $service): void
    {
        $subscription = Subscription::findOrFail($id);
        $this->authorize('update', $subscription);
        $service->cancel($subscription);
    }

    public function generateInvoiceNow(int $id, InvoiceService $service): void
    {
        $subscription = Subscription::findOrFail($id);
        $this->authorize('update', $subscription);
        $service->generateNextForSubscription($subscription);
    }

    public function render()
    {
        return view('livewire.billing.subscription-index', [
            // v0.9.12 — sembunyikan baris "vehicle" tersembunyi milik
            // RenewalInvoiceService (Perpanjang → Invoice ASLI). Bukan
            // subscription "asli" — cuma placeholder karena
            // invoices.subscription_id NOT NULL.
            'subscriptions' => Subscription::query()
                ->where('name', '!=', RenewalInvoiceService::RENEWAL_SUBSCRIPTION_NAME)
                ->with(['customer', 'reseller'])->latest()->paginate(15),
            'customers' => Customer::orderBy('name')->limit(200)->get(),
            'canCreate' => auth()->user()->can('create', Subscription::class),
        ]);
    }
}
