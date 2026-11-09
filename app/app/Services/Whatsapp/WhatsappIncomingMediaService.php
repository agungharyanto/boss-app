<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Storage;

/**
 * v0.13.6 — INGEST media masuk dari volume bersama gateway ke disk PRIVAT
 * boss-app, dengan RE-VALIDASI keamanan (MIME dari isi byte via finfo, cap
 * ukuran, buang jenis berbahaya) + EXIF stripping (GD re-encode) untuk foto.
 *
 * Gateway sudah mengunduh+menyimpan file + sidecar ke volume bersama
 * (read-only bagi boss-app). Service ini dipakai DUA jalur:
 *   - webhook `incoming-message` (payload membawa media_path),
 *   - command reconcile (baca sidecar JSON yang webhook-nya hilang).
 * Keduanya memanggil `ingestFromSource()` yang idempoten di sisi file (nama
 * deterministik per message_id) — idempotensi BARIS dijaga `message_id` UNIQUE
 * di pemanggil.
 *
 * MIME WA-reported TIDAK dipercaya untuk keputusan keamanan — hanya dipakai
 * memilih ekstensi. Keputusan terima/tolak dari `finfo` atas isi byte.
 */
class WhatsappIncomingMediaService
{
    /** MIME yang SELALU ditolak (skrip/eksekusi/markup aktif), apa pun jenisnya. */
    private const DENY_MIMES = [
        'image/svg+xml', 'text/html', 'application/xml', 'text/xml',
        'application/javascript', 'text/javascript',
        'application/x-dosexec', 'application/x-executable', 'application/x-mach-binary',
        'application/x-sh', 'application/x-shellscript', 'text/x-shellscript',
        'application/vnd.android.package-archive', 'application/x-msdownload',
    ];

    /**
     * Salin+validasi file sumber ke disk privat. Mengembalikan:
     *   ['stored_path' => string, 'size' => int, 'mime' => string]  (sukses)
     *   ['reason' => string]                                        (ditolak)
     *
     * @param  string  $mediaType  image|document|audio|video|sticker
     * @param  string  $declaredMime  MIME dari WA (dipakai HANYA untuk ekstensi)
     * @param  string  $sourceAbsPath  path absolut file di volume gateway
     * @param  string  $scopeKey  'direct' | reseller_id (subdir penyimpanan)
     * @param  string  $messageId  WhatsApp message id (nama file deterministik)
     */
    public function ingestFromSource(string $mediaType, string $declaredMime, string $sourceAbsPath, string $scopeKey, string $messageId): array
    {
        $allowed = config('services.whatsapp_gateway.incoming_media_mimes.'.$mediaType);
        $cap = (int) config('services.whatsapp_gateway.incoming_media_caps.'.$mediaType, 0);
        if (! is_array($allowed) || $cap <= 0) {
            return ['reason' => "jenis media tidak didukung: {$mediaType}"];
        }

        if (! is_file($sourceAbsPath) || ! is_readable($sourceAbsPath)) {
            return ['reason' => 'file sumber tidak ada/tidak terbaca di volume'];
        }

        $size = (int) filesize($sourceAbsPath);
        if ($size <= 0) {
            return ['reason' => 'file sumber kosong'];
        }
        if ($size > $cap) {
            return ['reason' => "melebihi cap {$mediaType} ({$size} > {$cap})"];
        }

        $bytes = file_get_contents($sourceAbsPath);
        if ($bytes === false) {
            return ['reason' => 'gagal membaca file sumber'];
        }

        $finfoMime = $this->detectMime($bytes);

        // Denylist keras + tolak semua text/* (SVG/HTML/skrip).
        if (in_array($finfoMime, self::DENY_MIMES, true) || str_starts_with($finfoMime, 'text/')) {
            return ['reason' => "jenis berbahaya ditolak (isi: {$finfoMime})"];
        }

        if (! $this->contentMatchesType($mediaType, $finfoMime)) {
            return ['reason' => "isi file tidak cocok jenis {$mediaType} (isi: {$finfoMime})"];
        }

        $ext = $allowed[$declaredMime] ?? (string) reset($allowed);

        // EXIF stripping untuk FOTO (bukan stiker — pertahankan animasi webp).
        if ($mediaType === 'image') {
            $reencoded = $this->stripExifReencode($bytes, $finfoMime);
            if ($reencoded === null) {
                return ['reason' => 'gambar tidak bisa di-decode untuk strip EXIF'];
            }
            $bytes = $reencoded;
        }

        $storedPath = "whatsapp-incoming/{$this->sanitize($scopeKey)}/{$this->sanitize($messageId)}.{$ext}";
        Storage::disk('local')->put($storedPath, $bytes);

        return ['stored_path' => $storedPath, 'size' => strlen($bytes), 'mime' => $finfoMime];
    }

    private function detectMime(string $bytes): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }
        $mime = finfo_buffer($finfo, $bytes) ?: 'application/octet-stream';
        finfo_close($finfo);

        return strtolower(trim($mime));
    }

    /**
     * Apakah MIME hasil finfo cocok dengan jenis media. Foto/stiker/dokumen
     * KETAT (menutup svg/html/exe yang menyamar). Audio/video TOLERAN pada
     * container opaque (finfo sering mengembalikan octet-stream/ogg untuk
     * amr/opus/m4a) selama BUKAN jenis berbahaya (sudah ditolak di atas).
     */
    private function contentMatchesType(string $mediaType, string $mime): bool
    {
        return match ($mediaType) {
            'image' => in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true),
            'sticker' => $mime === 'image/webp' || $mime === 'image/png',
            'document' => $mime === 'application/pdf',
            'audio' => str_starts_with($mime, 'audio/') || in_array($mime, ['application/ogg', 'application/octet-stream'], true),
            'video' => str_starts_with($mime, 'video/') || $mime === 'application/octet-stream',
            default => false,
        };
    }

    /**
     * Decode+encode ulang via GD → buang EXIF/metadata. Format output = format
     * input (jpeg/png/webp). Null kalau bukan gambar valid (GD gagal decode) —
     * sekaligus guard "bukan gambar sungguhan".
     */
    private function stripExifReencode(string $bytes, string $mime): ?string
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }

        ob_start();
        $ok = match ($mime) {
            'image/png' => imagepng($img),
            'image/webp' => imagewebp($img),
            default => imagejpeg($img, null, 90), // jpeg
        };
        $out = ob_get_clean();
        imagedestroy($img);

        if (! $ok || $out === false || $out === '') {
            return null;
        }

        return $out;
    }

    private function sanitize(string $s): string
    {
        $out = preg_replace('/[^A-Za-z0-9._-]/', '_', $s) ?? '_';

        return ($out === '' || $out === '.' || $out === '..') ? '_' : $out;
    }
}
