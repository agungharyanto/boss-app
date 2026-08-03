<?php

namespace App\Services;

use App\Models\InvoiceNumberSequence;
use App\Models\Reseller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-reseller invoice numbering (confirmed decision for v0.3.4): each
 * reseller — and direct-retail separately — gets its own monotonic monthly
 * counter, formatted INV/{code}/{year}/{month}/{6-digit sequence}. Direct
 * retail (no reseller) uses the literal code "DIRECT".
 */
class InvoiceNumberService
{
    /**
     * $tenantId is explicit (not derived from Auth/$reseller) since
     * $reseller is null for direct-retail invoices — there'd be no other
     * tenant signal at all in that case. Same reasoning as
     * TaxCalculationService::writeLedgerEntry() staying Auth-independent:
     * this must work correctly from a scheduled job with no authenticated
     * request context.
     */
    public function next(int $tenantId, ?Reseller $reseller, Carbon $date): string
    {
        $year = $date->year;
        $month = $date->month;

        return DB::transaction(function () use ($tenantId, $reseller, $year, $month) {
            $sequence = InvoiceNumberSequence::query()
                ->where('tenant_id', $tenantId)
                ->when(
                    $reseller === null,
                    fn ($q) => $q->whereNull('reseller_id'),
                    fn ($q) => $q->where('reseller_id', $reseller->id),
                )
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = InvoiceNumberSequence::create([
                    'tenant_id' => $tenantId,
                    'reseller_id' => $reseller?->id,
                    'year' => $year,
                    'month' => $month,
                    'last_sequence' => 0,
                ]);
            }

            $sequence->increment('last_sequence');

            $code = $reseller?->invoice_code ?? 'DIRECT';

            return sprintf('INV/%s/%04d/%02d/%06d', $code, $year, $month, $sequence->last_sequence);
        });
    }
}
