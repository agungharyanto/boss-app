<?php

namespace App\Models;

use App\Enums\HotspotDurationUnit;
use App\Enums\MikrotikSyncStatus;
use App\Enums\NetworkProfileGroupType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PppPackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * v0.14.5 — Profil PPP, a sellable monthly subscription package catalog
 * entry (equivalent to Profil Hotspot, v0.14.4, but for PPPoE customers).
 * See the migration's own docblock for the full "why no mikrotik_profile_name,
 * why no profile_type/limit_type/quota_*" reasoning.
 */
class PppPackage extends Model
{
    /** @use HasFactory<PppPackageFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'network_profile_group_id',
        'bandwidth_profile_id',
        'name',
        'visible_to_reseller',
        'cost_price',
        'sell_price',
        'promo_price',
        'tax_percent',
        'active_duration_value',
        'active_duration_unit',
        'shared_users',
        'priority',
        'login_days',
        'login_start_time',
        'login_end_time',
        'is_active',
        // mikrotik_sync_* — never part of a FormRequest's validated()
        // output, only written by markSync*() below, called from the push
        // Job. Same convention as HotspotPackage/CustomerIpPool/
        // NetworkProfileGroup.
        'mikrotik_sync_status',
        'mikrotik_synced_at',
        'mikrotik_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'visible_to_reseller' => 'boolean',
            'cost_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'promo_price' => 'decimal:2',
            'tax_percent' => 'decimal:2',
            'active_duration_unit' => HotspotDurationUnit::class,
            // Revisi Prioritas Dropdown — dulu string bebas ('Default'),
            // sekarang integer 1-8 (RouterOS Queue Priority) — lihat
            // App\Support\RouterOsQueuePriority untuk verifikasi range/
            // default dan mekanisme push-nya.
            'priority' => 'integer',
            'login_days' => 'array',
            'is_active' => 'boolean',
            'mikrotik_sync_status' => MikrotikSyncStatus::class,
            'mikrotik_synced_at' => 'datetime',
        ];
    }

    public function networkProfileGroup(): BelongsTo
    {
        return $this->belongsTo(NetworkProfileGroup::class);
    }

    public function bandwidthProfile(): BelongsTo
    {
        return $this->belongsTo(BandwidthProfile::class);
    }

    /**
     * v0.9.3 — konfigurasi rate komisi untuk paket ini (satu baris per
     * paket, di-enforce oleh unique parsial di commission_rates). HasOne,
     * bukan HasMany. Null selama admin belum mengatur rate lewat
     * /commission-rates.
     */
    public function commissionRate(): HasOne
    {
        return $this->hasOne(CommissionRate::class);
    }

    /**
     * Stable per-row identifier for this Profil PPP's OWN `/ppp profile`
     * object — `/ppp profile` genuinely supports `comment` (confirmed via
     * a live add/set round trip, same as NetworkProfileGroup's own PPP
     * push), so — unlike HotspotPackage's mikrotikLookupName()/
     * mikrotik_profile_name workaround — a rename just works: the SAME
     * comment always resolves the SAME router object regardless of what
     * ->name currently is.
     */
    public function mikrotikComment(): string
    {
        return "BOSS App - PPP Package #{$this->id}";
    }

    /**
     * `active_duration_value = 0` berarti **Unlimited / tanpa batas waktu**
     * (konvensi MixRadius: "0 UNTUK MASA AKTIF UNLIMITED"). Revisi
     * 2026-09-05 — sebelumnya field ini dipaksa `>= 1` dengan asumsi "paket
     * bulanan selalu punya durasi nyata"; ternyata ada kebutuhan paket
     * gratis/tanpa batas.
     */
    public function isUnlimitedDuration(): bool
    {
        return (int) $this->active_duration_value === 0;
    }

    /**
     * RouterOS session-timeout string untuk Masa Aktif paket ini, atau
     * `null` kalau Unlimited (`active_duration_value = 0`) — sama posture
     * `HotspotPackage::routerOsSessionTimeout()` yang juga `null` untuk
     * Unlimited/QuotaBase. `null` = parameter `session-timeout` TIDAK
     * dikirim ke `/ppp profile` sama sekali, jadi RouterOS memakai
     * default-nya sendiri ("none" = tanpa timeout). Lihat
     * `RouterOsApiGateway::syncPppProfile()` — cabang add/set sama-sama
     * meng-skip `session-timeout` saat argumennya null (RouterOS menolak
     * '' / 'none' sebagai nilai eksplisit, lihat catatan di sana).
     *
     * KETERBATASAN DIKETAHUI (sama persis dengan kasus HotspotPackage di
     * v0.14.4): mengubah paket yang SUDAH ter-sync dari durasi nyata ke
     * Unlimited (0) lalu push ulang TIDAK aktif menghapus `session-timeout`
     * lama di router — field-nya cuma dibiarkan. Di-flag, bukan
     * di-workaround diam-diam. Untuk paket yang baru dibuat sebagai
     * Unlimited, tidak ada masalah (parameter memang tidak pernah dikirim).
     */
    public function routerOsSessionTimeout(): ?string
    {
        if ($this->isUnlimitedDuration()) {
            return null;
        }

        $unit = $this->active_duration_unit;

        return $unit->routerOsValue($this->active_duration_value).$unit->routerOsSuffix();
    }

