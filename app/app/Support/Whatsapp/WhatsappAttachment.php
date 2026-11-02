<?php

namespace App\Support\Whatsapp;

use App\Exceptions\WhatsappAttachmentException;
use App\Models\WorkOrder;
use App\Models\WorkOrderPhoto;
use Illuminate\Support\Facades\Storage;

/**
 * v0.13.5.1 — value object lampiran media WA. Dibangun HANYA oleh kode
 * tepercaya (factory dari model yang SUDAH ter-scope tenant, mis.
 * WorkOrderPhoto) — path TIDAK PERNAH berasal dari input request mentah.
 *
 * Keamanan path (defense-in-depth, dicek saat konstruksi DAN lagi saat
 * kirim di job): wajib di bawah salah satu PREFIKS yang diizinkan di disk
 * privat, tanpa "..", tanpa path absolut. Kepemilikan tenant dijamin oleh
 * KONSTRUKSI (factory menerima model milik tenant) — resolver ownership =
 * model itu sendiri; job hanya re-validasi bentuk+isi file, bukan ownership
 * (sudah dijamin saat log dibuat).
 */
final class WhatsappAttachment
{
    /**
     * Prefiks path yang diizinkan di disk 'local' (privat). Hanya area yang
     * memang berisi media yang boleh dikirim. invoice-pdfs/ direservasi
     * untuk v0.13.5.2 (PDF invoice).
     *
     * @var list<string>
     */
    public const ALLOWED_PREFIXES = ['work-order-photos/', 'invoice-pdfs/'];

    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $mime,
        public readonly string $filename,
        public readonly ?string $caption = null,
    ) {
        self::assertSafePath($path);
    }

    /**
     * Factory tepercaya dari foto Work Order. HOOK KEPEMILIKAN TENANT:
     * `$expectedTenantId` WAJIB cocok dengan tenant Work Order induk foto —
     * resolver ownership = WorkOrder (satu-satunya tempat tenant_id untuk
     * foto, karena work_order_photos anak dari work_orders). Melempar
     * WhatsappAttachmentException kalau tidak cocok — mencegah lampiran
     * tenant lain pernah masuk ke log/antrian sejak AWAL (bukan cuma saat
     * kirim). mime diturunkan dari ekstensi; divalidasi ulang dari ISI file
     * saat kirim (assertValidForSend).
     */
    public static function fromWorkOrderPhoto(WorkOrderPhoto $photo, int $expectedTenantId, ?string $caption = null): self
    {
        $workOrder = WorkOrder::withoutGlobalScopes()->find($photo->work_order_id);
        if ($workOrder === null || (int) $workOrder->tenant_id !== $expectedTenantId) {
            throw new WhatsappAttachmentException('Lampiran bukan milik tenant ini.');
        }

        $path = (string) $photo->file_path;

        return new self(
            disk: 'local',
            path: $path,
            mime: self::guessMimeFromExtension($path),
            filename: basename($path),
            caption: $caption,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            disk: (string) ($data['disk'] ?? 'local'),
            path: (string) ($data['path'] ?? ''),
            mime: (string) ($data['mime'] ?? ''),
            filename: (string) ($data['filename'] ?? 'file'),
            caption: isset($data['caption']) ? (string) $data['caption'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'disk' => $this->disk,
            'path' => $this->path,
            'mime' => $this->mime,
            'filename' => $this->filename,
            'caption' => $this->caption,
        ];
    }

    /**
     * Validasi saat kirim: path masih aman, file ADA, MIME dari ISI file
     * (finfo) ada di whitelist, ukuran <= cap. Melempar
     * WhatsappAttachmentException (permanen) kalau gagal. Mengembalikan MIME
     * hasil deteksi isi (yang dikirim ke gateway, bukan mime tersimpan).
     */
    public function assertValidForSend(): string
    {
        self::assertSafePath($this->path);

        if (! Storage::disk($this->disk)->exists($this->path)) {
            throw new WhatsappAttachmentException("Lampiran tidak ditemukan: {$this->path}");
        }

        $contents = Storage::disk($this->disk)->get($this->path);
        if ($contents === null || $contents === '') {
            throw new WhatsappAttachmentException("Lampiran kosong: {$this->path}");
        }

        $max = (int) config('services.whatsapp_gateway.media_max_bytes');
        if (strlen($contents) > $max) {
            throw new WhatsappAttachmentException('Lampiran melebihi batas ukuran.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = (string) $finfo->buffer($contents);

        $whitelist = (array) config('services.whatsapp_gateway.media_mimes');
        if (! in_array($detected, $whitelist, true)) {
            throw new WhatsappAttachmentException("MIME lampiran tidak diizinkan: {$detected}");
        }

        return $detected;
    }

    public function base64(): string
    {
        $contents = Storage::disk($this->disk)->get($this->path);

        return base64_encode((string) $contents);
    }

    public function isImage(): bool
    {
        return $this->mime === 'image/jpeg' || $this->mime === 'image/png';
    }

    private static function assertSafePath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
            throw new WhatsappAttachmentException('Path lampiran tidak aman.');
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        throw new WhatsappAttachmentException('Path lampiran di luar prefiks yang diizinkan.');
    }

    private static function guessMimeFromExtension(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
