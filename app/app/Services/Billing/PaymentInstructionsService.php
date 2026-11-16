<?php

namespace App\Services\Billing;

use App\Models\CompanyProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Company\CompanyBankAccountService;
use App\Services\Company\CompanyProfileService;

/**
 * v0.13.5.4 — merakit instruksi "Cara Pembayaran" untuk invoice, berdasarkan
 * saklar metode tenant. DIPAKAI saat RENDER (PDF + caption WA).
 *
 * ATURAN KERAS: jalur render TIDAK PERNAH membuat tautan bayar / memanggil
 * Xendit. Untuk metode gateway, HANYA membaca baris `payments` yang SUDAH ADA
 * dan masih berlaku (raw_response.invoice_url) — tidak pernah createPaymentFor.
 * Ini sengaja dijaga arsitektur (lihat PaymentInstructionsArchitectureTest).
 */
class PaymentInstructionsService
{
    public function __construct(
        private readonly CompanyBankAccountService $bankAccounts,
        private readonly CompanyProfileService $companyProfiles,
    ) {}

    /**
     * @return array{
     *   manual: array{enabled: bool, accounts: array<int, array{bank_name:string,account_number:string,account_holder:string,is_default:bool}>},
     *   gateway: array{enabled: bool, link: ?string},
     *   agent: array{enabled: bool, name: ?string, contact: ?string},
     *   cs: string,
     *   has_any: bool,
     * }
     */
    public function forInvoice(Invoice $invoice): array
    {
        $profile = CompanyProfile::query()->where('tenant_id', $invoice->tenant_id)->first();

        $manualEnabled = $profile === null ? true : (bool) $profile->pay_manual_transfer_enabled;
        $gatewayEnabled = (bool) ($profile?->pay_gateway_enabled ?? false);
        $agentEnabled = (bool) ($profile?->pay_agent_enabled ?? false);

        $accounts = [];
        if ($manualEnabled) {
            foreach ($this->bankAccounts->activeAccounts($invoice->tenant_id) as $acc) {
                $accounts[] = [
                    'bank_name' => $acc->bank_name,
                    'account_number' => $acc->account_number,
                    'account_holder' => $acc->account_holder,
                    'is_default' => $acc->is_default,
                ];
            }
        }

        $link = $gatewayEnabled ? $this->existingGatewayLink($invoice) : null;

        $agentName = null;
        $agentContact = null;
        if ($agentEnabled) {
            $referrer = $invoice->customer?->referredBy;
            if ($referrer !== null) {
                $agentName = $referrer->name;
                $agentContact = $referrer->phone;
            }
        }

        $hasAny = ($manualEnabled && $accounts !== [])
            || ($gatewayEnabled && $link !== null)
            || ($agentEnabled && $agentName !== null);

        return [
            'manual' => ['enabled' => $manualEnabled, 'accounts' => $accounts],
            'gateway' => ['enabled' => $gatewayEnabled, 'link' => $link],
            'agent' => ['enabled' => $agentEnabled, 'name' => $agentName, 'contact' => $agentContact],
            'cs' => $this->companyProfiles->resolveCs($profile, $invoice->tenant_id, $invoice->reseller_id),
            'has_any' => $hasAny,
        ];
    }

    /**
     * Teks ringkas satu-blok untuk variabel caption {payment_instructions}.
     * Prioritas: transfer manual (rekening default) → gateway → agen.
     * Kosong bila tak ada metode/data (renderer caption menangani var kosong).
     */
    public function captionText(Invoice $invoice): string
    {
        $data = $this->forInvoice($invoice);

        if ($data['manual']['enabled'] && $data['manual']['accounts'] !== []) {
            $a = $data['manual']['accounts'][0];

            return 'Transfer ke '.$a['bank_name'].' '.$a['account_number'].' a.n. '.$a['account_holder'].'.';
        }

        if ($data['gateway']['enabled'] && $data['gateway']['link'] !== null) {
            return 'Bayar online: '.$data['gateway']['link'];
        }

        if ($data['agent']['enabled'] && $data['agent']['name'] !== null) {
            $c = $data['agent']['contact'] !== null ? ' ('.$data['agent']['contact'].')' : '';

            return 'Pembayaran via agen: '.$data['agent']['name'].$c.'.';
        }

        return '';
    }

    /**
     * Hanya membaca baris payments yang SUDAH ada. Tidak pernah memanggil Xendit.
     */
    private function existingGatewayLink(Invoice $invoice): ?string
    {
        $payments = Payment::query()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('id')
            ->get();

        foreach ($payments as $payment) {
            if ($payment->status === 'failed') {
                continue;
            }
            $url = $payment->raw_response['invoice_url'] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }
}
