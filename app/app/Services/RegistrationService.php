<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\RegistrationChannel;
use App\Enums\RegistrationStatus;
use App\Enums\WhatsappEventType;
use App\Models\Customer;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Services\Installation\WorkOrderService;
use App\Services\Whatsapp\WhatsappGatewayService;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    public function __construct(
        private readonly WhatsappGatewayService $whatsappService,
        private readonly CommissionAttributionService $commissionAttribution,
        private readonly SubscriptionService $subscriptionService,
        private readonly WorkOrderService $workOrderService,
    ) {}

    /**
     * Registers a new customer, optionally attributed to a referring referrer.
     *
     * When $registeredBy is given, the customer's registration_channel matches
     * that referrer's own type (sales/teknisi/freelance), and a pending
     * commission_ledger row is created for them. With no referrer (an admin
     * registering with no referral picked), registration_channel falls back
     * to 'admin' and no commission_ledger row is created at all.
     *
     * v0.9.4 — $scheme (nullable 'recurring'/'limited_count'): kalau diisi
     * DAN customer.ppp_package_id punya CommissionRate aktif dengan amount
     * untuk skema itu, commission_ledger.scheme + amount ikut terisi. Kalau
     * $scheme null (admin skip pilihan skema), ledger tetap dibuat Pending
     * dengan scheme+amount NULL — persis perilaku lama, tidak maksa.
     *
     * v0.26.2c — $subscription (nullable): kalau diisi, registrasi jadi
     * SATU PINTU — Customer + Subscription + WorkOrder dibuat dalam SATU
     * transaksi DB (keputusan Agung, "Registrasi Pelanggan Jadi Satu
     * Pintu"; menggantikan v0.26.2b yang menaruh Janji Kunjungan di form
     * Buat Langganan terpisah). Shape:
     * `['ppp_package_id' => int, 'scheduled_visit_at' => ?string]`.
     * `SubscriptionService::create()` dipanggil lewat jalur "direct-retail"
     * (name/monthly_amount dari PppPackage->name/->sell_price — BUKAN
     * reseller_package_pricing_id, tabel berbeda, lihat investigasi v0.26.2c)
     * — billing_cycle_day di-default ke tanggal hari registrasi
     * (`min(now()->day, 28)`, clamp supaya aman untuk bulan pendek),
     * keputusan eksplisit Agung karena `ppp_packages` tidak punya kolom
     * siklus tagihan apa pun. `WorkOrderService::createFromSubscription()`
     * dipanggil DI DALAM transaksi yang sama (nested — Laravel otomatis
     * pakai savepoint) dengan `scheduled_visit_at` sebagai `$scheduledAt`
     * — null berarti WO dispatch SEGERA (hook v0.26.2 yang sudah ada di
     * `createFromSubscription()` sendiri, tidak diduplikasi di sini). Kalau
     * `$subscription` null (dipertahankan untuk caller lama yang belum
     * migrasi ke alur satu-pintu), perilaku PERSIS seperti sebelumnya —
     * tidak ada Subscription/WorkOrder yang dibuat.
     *
     * @param  array{name: string, address: string, phone_number: string, nik?: ?string, latitude?: ?float, longitude?: ?float, package?: ?string, ppp_package_id?: ?int}  $data
     * @param  ?array{ppp_package_id: int, scheduled_visit_at: ?string}  $subscription
     */
    public function register(array $data, ?Referrer $registeredBy = null, ?string $scheme = null, ?array $subscription = null): Customer
    {
        $customer = DB::transaction(function () use ($data, $registeredBy, $scheme, $subscription) {
            $customer = Customer::create([
                ...$data,
                'status' => CustomerStatus::Prospek,
                'registration_status' => RegistrationStatus::Registered,
                'registration_channel' => $registeredBy
                    ? RegistrationChannel::from($registeredBy->type->value)
                    : RegistrationChannel::Admin,
                'referred_by_referrer_id' => $registeredBy?->id,
            ]);

            if ($registeredBy !== null) {
                $this->commissionAttribution->createPendingLedger($customer, $registeredBy, $scheme);
            }

            if ($subscription !== null) {
                $package = PppPackage::findOrFail($subscription['ppp_package_id']);

                $newSubscription = $this->subscriptionService->create($customer, [
                    'name' => $package->name,
                    'monthly_amount' => $package->sell_price,
                    'billing_cycle_day' => min(now()->day, 28),
                ]);

                $this->workOrderService->createFromSubscription($newSubscription, $subscription['scheduled_visit_at'] ?? null);
            }

            return $customer;
        });

        // v0.4.0 WhatsApp Gateway hook — outside the transaction above so a
        // notification never fires for a registration that ends up rolled
        // back. This flow never sets customer.reseller_id (confirmed,
        // accepted as-is — see docs/ROADMAP.md), so session_key always
        // resolves to "direct" here.
        $this->whatsappService->buildAndQueue(WhatsappEventType::CustomerRegistered, $customer);

        return $customer;
    }
}
