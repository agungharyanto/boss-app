<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.26.2 — PER TENANT (satu baris per tenant, `tenant_id` unique) —
 * dikonfirmasi lewat investigasi Langkah 0: BUKAN platform-level
 * singleton seperti `PaymentGatewaySettings`/`WhatsappGatewaySettings`
 * (keduanya cuma 1 baris untuk seluruh deployment ISP, tidak cocok untuk
 * kebutuhan ini). Nama sengaja BUKAN mirip `WhatsappGatewaySettings` —
 * domain/scope beda total (timing dispatch/reminder Work Order, bukan
 * rate-limit pengiriman WA).
 *
 * Model ini TIDAK pakai `BelongsToTenant` — dipanggil dari command yang
 * berjalan lintas-tenant tanpa Auth (`WorkOrderDispatchCommand`, lihat
 * itu), jadi query-nya sengaja eksplisit `tenant_id` sendiri, sama
 * disiplin `App\Services\StaffService`.
 */
class WorkOrderDispatchSettings extends Model
{
    /**
     * v0.13.4.3.1 — versi teks persyaratan penutupan WO. DRAFT; dinaikkan
     * manual tiap teks diubah. Disalin ke work_order_signatures.terms_version.
     */
    public const DEFAULT_CLOSE_TERMS_VERSION = 'v1-draft';

    /**
     * v0.13.4.3.1 — teks persyaratan DEFAULT (DRAFT, BELUM disetujui final
     * Agung) yang pelanggan baca+setujui sebelum tanda tangan di form tutup
     * WO. Placeholder [..] SENGAJA dibiarkan — Agung isi lewat DB sebelum
     * dipakai pelanggan nyata (ubah `work_order_dispatch_settings.close_terms_text`
     * + naikkan `close_terms_version`). Bukan UI besar — edit baris pengaturan.
     */
    public const DEFAULT_CLOSE_TERMS = <<<'TERMS'
        SYARAT DAN KETENTUAN PEMASANGAN LAYANAN INTERNET
        Dengan menandatangani, saya selaku pelanggan menyatakan bahwa:
        1. Data diri dan alamat pemasangan yang tercantum adalah benar, dan saya berhak menyetujui pemasangan di lokasi tersebut.
        2. Pemasangan telah selesai dilakukan teknisi dan layanan berfungsi saat serah terima, termasuk hasil uji kecepatan yang ditunjukkan teknisi.
        3. Perangkat (modem/ONT dan kabel) yang terpasang adalah [PILIH: milik perusahaan dan dipinjamkan selama berlangganan / milik pelanggan]. Saya akan menjaganya dan tidak membongkar, memindahkan, atau memperjualbelikannya.
        4. Saya mengetahui paket layanan, biaya pemasangan, biaya berlangganan bulanan, dan tanggal jatuh tempo yang telah dijelaskan kepada saya, dan akan membayar tepat waktu. [PERIKSA/ISI]
        5. Kecepatan pada paket adalah kecepatan maksimal ("hingga") dan dapat dipengaruhi kondisi jaringan, perangkat saya, serta WiFi di dalam rumah.
        6. Saya tidak akan memakai layanan untuk kegiatan yang melanggar hukum, dan tidak membagikan atau menjualnya kepada pihak lain tanpa izin tertulis.
        7. Gangguan layanan saya laporkan melalui kontak resmi [ISI KONTAK] dan akan ditangani sesuai prosedur perusahaan.
        8. Nama dan kata sandi WiFi telah diserahkan kepada saya, dan saya bertanggung jawab menjaga kerahasiaannya.
        9. Saya menyetujui teknisi mengambil foto dokumentasi pemasangan (perangkat, jalur kabel, ODP, dan tampak depan lokasi) yang dipakai dan dibagikan kepada tim internal perusahaan untuk keperluan operasional dan penanganan gangguan, serta data saya diproses untuk keperluan layanan sesuai ketentuan perlindungan data pribadi.
        10. Tanda tangan ini adalah persetujuan saya atas seluruh poin di atas.
        TERMS;

    protected $fillable = [
        'tenant_id',
        'dispatch_offset_minutes',
        'reminder_time',
        'command_interval_minutes',
        'wa_group_name',
        'wa_group_jid',
        'close_terms_text',
        'close_terms_version',
        'last_dispatch_run_at',
        'customer_auto_notify_enabled',
        // v0.13.5.3 — "Mode Uji Kirim Invoice" per tenant.
        'invoice_pdf_test_mode_enabled',
        'invoice_pdf_test_recipient',
        // v0.13.5.3.1 — "Kirim PDF Invoice Otomatis" per tenant (saklar kedua).
        'invoice_pdf_auto_send_enabled',
        'invoice_pdf_auto_send_enabled_at',
        'invoice_pdf_auto_send_from',
        'invoice_pdf_auto_send_daily_cap',
    ];

    protected function casts(): array
    {
        return [
            'last_dispatch_run_at' => 'datetime',
            'customer_auto_notify_enabled' => 'boolean',
            'invoice_pdf_test_mode_enabled' => 'boolean',
            'invoice_pdf_auto_send_enabled' => 'boolean',
            'invoice_pdf_auto_send_enabled_at' => 'datetime',
            'invoice_pdf_auto_send_from' => 'date',
            'invoice_pdf_auto_send_daily_cap' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Semua default dituliskan eksplisit di sini (bukan mengandalkan
     * kolom DB default saja) — `firstOrCreate()` tidak me-re-SELECT baris
     * setelah `create()`, jadi kolom yang tidak disebut eksplisit di
     * array kedua akan tetap UNSET (bukan cuma null) pada instance
     * in-memory yang dikembalikan, meski nilai DEFAULT-nya sendiri
     * ter-apply di level DB — gotcha yang sama dengan
     * `WhatsappGatewaySettings::current()`, dikonfirmasi lewat docblock
     * method itu sebelum menulis ini.
     */
    public static function forTenant(int $tenantId): self
    {
        return static::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'dispatch_offset_minutes' => 120,
                'reminder_time' => '08:00',
                'command_interval_minutes' => 15,
                'wa_group_name' => null,
                'wa_group_jid' => null,
                'close_terms_text' => self::DEFAULT_CLOSE_TERMS,
                'close_terms_version' => self::DEFAULT_CLOSE_TERMS_VERSION,
                'last_dispatch_run_at' => null,
                // v0.13.5.1.2 — default ON (pertahankan perilaku); tenant 1
                // di-set OFF lewat langkah rilis, bukan di sini.
                'customer_auto_notify_enabled' => true,
                // v0.13.5.3 — Mode Uji default MATI; nomor uji di-set lewat
                // UI/langkah rilis, bukan di sini.
                'invoice_pdf_test_mode_enabled' => false,
                'invoice_pdf_test_recipient' => null,
                // v0.13.5.3.1 — Kirim otomatis default MATI; dinyalakan lewat
                // UI/langkah rilis (go-live di hari sepi, MixRadius mati dulu).
                'invoice_pdf_auto_send_enabled' => false,
                'invoice_pdf_auto_send_enabled_at' => null,
                'invoice_pdf_auto_send_from' => null,
                'invoice_pdf_auto_send_daily_cap' => 80,
            ]
        );
    }
}
