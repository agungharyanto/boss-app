<?php

namespace App\Services\Billing;

use App\Models\CompanyProfile;
use App\Models\Invoice;
use App\Services\Company\CompanyProfileService;
use App\Support\Filesystem\WebOwnership;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * v0.13.5.2 — render Invoice jadi PDF server-side (dompdf) untuk diunduh admin.
 * NOL kirim: tidak memanggil jalur WhatsApp apa pun. store() menyimpan ke disk
 * PRIVAT `invoice-pdfs/{tenant_id}/{invoice_id}.pdf` (prefix yang sudah
 * direservasi `WhatsappAttachment`) — DIPAKAI sub-versi kirim (v0.13.5.3) nanti,
 * TIDAK dipanggil dari jalur mengirim di versi ini.
 *
 * Kop perusahaan dari CompanyProfileService::resolveFor (fallback env
 * INVOICE_COMPANY_* sebagai lapis terakhir di service itu). Angka invoice dibaca
 * dari field tersimpan + partial Blade yang SAMA dengan browser-print
 * (billing.partials.invoice-items-totals) — satu sumber angka. Logo
 * dinormalisasi lewat GD (resize lebar maks, PNG/JPEG/WebP); gagal logo =
 * render TANPA logo, tidak pernah error.
 */
class InvoicePdfService
{
    private const LOGO_MAX_WIDTH = 240;

    public function __construct(
        private readonly CompanyProfileService $companyProfiles,
        private readonly WebOwnership $webOwnership,
    ) {}

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['customer', 'lineItems', 'reseller']);

        $company = $this->companyProfiles->resolveFor($invoice->tenant_id, $invoice->reseller_id);
        $company['footer_note'] = config('invoice.footer_note');

        $html = View::make('billing.invoice-pdf', [
            'invoice' => $invoice,
            'company' => $company,
            'logoDataUri' => $this->logoDataUri($invoice->tenant_id),
        ])->render();

        // isRemoteEnabled=false (tak fetch URL remote), chroot dibatasi ke
        // storage (meski logo di-embed base64, bukan path file).
        $pdf = Pdf::setOptions([
            'isRemoteEnabled' => false,
            'chroot' => storage_path('app'),
            'defaultFont' => 'DejaVu Sans',
            'isHtml5ParserEnabled' => true,
        ])->loadHTML($html)->setPaper('a4');

        return $pdf->output();
    }

    /**
     * Simpan PDF ke disk privat; kembalikan path. Idempoten (overwrite baris
     * yang sama). TIDAK mengirim apa pun.
     */
    public function store(Invoice $invoice): string
    {
        $path = "invoice-pdfs/{$invoice->tenant_id}/{$invoice->id}.pdf";
        $disk = Storage::disk('local');

        // Hapus dulu bila file sudah ada: file yang ditulis sweep TERJADWAL
        // berjalan sebagai root; direktori tenant milik www-data, jadi www-data
        // tetap bisa menghapusnya (izin unlink bergantung pada direktori, bukan
        // pemilik file) lalu menulis ulang — mencegah 500 "overwrite file root".
        if ($disk->exists($path)) {
            $disk->delete($path);
        }

        $disk->put($path, $this->render($invoice));

        // Bila proses ini ROOT (scheduler/worker), normalkan kepemilikan file +
        // direktori baru ke www-data; no-op total bila bukan root.
        $this->webOwnership->normalize($disk->path($path), $disk->path(''));

        return $path;
    }

    private function logoDataUri(int $tenantId): ?string
    {
        $logoPath = CompanyProfile::query()->where('tenant_id', $tenantId)->value('logo_path');

        if (! is_string($logoPath) || $logoPath === '' || ! Storage::disk('local')->exists($logoPath)) {
            return null;
        }

        try {
            $raw = Storage::disk('local')->get($logoPath);
            $img = @imagecreatefromstring($raw);
            if ($img === false) {
                return null;
            }

            $w = imagesx($img);
            $h = imagesy($img);
            if ($w > self::LOGO_MAX_WIDTH) {
                $nh = (int) max(1, round($h * self::LOGO_MAX_WIDTH / $w));
                $resized = imagecreatetruecolor(self::LOGO_MAX_WIDTH, $nh);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, self::LOGO_MAX_WIDTH, $nh, $w, $h);
                imagedestroy($img);
                $img = $resized;
            }

            ob_start();
            imagepng($img);
            $png = (string) ob_get_clean();
            imagedestroy($img);

            return 'data:image/png;base64,'.base64_encode($png);
        } catch (Throwable) {
            return null; // gagal logo = render tanpa logo
        }
    }
}
