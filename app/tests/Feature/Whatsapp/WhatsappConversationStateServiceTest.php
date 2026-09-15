<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappConversationDirection;
use App\Models\WhatsappConversationLog;
use App\Services\Whatsapp\WhatsappConversationStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.13.2 — fondasi generik state machine percakapan WhatsApp. Test ini
 * TIDAK menguji business logic PSB/OTP apa pun (belum ada, itu v0.13.3+)
 * — murni open()/advance()/close()/getCurrent()/logMessage() + audit
 * trail Postgres.
 */
class WhatsappConversationStateServiceTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappConversationStateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WhatsappConversationStateService::class);
    }

    public function test_open_advance_close_cycle_writes_correct_audit_log(): void
    {
        $phone = '081234567890';

        $stateId = $this->service->open($phone, 'technician_otp', ['step' => 'awaiting_otp']);
        $this->service->advance($phone, 'technician_otp', 'otp_verified', ['otp_attempts' => 1]);
        $this->service->close($phone, 'technician_otp', 'selesai');

        $this->assertNull($this->service->getCurrent($phone, 'technician_otp'));
        $this->assertNull($this->service->getCurrent($phone));

        $logs = WhatsappConversationLog::where('state_id', $stateId)->orderBy('id')->get();
        $this->assertCount(2, $logs);

        $this->assertSame(WhatsappConversationDirection::System, $logs[0]->direction);
        $this->assertSame('dibuka', $logs[0]->content);
        $this->assertSame('awaiting_otp', $logs[0]->step);

        $this->assertSame(WhatsappConversationDirection::System, $logs[1]->direction);
        $this->assertSame('ditutup: selesai', $logs[1]->content);
        // step di log close() adalah step TERAKHIR sebelum ditutup (hasil advance()).
        $this->assertSame('otp_verified', $logs[1]->step);
    }

    public function test_open_a_different_scope_while_one_is_active_closes_the_old_one_first(): void
    {
        $phone = '081234567890';

        $stateIdA = $this->service->open($phone, 'scope-a', ['step' => 'step-a', 'value' => 'data-a']);
        $stateIdB = $this->service->open($phone, 'scope-b', ['step' => 'step-b']);

        // scope A otomatis ditutup, tercatat "dialihkan ke scope baru".
        $closeLogA = WhatsappConversationLog::where('state_id', $stateIdA)
            ->where('direction', WhatsappConversationDirection::System)
            ->where('content', 'ditutup: dialihkan ke scope baru')
            ->first();
        $this->assertNotNull($closeLogA);

        // getCurrent() tanpa scope resolve ke scope B (yang sekarang aktif).
        $current = $this->service->getCurrent($phone);
        $this->assertNotNull($current);
        $this->assertSame('scope-b', $current['scope']);
        $this->assertSame($stateIdB, $current['state_id']);

        // State scope A genuinely hilang — tidak bisa diakses lagi.
        $this->assertNull($this->service->getCurrent($phone, 'scope-a'));

        // Data scope B TIDAK tercampur data scope A sama sekali.
        $this->assertArrayNotHasKey('value', $current['data']);
    }

    public function test_get_current_without_scope_resolves_from_active_pointer(): void
    {
        $phone = '081234567890';
        $this->service->open($phone, 'psb', ['step' => 'menunggu_cid', 'foo' => 'bar']);

        $current = $this->service->getCurrent($phone);

        $this->assertNotNull($current);
        $this->assertSame('psb', $current['scope']);
        $this->assertSame('menunggu_cid', $current['step']);
        $this->assertSame('bar', $current['data']['foo']);
    }

    public function test_get_current_returns_null_when_nothing_active(): void
    {
        $this->assertNull($this->service->getCurrent('089900001111'));
        $this->assertNull($this->service->getCurrent('089900001111', 'any-scope'));
    }

    public function test_advance_throws_when_no_active_state(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->advance('089900001111', 'ghost-scope', 'some_step');
    }

    public function test_log_message_throws_when_no_active_state(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->logMessage('089900001111', 'ghost-scope', WhatsappConversationDirection::Inbound, 'halo');
    }

    public function test_log_message_rejects_system_direction(): void
    {
        $phone = '081234567890';
        $this->service->open($phone, 'psb');

        $this->expectException(RuntimeException::class);

        $this->service->logMessage($phone, 'psb', WhatsappConversationDirection::System, 'harusnya ditolak');
    }

    public function test_log_message_records_inbound_and_outbound_under_the_same_state_id(): void
    {
        $phone = '081234567890';
        $stateId = $this->service->open($phone, 'psb', ['step' => 'menunggu_jawaban']);

        $this->service->logMessage($phone, 'psb', WhatsappConversationDirection::Inbound, 'CID saya 123', 'menunggu_jawaban');
        $this->service->logMessage($phone, 'psb', WhatsappConversationDirection::Outbound, 'Terima kasih, CID diterima.', 'cid_diterima');

        $messages = WhatsappConversationLog::where('state_id', $stateId)
            ->whereIn('direction', [WhatsappConversationDirection::Inbound, WhatsappConversationDirection::Outbound])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $messages);
        $this->assertSame(WhatsappConversationDirection::Inbound, $messages[0]->direction);
        $this->assertSame('CID saya 123', $messages[0]->content);
        $this->assertSame(WhatsappConversationDirection::Outbound, $messages[1]->direction);
        $this->assertSame('Terima kasih, CID diterima.', $messages[1]->content);
    }

    public function test_close_is_idempotent_for_an_already_closed_state(): void
    {
        $phone = '081234567890';
        $this->service->open($phone, 'psb');
        $this->service->close($phone, 'psb', 'selesai');

        // Menutup lagi state yang sudah tidak ada — tidak boleh error, dan
        // TIDAK menulis log baru (tidak ada state_id genuine untuk dicatat).
        $countBefore = WhatsappConversationLog::count();
        $this->service->close($phone, 'psb', 'selesai lagi');
        $this->assertSame($countBefore, WhatsappConversationLog::count());
    }

    /**
     * TTL 2 jam FLAT, bukan sliding — dikonfirmasi eksplisit, JANGAN
     * diubah. advance() TIDAK boleh memperpanjang TTL ke 2 jam PENUH lagi
     * dari waktu advance() dipanggil — ia harus MEMPERTAHANKAN sisa TTL
     * dari open() pertama kali.
     */
    public function test_advance_preserves_remaining_ttl_not_reset_to_full(): void
    {
        $phone = '081234567890';

        Carbon::setTestNow('2026-01-01 10:00:00');
        $this->service->open($phone, 'psb', ['step' => 'awal']);
        $originalExpiresAt = $this->service->getCurrent($phone)['expires_at'];
        // Bukti TTL genuinely +2 jam dari open() — dibandingkan via Carbon::parse()
        // (bukan string literal timezone tertentu, app.timezone server ini
        // Asia/Jakarta bukan UTC, lihat CLAUDE.md).
        $this->assertTrue(Carbon::parse($originalExpiresAt)->equalTo(Carbon::parse('2026-01-01 12:00:00')));

        // 90 menit berlalu (masih dalam window TTL 2 jam) sebelum advance().
        Carbon::setTestNow('2026-01-01 11:30:00');
        $this->service->advance($phone, 'psb', 'lanjut');

        $afterAdvanceExpiresAt = $this->service->getCurrent($phone)['expires_at'];

        // expires_at TIDAK BERUBAH — masih persis 12:00, BUKAN direset jadi
        // 13:30 (11:30 + 2 jam) seolah TTL sliding.
        $this->assertSame($originalExpiresAt, $afterAdvanceExpiresAt);

        Carbon::setTestNow();
    }

    /**
     * advance() menolak state yang TTL-nya sudah genuinely lewat (edge
     * case race jarang — lihat docblock service) alih-alih diam-diam
     * memperpanjangnya lagi.
     */
    public function test_advance_rejects_a_state_whose_ttl_has_already_passed(): void
    {
        $phone = '081234567890';

        Carbon::setTestNow('2026-01-01 10:00:00');
        $this->service->open($phone, 'psb', ['step' => 'awal']);

        // Array cache driver (test env) sudah otomatis menganggap ini
        // expired begitu waktu lewat 2 jam — Cache::get() akan return
        // null duluan, jadi advance() akan gagal lewat jalur "tidak ada
        // state aktif" (RuntimeException yang sama), bukan lewat cabang
        // ttlSeconds<=0 secara spesifik — keduanya sama-sama benar
        // menolak, cukup diverifikasi exception-nya terlempar.
        Carbon::setTestNow('2026-01-01 12:30:00');

        $this->expectException(RuntimeException::class);
        $this->service->advance($phone, 'psb', 'lanjut');

        Carbon::setTestNow();
    }
}
