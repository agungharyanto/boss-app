<?php

namespace App\Http\Controllers;

use App\Models\FiberNodePhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 3. No precedent
 * anywhere in this codebase for serving a stored private-disk photo back
 * to a Blade view (WorkOrderPhoto, the closest sibling, is upload-only via
 * the REST API — never displayed in any web UI) — this is a small, new,
 * auth-gated streaming endpoint so <img> tags in FiberNodeForm/
 * GpsPhotoCapture/OdpEdit can actually show an already-uploaded photo.
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
