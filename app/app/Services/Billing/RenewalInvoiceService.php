<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PppPackage;
use App\Models\Subscription;
use App\Services\InvoiceService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Sprint "perpanjang-invoice-asli-cetak" (keputusan Agung: Invoice mulai
 * BENAR-BENAR dipakai, BOSS App menggantikan MixRadius/SmartOLT).
 *
 * Aksi "Perpanjang" di Daftar Pelanggan sekarang membuat Invoice ASLI per
 * periode bulan, langsung ditandai LUNAS lewat InvoiceService::markPaid()
 * — SATU-SATUNYA jalur sah "invoice pelanggan lunas" — yang OTOMATIS
 * men-trigger pematangan Komisi Penjualan v0.9.5
 * (CommissionLedgerMaturityService) tanpa logic komisi baru sama sekali.
 * INI menjadi pemanggil ke-3 InvoiceService::markPaid() (dulu: PATCH manual
 * v0.3.4 + webhook Xendit v0.3.5).
 *
 * BUKAN mengaktifkan `GenerateDueInvoices` (job recurring TETAP off) — ini
 * pembuatan invoice ON-DEMAND, dipicu manual oleh aksi Perpanjang.
 *
 * `invoices.subscription_id` NOT NULL + `InvoiceService::generateForPeriod()`
 * menerima `Subscription`, jadi setiap pelanggan yang diperpanjang mendapat
 * SATU baris `subscriptions` tersembunyi (nama "Perpanjangan Manual (BOSS
 * App)", `status = Cancelled`, `monthly_amount = 0`) — di-`firstOrCreate`
 * sekali lalu dipakai ulang untuk semua perpanjangan pelanggan itu.
 * `status = Cancelled` membuatnya INVISIBLE untuk `GenerateDueInvoices`
 * (yang hanya query `status = Active`) — subscription table tetap "tidak
 * diaktifkan" dalam arti tidak ada tagihan otomatis. Nominal + deskripsi
 * asli datang per-invoice lewat `$overrideAmount`/`$overrideDescription`.
 */
class RenewalInvoiceService
{
    public function __construct(private readonly InvoiceService $invoiceService) {}

    /**
     * Buat (atau ambil yang sudah ada) Invoice untuk `$customer` pada bulan
     * `$period`, pastikan statusnya LUNAS. Idempoten: kalau invoice periode
     * itu sudah ada (dari jalur mana pun) TIDAK membuat yang kedua — kalau
     * belum Paid, dilunaskan; kalau sudah Paid, dibiarkan.
     *
     * @return array{invoice: Invoice, created: bool, newly_paid: bool}
     */
    public function issuePaidForPeriod(Customer $customer, CarbonInterface $period): array
    {
        $periodStart = Carbon::parse($period->format('Y-m-d'))->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $dueDate = $periodStart->copy();

        $subscription = $this->renewalSubscriptionFor($customer);

        $package = $customer->ppp_package_id !== null
            ? PppPackage::withoutGlobalScopes()->find($customer->ppp_package_id)
            : null;

        $amount = $package !== null ? (float) $package->sell_price : 0.0;
        $description = $package !== null
            ? "Perpanjangan layanan — {$package->name} (".$periodStart->translatedFormat('F Y').')'
            : 'Perpanjangan layanan ('.$periodStart->translatedFormat('F Y').')';

        $invoice = $this->invoiceService->generateForPeriod(
            $subscription,
            $periodStart,
            $periodEnd,
            $dueDate,
            overrideAmount: $amount,
            overrideDescription: $description,
            applyTax: (bool) $customer->tax_billable,
        );

        $created = $invoice->wasRecentlyCreated;

        if ($invoice->status === InvoiceStatus::Paid) {
            return ['invoice' => $invoice, 'created' => $created, 'newly_paid' => false];
        }

        // Draft -> Pending -> Paid. markPaid() memicu maturity Komisi
        // Penjualan (v0.9.5). WA "payment received" di-SUPPRESS (Bagian A).
        if ($invoice->status === InvoiceStatus::Draft) {
            $invoice = $this->invoiceService->markPending($invoice);
        }

        // Bagian A (v0.9.12) — JANGAN kirim WA "pembayaran diterima" per
        // invoice; Perpanjang multi-bulan akan mengirim N pesan terpisah.
        $invoice = $this->invoiceService->markPaid($invoice, notifyCustomer: false);

        return ['invoice' => $invoice, 'created' => $created, 'newly_paid' => true];
    }

    /**
     * Satu baris `subscriptions` tersembunyi per pelanggan, dipakai ulang.
     * Dibedakan dari subscription "asli" (kalau nanti ada) lewat `name`.
     */
    private function renewalSubscriptionFor(Customer $customer): Subscription
    {
        return Subscription::withoutGlobalScopes()->firstOrCreate(
            [
                'customer_id' => $customer->id,
                'name' => self::RENEWAL_SUBSCRIPTION_NAME,
            ],
            [
                'tenant_id' => $customer->tenant_id,
                'reseller_id' => $customer->reseller_id,
                'monthly_amount' => 0,
                'status' => SubscriptionStatus::Cancelled->value,
                'billing_cycle_day' => 1,
                'started_at' => Carbon::now()->startOfMonth()->toDateString(),
                'cancelled_at' => Carbon::now()->startOfMonth()->toDateString(),
            ],
        );
    }

    public const RENEWAL_SUBSCRIPTION_NAME = 'Perpanjangan Manual (BOSS App)';
}
