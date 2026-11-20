<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Support\Billing\ManualPaymentInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * v0.13.5.5 (T4) — PATCH /invoices/{invoice}/paid. Semua field OPSIONAL:
 * "tanpa metode → tidak tercatat" (invoice tetap jadi Lunas, tapi tidak ada
 * baris `payments` yang ditulis — persis perilaku lama). Bila `method` diisi,
 * markPaid() menulis satu baris payments; `recorded_by` = pengguna API.
 */
class MarkInvoicePaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Invoice::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'method' => ['nullable', new Enum(PaymentMethod::class)],
            'reference_note' => ['nullable', 'string', 'max:255'],
            // Rekening tujuan harus milik tenant invoice (route-model-bound).
            'company_bank_account_id' => [
                'nullable',
                'integer',
                Rule::exists('company_bank_accounts', 'id')->where('tenant_id', $this->route('invoice')->tenant_id),
            ],
        ];
    }

    /**
     * Bangun DTO untuk InvoiceService::markPaid(). null = tidak ada metode →
     * tidak menulis baris payments.
     */
    public function toManualPaymentInput(): ?ManualPaymentInput
    {
        $method = $this->filled('method') ? PaymentMethod::from($this->string('method')->value()) : null;

        if ($method === null) {
            return null;
        }

        return new ManualPaymentInput(
            method: $method,
            recordedBy: $this->user()?->id,
            referenceNote: $this->filled('reference_note') ? $this->string('reference_note')->value() : null,
            companyBankAccountId: $this->filled('company_bank_account_id') ? $this->integer('company_bank_account_id') : null,
        );
    }
}
