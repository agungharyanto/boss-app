<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Sprint "perpanjang-invoice-asli-cetak" — cetak Invoice, DUA tipe:
 *  - `standard` (A4/Letter) — layout invoice biasa, cocok printer biasa
 *    / "Save as PDF" dari dialog browser.
 *  - `thermal` (58mm/80mm) — layout sempit mirip struk kasir.
 *
 * Pendekatan BROWSER-PRINT (keputusan Agung): view HTML ber-CSS `@media
 * print` + `@page`, auto `window.print()`. Codebase belum punya library PDF
 * sama sekali (no dompdf/snappy) — ini nol dependency baru; kalau nanti
 * butuh file .pdf ter-download beneran, dompdf bisa ditambahkan tanpa
 * mengubah kontrak route ini.
 */
class InvoicePrintController extends Controller
{
    public function show(Request $request, Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        $format = $request->query('format') === 'thermal' ? 'thermal' : 'standard';
        $width = $request->query('width') === '58' ? '58' : '80'; // thermal only

        $invoice->load(['customer', 'lineItems', 'reseller']);

        $tenantName = Tenant::find($invoice->tenant_id)?->name ?? config('app.name');

        $company = [
            'name' => config('invoice.company_name') ?: $tenantName,
            'address' => config('invoice.company_address'),
            'phone' => config('invoice.company_phone'),
            'email' => config('invoice.company_email'),
            'footer_note' => config('invoice.footer_note'),
        ];

        $view = $format === 'thermal'
            ? 'billing.invoice-print-thermal'
            : 'billing.invoice-print-standard';

        return view($view, [
            'invoice' => $invoice,
            'company' => $company,
            'thermalWidth' => $width,
            'autoPrint' => $request->query('autoprint', '1') !== '0',
        ]);
    }
}
