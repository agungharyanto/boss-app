<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Company\CompanyProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * v0.19.1 — logo perusahaan disimpan di disk PRIVAT ('local'), tidak pernah
 * publicly served — jadi butuh endpoint ber-auth sendiri, sama posture
 * CommissionPaymentProofController / FiberNodePhotoController.
 *
 * Return type sengaja `Symfony\Component\HttpFoundation\Response` (parent
 * bersama), BUKAN `Illuminate\Http\Response` — `Storage::disk('local')
 * ->response()` mengembalikan `Illuminate\Http\Response` terhadap disk
 * lokal asli TAPI `StreamedResponse` terhadap `Storage::fake('local')`
 * (dipakai test), type-hint lebih sempit meledak saat ditest. Gotcha ini
 * dikonfirmasi lewat CommissionPaymentProofController (v0.16.1), lihat
 * docblock-nya.
 *
 * Content-Type diturunkan dari EKSTENSI file tersimpan (yang saat unggah
 * sudah diturunkan dari MIME tervalidasi, bukan nama file) + header
 * X-Content-Type-Options: nosniff supaya browser tidak menebak ulang tipe.
 */
class CompanyLogoController extends Controller
{
    private const EXT_TO_MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function show(Request $request, CompanyProfileService $service): Response
    {
        abort_unless($request->user()?->can('company_profile.view'), 403);

        $profile = $service->forTenant($request->user()->tenant_id);

        abort_if($profile->logo_path === null, 404);
        abort_unless(Storage::disk('local')->exists($profile->logo_path), 404);

        $ext = strtolower(pathinfo($profile->logo_path, PATHINFO_EXTENSION));
        $mime = self::EXT_TO_MIME[$ext] ?? 'application/octet-stream';

        return Storage::disk('local')->response($profile->logo_path, null, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
