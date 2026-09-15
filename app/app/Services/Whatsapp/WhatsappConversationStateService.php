<?php

namespace App\Services\Whatsapp;

use App\Enums\WhatsappConversationDirection;
use App\Models\WhatsappConversationLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v0.13.2 — fondasi GENERIK state machine percakapan WhatsApp. Belum ada
 * business logic PSB/OTP spesifik di sini (itu v0.13.3/v0.13.4) — service
 * ini murni buka/lanjut/tutup 1 "percakapan" per nomor+scope, plus audit
 * trail terpisah ke Postgres (whatsapp_conversation_logs, append-only).
 *
 * State AKTIF hidup di Redis via Cache:: facade (reuse App\Services\Shared\
 * ActionOtpService pattern — root .env server ini CACHE_STORE=redis, jadi
 * Cache:: facade genuinely Redis, TIDAK ADA koneksi Redis terpisah yang
 * dibuat di sini). DUA key per nomor+scope:
 *   - "wa-state:{phone}:{scope}"  — isi state (step/data/state_id/expires_at).
 *   - "wa-active-scope:{phone}"   — pointer scope MANA yang sedang aktif
 *     untuk nomor itu (1 nomor = 1 alur aktif pada satu waktu, realita
 *     percakapan WhatsApp memang linear — TIDAK ADA "2 alur paralel").
 *
 * TTL 2 JAM FLAT, BUKAN SLIDING — dikonfirmasi ulang, JANGAN diubah tanpa
 * konfirmasi eksplisit. Konsekuensinya: advance() TIDAK memperpanjang TTL
 * ke 2 jam penuh lagi setiap dipanggil — ia MEMPERTAHANKAN sisa TTL dari
 * open() pertama kali (dihitung dari `expires_at` absolut yang disimpan
 * DI DALAM value, bukan dibaca lewat TTL Redis native — Cache:: facade
 * Laravel tidak expose "update value, pertahankan TTL lama" secara
 * langsung, jadi expires_at absolut inilah satu-satunya sumber kebenaran
 * untuk menghitung TTL SISA setiap kali value ditulis ulang).
 *
 * PENTING — TTL EXPIRY REDIS ITU PASIF, TIDAK ADA HOOK OTOMATIS:
 * kalau state habis TTL-nya SENDIRI (2 jam berlalu tanpa close() manual),
 * TIDAK ADA baris audit log "ditutup: timeout" yang tertulis SECARA
 * REAL-TIME — Redis (dan Cache:: facade di atasnya) tidak memberi
 * notifikasi expiry ke aplikasi ini. Satu-satunya cara mengetahui sebuah
 * state sudah timeout adalah BELAKANGAN, tidak langsung: getCurrent()
 * dipanggil dan mengembalikan null padahal secara bisnis "harusnya masih
 * ada" — di titik itu pun TIDAK ADA cara membedakan "memang belum pernah
 * dibuka" vs "sudah timeout" tanpa cek riwayat whatsapp_conversation_logs
 * sendiri (baris "dibuka" terakhir untuk phone+scope itu, tanpa baris
 * "ditutup" sesudahnya, dalam window waktu yang masuk akal). JANGAN
 * asumsikan di kode pemanggil (v0.13.3+) bahwa "closed: timeout" akan
 * selalu tercatat otomatis — itu TIDAK PERNAH terjadi di desain ini.
 */
class WhatsappConversationStateService
{
    public const TTL_HOURS = 2;

