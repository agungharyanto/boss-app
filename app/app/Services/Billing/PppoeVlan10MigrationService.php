<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\InvoiceService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * v0.12.2 Track A — migrasi 527 pelanggan PPPoE VLAN10 (`test-x86-bajastu`)
 * dari mixradius ke BOSS App sebagai sumber tagihan. Sengaja BUKAN
 * `RenewalInvoiceService::renewalSubscriptionFor()` (subscription
 * tersembunyi `status=Cancelled`, invisible ke `GenerateDueInvoices`) —
 * di sini `subscriptions.status = Active` SECARA SENGAJA (keputusan Agung
 * eksplisit): 527 pelanggan ini akan mulai ditagih otomatis oleh
 * `GenerateDueInvoices` (dijadwalkan harian, lihat routes/console.php)
 * begitu `due_date` mereka jatuh — BOSS App menjadi sumber tagihan
 * sesungguhnya untuk mereka mulai sekarang, bukan cuma pencatatan
 * historis. Subscription per-customer diberi nama tetap
 * (`SUBSCRIPTION_NAME`) — dicari lewat itu (`firstOrNew`), bukan
 * `firstOrCreate` mentah, supaya menjalankan migrasi ini dua kali
 * (idempoten) meng-UPDATE baris yang sama alih-alih membuat duplikat.
 *
 * `ppp_package_id` yang ditulis ke `customers` (bukan `subscriptions` —
 * tabel itu tidak punya kolom itu) BOLEH BEDA dari Framed-Pool yang sudah
 * ditulis ke `radcheck`/`radreply` di Langkah 4 — ini disengaja: paket
 * ASLI pelanggan (dari CSV) harus tetap tercatat meski Framed-Pool
 * mereka saat ini cuma fallback `PPPoE-Remote` (tier 1/2).
 *
 * WA `payment_received` SELALU di-suppress
 * (`InvoiceService::markPaid($invoice, notifyCustomer: false)`) — migrasi
 * data historis batch besar, bukan pembayaran nyata yang baru terjadi;
 * mengirim WA "pembayaran diterima" ke ratusan pelanggan sekaligus akan
 * jadi spam yang salah konteks. Sama alasan v0.9.12 Bagian A untuk
 * `RenewalInvoiceService`.
 */
class PppoeVlan10MigrationService
{
    public const SUBSCRIPTION_NAME = 'Migrasi PPPoE VLAN10 (Track A)';

    public function __construct(private readonly InvoiceService $invoiceService) {}

    /**
     * @return array{subscription_id: int, invoice_id: int, invoice_number: string, invoice_status: string, invoice_created: bool}
     */
    public function migrateOne(
        Customer $customer,
        ?int $pppPackageId,
        ?string $subscriptionName,
        float $amount,
        string $lineDescription,
        ?CarbonInterface $startedAt,
        ?CarbonInterface $expiresAt,
        int $billingCycleDay,
        bool $applyTax,
    ): array {
        if ($pppPackageId !== null && $customer->ppp_package_id !== $pppPackageId) {
            $customer->update(['ppp_package_id' => $pppPackageId]);
        }

        $subscription = Subscription::withoutGlobalScopes()->firstOrNew([
            'customer_id' => $customer->id,
            'name' => self::SUBSCRIPTION_NAME,
        ]);

        $subscription->fill([
            'tenant_id' => $customer->tenant_id,
            'reseller_id' => $customer->reseller_id,
            'name' => $subscriptionName ?? self::SUBSCRIPTION_NAME,
            'monthly_amount' => $amount,
            'status' => SubscriptionStatus::Active->value,
            'billing_cycle_day' => $billingCycleDay,
            'started_at' => ($startedAt ?? Carbon::now())->toDateString(),
            'expires_at' => $expiresAt?->toDateString(),
        ])->save();

        $periodEnd = ($expiresAt ?? Carbon::now())->copy()->startOfDay();
        $periodStart = $periodEnd->copy()->subMonthNoOverflow()->addDay();
        $dueDate = $periodEnd->copy();

        $invoice = $this->invoiceService->generateForPeriod(
            $subscription,
            $periodStart,
            $periodEnd,
            $dueDate,
            overrideAmount: $amount,
            overrideDescription: $lineDescription,
            applyTax: $applyTax,
        );

        $created = $invoice->wasRecentlyCreated;

        if ($invoice->status === InvoiceStatus::Draft) {
            $invoice = $this->invoiceService->markPending($invoice);
        }

        $isPaid = $expiresAt === null || ! $expiresAt->lt(Carbon::now()->startOfDay());

        if ($invoice->status === InvoiceStatus::Pending) {
            $invoice = $isPaid
                ? $this->invoiceService->markPaid($invoice, notifyCustomer: false)
                : $this->invoiceService->markOverdue($invoice);
        }

        return [
            'subscription_id' => $subscription->id,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_status' => $invoice->status->value,
            'invoice_created' => $created,
        ];
    }
}
