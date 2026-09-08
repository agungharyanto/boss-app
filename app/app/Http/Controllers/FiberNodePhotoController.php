<?php

namespace App\Http\Controllers;

use App\Models\FiberNodePhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 3. No precedent
 * anywhere in this codebase for serving a stored private-disk photo back
 * to a Blade view (WorkOrderPhoto, the closest sibling, is upload-only via
 * the REST API — never displayed in any web UI) — this is a small, new,
 * auth-gated streaming endpoint so <img> tags in FiberNodeForm/
 * GpsPhotoCapture/OdpEdit/the Peta Topologi marker panel can actually show
 * an already-uploaded photo.
 *
 * v0.16.1 Revisi 3 D — return type is `Symfony\Component\HttpFoundation\Response`
 * (the common parent), NOT `Illuminate\Http\Response`. `Storage::disk('local')
 * ->response()` returns a `StreamedResponse` on this server's real local
 * disk — the original `: Illuminate\Http\Response` hint threw a TypeError
 * (500 → broken <img> everywhere a fiber-node/ODP photo was shown, since
 * v0.16.0). Same fix `CommissionPaymentProofController` already carried.
 */
class FiberNodePhotoController extends Controller
{
    public function show(Request $request, FiberNodePhoto $fiber_node_photo): Response
    {
        abort_unless(
            $request->user()->can('network_infrastructure.view') || $request->user()->can('network_infrastructure.manage'),
            403
        );

        return Storage::disk('local')->response($fiber_node_photo->photo_path);
    }
}