    public function markSyncPending(): void
    {
        $this->update(['mikrotik_sync_status' => MikrotikSyncStatus::Pending, 'mikrotik_sync_error' => null]);
    }

    public function markSynced(): void
    {
        $this->update([
            'mikrotik_sync_status' => MikrotikSyncStatus::Synced,
            'mikrotik_synced_at' => Carbon::now(),
            'mikrotik_sync_error' => null,
        ]);
    }

    public function markSyncFailed(string $message): void
    {
        $this->update([
            'mikrotik_sync_status' => MikrotikSyncStatus::Failed,
            'mikrotik_sync_error' => $message,
        ]);
    }

    /**
     * ATURAN NAMA FINAL (2026-09-05, dikonfirmasi Agung — lihat CLAUDE.md
     * "Aturan Nama Profil Paket"):
     *
     *  - DI DALAM dunia PPP (IP Pool Pelanggan, Grup Profil tipe ppp,
     *    Profil PPP) nama BOLEH sama semua — sengaja, biar konsisten
     *    dilihat di WinBox saat troubleshooting. Collision `/ppp profile`
     *    di RouterOS (Grup Profil ppp + Profil PPP sama-sama push ke
     *    namespace itu) di-handle otomatis via routerOsProfileName()
     *    saat push, BUKAN dengan menolak di validasi.
     *  - Hotspot vs PPP TETAP tidak boleh bentrok untuk nama Paket/Profil:
     *    Profil PPP TIDAK BOLEH senama Grup Profil tipe HOTSPOT atau Profil
     *    Hotspot di NAS yang sama. Aturan bisnis Agung — bukan sekadar soal
     *    namespace RouterOS (`/ip hotspot user profile` memang namespace
     *    beda dari `/ppp profile`), tetap di-enforce.
     *
     * Pure query logic, dipanggil identik dari Store/UpdatePppPackageRequest
     * dan PppPackageIndex — pola sama CustomerIpPool::overlapsRange().
     */
    public static function collidesWithExistingName(int $nasId, string $name): bool
    {
        $hotspotGroupCollision = NetworkProfileGroup::where('nas_id', $nasId)
            ->where('type', NetworkProfileGroupType::Hotspot->value)
            ->where('name', $name)
            ->exists();

        if ($hotspotGroupCollision) {
            return true;
        }

        return HotspotPackage::whereHas('networkProfileGroup', fn ($query) => $query->where('nas_id', $nasId))
            ->where('name', $name)
            ->exists();
    }

    /**
     * Nama yang GENUINELY dikirim ke `/ppp/profile` di router — BUKAN
     * selalu `$this->name` verbatim (FIX 2 aturan nama final).
     *
     * `/ppp profile` wajib unik nama-nya router-wide. Grup Profil (ppp)
     * SELALU push nama verbatim (dia "anchor" — PPPoE Server Default
     * Profile). Profil PPP push verbatim JUGA, KECUALI namanya bentrok
     * dengan Grup Profil ppp / Profil PPP lain di NAS yang sama — lalu
     * pakai suffix stabil " (pkg #{id})". Nama TAMPILAN (`$this->name`,
     * yang diketik/dilihat Agung di form) tidak pernah berubah — hanya
     * string yang dikirim ke RouterOS API. Lookup existing tetap by
     * `comment` (`mikrotikComment()`), tidak terpengaruh nama.
     *
     * Dievaluasi saat push (PushPppPackageToMikrotikJob). Kasus umum
     * (paket dibuat senama Grup Profil induknya) otomatis benar. Kasus
     * Grup Profil DI-RENAME jadi bentrok dengan Profil PPP yang sudah
     * ter-sync: NetworkProfileGroupService me-re-dispatch push Profil PPP
     * yang senama supaya mereka geser ke suffix duluan.
     */
    public function routerOsProfileName(): string
    {
        $nasId = $this->networkProfileGroup?->nas_id;

        if ($nasId === null) {
            return $this->name;
        }

        $collidesWithPppGroup = NetworkProfileGroup::where('nas_id', $nasId)
            ->where('type', NetworkProfileGroupType::Ppp->value)
            ->where('name', $this->name)
            ->exists();

        $collidesWithLowerIdPackage = self::whereHas('networkProfileGroup', fn ($query) => $query->where('nas_id', $nasId))
            ->where('name', $this->name)
            ->where('id', '<', $this->id)
            ->exists();

        return $collidesWithPppGroup || $collidesWithLowerIdPackage
            ? "{$this->name} (pkg #{$this->id})"
            : $this->name;
    }
}
