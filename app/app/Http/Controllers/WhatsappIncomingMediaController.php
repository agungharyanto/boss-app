<?php

namespace App\Http\Controllers;

use App\Models\WhatsappIncomingMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * v0.13.6 — sajikan file media pesan masuk dari disk PRIVAT, HANYA lewat route
 * ber-auth + policy (tanpa URL publik). Pola `FiberNodePhotoController`: return
 * type `Symfony\...\Response` (Storage::disk('local')->response() =
 * StreamedResponse), bukan `Illuminate\Http\Response`.
 */
class WhatsappIncomingMediaController extends Controller
{
    public function show(Request $request, WhatsappIncomingMessage $incomingMessage): Response
    {
        $this->authorize('view', $incomingMessage);

        abort_unless($incomingMessage->hasServableMedia(), 404);
        abort_unless(Storage::disk('local')->exists($incomingMessage->media_file_path), 404);

        // inline supaya gambar/audio bisa di-embed <img>/<audio>; nosniff.
        return Storage::disk('local')->response(
            $incomingMessage->media_file_path,
            null,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }
}
