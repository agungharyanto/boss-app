<?php

namespace App\DataTransferObjects;

/**
 * Result of App\Services\Tax\TaxCalculationService::calculateForAmount().
 * Immutable — hand this straight to
 * App\Services\Tax\TaxCalculationService::writeLedgerEntry() to persist it,
 * don't mutate/rebuild it by hand.
 */
readonly class TaxBreakdown
{
    /**
     * @param  float  $baseAmount  the amount tax was calculated on (before tax)
     * @param  array<int, array{tax_component_id: int, code: string, name: string, rate: float, tax_amount: float, burden: string, customer_amount: float, reseller_amount: float}>  $components
     * @param  float  $totalTax  sum of every component's tax_amount
     * @param  float  $totalCustomerBorne  sum of every component's customer_amount
     * @param  float  $totalResellerBorne  sum of every component's reseller_amount
     * @param  float  $grandTotal  baseAmount + totalTax
     */
    public function __construct(
        public float $baseAmount,
        public array $components,
        public float $totalTax,
        public float $totalCustomerBorne,
        public float $totalResellerBorne,
        public float $grandTotal,
    ) {}
}