    /**
     * Buka state baru untuk nomor+scope ini. Kalau nomor ini SUDAH punya
     * scope aktif LAIN (berbeda dari $scope), state lama itu DITUTUP DULU
     * (log system "ditutup: dialihkan ke scope baru") sebelum yang baru
     * dibuka — supaya tidak pernah ada 2 "alur aktif" untuk 1 nomor secara
     * bersamaan, sesuai realita percakapan WhatsApp yang linear.
     *
     * @param  array<string, mixed>  $initialData
     * @return string state_id yang baru dibuat — dipakai pemanggil kalau
     *                perlu, meski umumnya tidak perlu disimpan sendiri
     *                (getCurrent() selalu bisa membacanya balik).
     */
    public function open(string $phone, string $scope, array $initialData = []): string
    {
        $activeScope = Cache::get($this->activeScopeKey($phone));

        if (is_string($activeScope) && $activeScope !== '' && $activeScope !== $scope) {
            $this->close($phone, $activeScope, 'dialihkan ke scope baru');
        }

        $stateId = (string) Str::uuid();
        $expiresAt = now()->addHours(self::TTL_HOURS);

        $this->putState($phone, $scope, [
            'scope' => $scope,
            'step' => $initialData['step'] ?? null,
            'data' => $initialData,
            'state_id' => $stateId,
            'opened_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        Cache::put($this->activeScopeKey($phone), $scope, $expiresAt);

        $this->writeSystemLog($stateId, $phone, $scope, 'dibuka', $initialData['step'] ?? null);

        return $stateId;
    }

    /**
     * Lanjutkan state yang SUDAH ada — update step + merge data. TTL SISA
     * dipertahankan (bukan direset ke 2 jam penuh lagi), sesuai desain
     * "TTL 2 jam flat" di atas.
     *
     * @param  array<string, mixed>  $mergeData
     *
     * @throws RuntimeException kalau tidak ada state aktif untuk nomor+scope ini
     */
    public function advance(string $phone, string $scope, string $newStep, array $mergeData = []): void
    {
        $state = Cache::get($this->stateKey($phone, $scope));

        if (! is_array($state)) {
            throw new RuntimeException("WhatsappConversationStateService::advance() dipanggil tanpa state aktif untuk phone={$phone} scope={$scope} — sudah ditutup, atau sudah timeout (lihat docblock class ini soal TTL expiry pasif).");
        }

        $expiresAt = $this->resolveExpiresAt($state);
        $ttlSeconds = (int) now()->diffInSeconds($expiresAt, false);

        if ($ttlSeconds <= 0) {
            // Race jarang: TTL Redis SEBENARNYA sudah lewat tapi baris ini
            // sempat terbaca sebelum benar-benar hilang — jangan pernah
            // menulis ulang dengan TTL negatif/kadaluwarsa, perlakukan
            // sebagai "tidak ada state aktif", sama seperti $state null.
            throw new RuntimeException("WhatsappConversationStateService::advance() — state untuk phone={$phone} scope={$scope} sudah kedaluwarsa (TTL habis).");
        }

        $state['step'] = $newStep;
        $state['data'] = array_merge((array) ($state['data'] ?? []), $mergeData);

        $this->putState($phone, $scope, $state, $expiresAt);
    }

    /**
     * Tutup state — hapus dari Redis (state + pointer, hanya kalau pointer
     * ini genuinely masih menunjuk ke scope yang sama), tulis 1 baris log
     * system "ditutup: {reason}". Idempotent — menutup state yang sudah
     * tidak ada (sudah ditutup/timeout sebelumnya) TIDAK error, cuma no-op
     * (tidak ada state_id untuk dicatat, jadi tidak menulis log apa pun —
     * tidak ada yang genuinely "ditutup").
     */
    public function close(string $phone, string $scope, string $reason): void
    {
        $state = Cache::get($this->stateKey($phone, $scope));

        Cache::forget($this->stateKey($phone, $scope));

        if (Cache::get($this->activeScopeKey($phone)) === $scope) {
            Cache::forget($this->activeScopeKey($phone));
        }

        if (! is_array($state) || ! isset($state['state_id'])) {
            return;
        }

        $this->writeSystemLog((string) $state['state_id'], $phone, $scope, "ditutup: {$reason}", $state['step'] ?? null);
    }

    /**
     * Baca state aktif. Tanpa $scope eksplisit, resolve dulu dari pointer
     * wa-active-scope:{phone} — mengembalikan null kalau nomor ini
     * genuinely tidak punya alur aktif sama sekali. Dengan $scope
     * eksplisit, baca LANGSUNG state untuk scope itu (tidak peduli apakah
     * itu scope yang "aktif" menurut pointer — berguna untuk inspeksi,
     * meski dalam alur normal tidak pernah ada state untuk scope yang
     * bukan aktif, karena open() selalu menutup yang lama dulu).
     *
     * @return array<string, mixed>|null
     */
    public function getCurrent(string $phone, ?string $scope = null): ?array
    {
        if ($scope === null) {
            $activeScope = Cache::get($this->activeScopeKey($phone));

            if (! is_string($activeScope) || $activeScope === '') {
                return null;
            }

            $scope = $activeScope;
        }

        $state = Cache::get($this->stateKey($phone, $scope));

        return is_array($state) ? $state : null;
    }

    /**
     * Catat 1 pesan sungguhan (inbound dari pelanggan, atau outbound dari
     * sistem/bot) ke audit trail — TERPISAH dari open()/close() (yang
     * menulis log system otomatis). Dipanggil EKSPLISIT oleh pemanggil
     * v0.13.3+ yang genuinely punya isi pesan untuk dicatat — service ini
     * TIDAK PERNAH memanggilnya sendiri.
     *
     * Butuh state aktif untuk phone+scope ini (supaya baris log ikut
     * dikelompokkan ke state_id yang benar) — throw kalau tidak ada,
     * sama alasan advance().
     */
    public function logMessage(string $phone, string $scope, WhatsappConversationDirection $direction, string $content, ?string $step = null): void
    {
        if ($direction === WhatsappConversationDirection::System) {
            throw new RuntimeException('WhatsappConversationStateService::logMessage() tidak menerima direction System — itu ditulis otomatis oleh open()/close(), bukan lewat method ini.');
        }

        $state = Cache::get($this->stateKey($phone, $scope));

        if (! is_array($state) || ! isset($state['state_id'])) {
            throw new RuntimeException("WhatsappConversationStateService::logMessage() dipanggil tanpa state aktif untuk phone={$phone} scope={$scope}.");
        }

        WhatsappConversationLog::create([
            'state_id' => $state['state_id'],
            'phone_number' => $phone,
            'scope' => $scope,
            'direction' => $direction,
            'content' => $content,
            'step' => $step ?? ($state['step'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function putState(string $phone, string $scope, array $value, Carbon $expiresAt): void
    {
        Cache::put($this->stateKey($phone, $scope), $value, $expiresAt);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function resolveExpiresAt(array $state): Carbon
    {
        if (isset($state['expires_at']) && is_string($state['expires_at'])) {
            return Carbon::parse($state['expires_at']);
        }

        // Jaring pengaman — seharusnya tidak pernah terjadi (setiap state
        // yang ditulis putState() selalu punya expires_at), tapi kalau
        // genuinely hilang, jangan pernah diam-diam kasih TTL 2 jam penuh
        // baru (itu melanggar "flat, bukan sliding") — anggap sudah lewat,
        // biar advance() menolaknya sebagai kedaluwarsa.
        return now()->subSecond();
    }

    private function writeSystemLog(string $stateId, string $phone, string $scope, string $eventDescription, ?string $step): void
    {
        WhatsappConversationLog::create([
            'state_id' => $stateId,
            'phone_number' => $phone,
            'scope' => $scope,
            'direction' => WhatsappConversationDirection::System,
            'content' => $eventDescription,
            'step' => $step,
        ]);
    }

    private function stateKey(string $phone, string $scope): string
    {
        return "wa-state:{$phone}:{$scope}";
    }

    private function activeScopeKey(string $phone): string
    {
        return "wa-active-scope:{$phone}";
    }
}
