<?php

namespace App\Http\Resources;

use App\Enums\CommissionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A referred Customer, annotated with EVERY `commission_ledger` row created
 * for it.
 *
 * v0.9.6 — sebelumnya hanya menampilkan `->first()` (asumsi 1 baris komisi
 * per pelanggan). Sejak v0.9.5 (append-per-invoice) dan v0.9.6 (Titip)
 * satu pelanggan bisa punya N baris komisi, jadi `commissions[]` sekarang
 * berisi SEMUA baris + ringkasan totalnya. Perubahan bentuk respons yang
 * disengaja (breaking) — `commission_status`/`commission_amount` tunggal
 * dihapus.
 */
class ReferrerReferralResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entries = $this->commissionLedgerEntries->sortBy('id')->values();

        $earnedStatuses = [
            CommissionStatus::Eligible->value,
            CommissionStatus::Approved->value,
            CommissionStatus::Paid->value,
        ];

        return [
            'customer_id' => $this->id,
            'customer_name' => $this->name,
            'registration_status' => $this->registration_status->value,
            'registration_status_label' => $this->registration_status->label(),
            'registered_at' => $this->created_at->toIso8601String(),
            'commissions' => $entries->map(fn ($c) => [
                'id' => $c->id,
                'scheme' => $c->scheme?->value,
                'scheme_label' => $c->scheme?->label(),
                'amount' => $c->amount,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'payment_period' => $c->payment_period?->toDateString(),
                'invoice_id' => $c->invoice_id,
                'created_at' => $c->created_at->toIso8601String(),
            ])->all(),
            'commission_total_earned' => (string) $entries
                ->filter(fn ($c) => in_array($c->status->value, $earnedStatuses, true))
                ->sum(fn ($c) => (float) ($c->amount ?? 0)),
        ];
    }
}
