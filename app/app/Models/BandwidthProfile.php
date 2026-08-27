<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BandwidthProfileFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BandwidthProfile extends Model
{
    /** @use HasFactory<BandwidthProfileFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'upload_min',
        'upload_max',
        'download_min',
        'download_max',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'upload_min' => 'integer',
            'upload_max' => 'integer',
            'download_min' => 'integer',
            'download_max' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Real bug found via manual UI testing: "10Mbps" and "10Mbps " (a
     * trailing space) are byte-distinct strings, so the tenant-scoped
     * unique index/Rule::unique() correctly never flagged them as
     * duplicates — they genuinely weren't the same value, just visually
     * indistinguishable in the UI. Trimming here (not just at the two
     * request entry points below) means ANY future write path — including
     * ones nobody has written yet — gets this for free, matching the
     * "fix once at the model, not per-caller" posture BOSS-006 implies.
     */
    protected function name(): Attribute
    {
        return Attribute::set(fn (string $value) => trim($value));
    }

    protected function uploadMinDisplay(): Attribute
    {
        return Attribute::get(fn () => self::formatKbps($this->upload_min));
    }

    protected function uploadMaxDisplay(): Attribute
    {
        return Attribute::get(fn () => self::formatKbps($this->upload_max));
    }

    protected function downloadMinDisplay(): Attribute
    {
        return Attribute::get(fn () => self::formatKbps($this->download_min));
    }

    protected function downloadMaxDisplay(): Attribute
    {
        return Attribute::get(fn () => self::formatKbps($this->download_max));
    }

    /**
     * >1000 Kbps shown as Mbps (up to 2 decimal places — e.g. 1536 Kbps ->
     * "1.54 Mbps", 1500 Kbps -> "1.5 Mbps", 50000 Kbps -> "50 Mbps"),
     * otherwise shown as plain whole Kbps. No existing PHP-side Kbps/Mbps
     * helper in this codebase to reuse (confirmed by grep before writing
     * this) — resources/js/app.js's window.pickBpsUnit is JS-only, a
     * different unit base (bps, not Kbps), and picks one shared unit
     * across a whole chart dataset rather than formatting a single value,
     * so it isn't reusable here despite the superficially similar name.
     *
     * Deliberately does NOT rtrim() trailing '0' characters off the
     * formatted string — a real bug caught by BandwidthProfileApiTest:
     * PHP's (string) cast of a float already gives the minimal
     * representation with no padding (round(50000/1000, 2) casts to "50",
     * never "50.00"), so a blind rtrim(..., '0') doesn't just strip a
     * padded decimal — it eats significant trailing zeros from whole
     * numbers too ("50" -> "5"). No trimming is needed at all.
     */
    public static function formatKbps(int $kbps): string
    {
        if ($kbps > 1000) {
            return round($kbps / 1000, 2).' Mbps';
        }

        return $kbps.' Kbps';
    }
}
