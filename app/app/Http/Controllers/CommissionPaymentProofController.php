<?php

namespace App\Http\Controllers;

use App\Models\CommissionLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * v0.9.11 (Payout Komisi) — mirror `FiberNodePhotoController` (v0.16.0):
 * `payment_proof_path` disimpan di disk 'local' (privat, tidak pernah
 * publicly served), jadi butuh endpoint ber-auth sendiri untuk menampilkan
 * bukti bayar yang sudah diunggah, bukan `<img src>` langsung ke storage
 * publik.
 *
 * Return type sengaja `Symfony\Component\HttpFoundation\Response` (parent
 * bersama), BUKAN `Illuminate\Http\Response` seperti yang dipakai
 * `FiberNodePhotoController` — `Storage::disk('local')->response()`
 * mengembalikan `Illuminate\Http\Response` terhadap disk lokal ASLI, TAPI
 * `Symfony\Component\HttpFoundation\StreamedResponse` terhadap
 * `Storage::fake('local')` (dipakai test ini sendiri) — type-hint yang
 * lebih sempit meledak begitu ditest, meski bekerja normal di produksi.
 * Ditemukan langsung lewat `CommissionPaymentProofControllerTest`, bukan
 * ditebak — `FiberNodePhotoController` kemungkinan besar punya bug laten
 * yang sama, tidak disentuh di sini (di luar scope sprint ini, belum
 * pernah ketahuan karena belum ada test untuknya).
 */
class CommissionPaymentProofController extends Controller
{
    public function show(Request $request, CommissionLedger $commission_ledger): Response
    {
        abort_unless(
            $request->user()->can('commission_ledger.view') || $request->user()->can('commission_ledger.manage'),
            403
        );

        abort_if($commission_ledger->payment_proof_path === null, 404);

        return Storage::disk('local')->response($commission_ledger->payment_proof_path);
    }
}
