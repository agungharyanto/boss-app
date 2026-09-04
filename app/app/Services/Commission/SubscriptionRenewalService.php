<?php

namespace App\Services\Commission;

use App\Enums\ReferrerType;
use App\Models\Customer;
use App\Models\CustomerTimelineEntry;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sprint "perpanjang-daftar-pelanggan" — aksi "Perpanjang" di Daftar
 * Pelanggan. Mencatat perpanjangan langganan (opsional ganti paket) +
 * komisi Titip kalau acting user adalah Referrer Sales/Freelance.
 *
 * BATASAN KERAS (dikonfirmasi berulang di CLAUDE.md untuk seluruh cluster
 * komisi):
 *  - HANYA data BOSS App. TIDAK ADA satu pun panggilan ke NAS / RouterOS /
 *    FreeRADIUS / MixRadius. Perpanjangan layanan yang sebenarnya tetap
 *    proses manual admin di luar BOSS App.
 *  - `subscriptions` / `SubscriptionService` / `GenerateDueInvoices` TIDAK
 *    disentuh — `customers.ppp_package_id` sepenuhnya independen dari
 *    `subscriptions` (lihat catatan v0.9.4 di CLAUDE.md).
 *  - Ganti paket = update `customers.ppp_package_id` saja.
 *  - Komisi: `ReferrerType::Sales` / `ReferrerType::Freelance` → baris
 *    `commission_ledger` scheme=titip status=eligible, nominal dari
 *    `CommissionRate.titip_amount` paket customer SAAT INI (setelah ganti
 *    paket kalau ada). `Teknisi` / `Admin` / tidak tertaut Referrer →
 *    tidak ada komisi, hanya catatan timeline.
 *  - CREATE-ONLY: tidak ada jalur edit/hapus baris `commission_ledger`
 *    lewat aksi ini.
 */
class SubscriptionRenewalService
{
    public function __construct(private readonly ReferrerTitipService $titip) {}

    /**
     * @return array{
     *     package_changed: bool,
     *     package_from: ?string,
     *     package_to: ?string,
     *     commission_created: bool,
     *     commission_amount: ?float,
     *     commission_skipped_reason: ?string,
     * }
     *
     * @throws \RuntimeException kalau paket baru tidak valid untuk tenant customer
     */
    public function renew(User $actor, Customer $customer, ?int $newPppPackageId): array
    {
        $originalPackageId = $customer->ppp_package_id;
        $fromName = $customer->pppPackage?->name;

        $newPackage = null;
        if ($newPppPackageId !== null && $newPppPackageId !== $originalPackageId) {
            $newPackage = PppPackage::query()
                ->where('id', $newPppPackageId)
                ->where('tenant_id', $customer->tenant_id)
                ->where('is_active', true)
                ->first();

            if ($newPackage === null) {
                throw new \RuntimeException('Paket yang dipilih tidak valid atau tidak aktif.');
            }
        }

        $referrer = Referrer::query()
            ->where('user_id', $actor->id)
            ->where('is_active', true)
            ->first();

        $result = [
            'package_changed' => false,
            'package_from' => $fromName,
            'package_to' => $fromName,
            'commission_created' => false,
            'commission_amount' => null,
            'commission_skipped_reason' => null,
        ];

        DB::transaction(function () use (&$result, $actor, $customer, $newPackage, $originalPackageId, $fromName, $referrer): void {
            if ($newPackage !== null) {
                $customer->update(['ppp_package_id' => $newPackage->id]);
                $customer->refresh();

                $result['package_changed'] = true;
                $result['package_to'] = $newPackage->name;
            }

            $eligibleType = $referrer !== null
                && in_array($referrer->type, [ReferrerType::Sales, ReferrerType::Freelance], true);

            if (! $eligibleType) {
                $result['commission_skipped_reason'] = $referrer === null
                    ? 'Akun tidak tertaut ke Referral — tidak ada komisi.'
                    : "Tipe Referral {$referrer->type->label()} tidak menghasilkan komisi Titip.";
            } else {
                $availability = $this->titip->availabilityFor($customer);

                if (! $availability['available']) {
                    $result['commission_skipped_reason'] = $availability['reason'] ?? 'Rate komisi Titip tidak tersedia untuk paket ini.';
                } else {
                    $ledger = $this->titip->recordForRenewal($referrer, $customer);
                    $result['commission_created'] = true;
                    $result['commission_amount'] = (float) $ledger->amount;
                }
            }

            CustomerTimelineEntry::create([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'event_type' => 'subscription_renewed',
                'description' => $result['package_changed']
                    ? "Perpanjangan dicatat, paket diubah dari {$fromName} ke {$result['package_to']}"
                    : 'Perpanjangan dicatat (paket tidak diubah)',
                'changes' => [
                    'package_from_id' => $originalPackageId,
                    'package_to_id' => $customer->ppp_package_id,
                    'package_from' => $fromName,
                    'package_to' => $result['package_to'],
                    'commission_created' => $result['commission_created'],
                    'commission_amount' => $result['commission_amount'],
                    'referrer_id' => $referrer?->id,
                ],
                'actor_id' => $actor->id,
            ]);
        });

        return $result;
    }
}
