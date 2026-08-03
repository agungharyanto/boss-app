<?php

namespace App\Models;

use App\Enums\WhatsappSessionStatus;
use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WhatsappSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappSession extends Model
{
    /** @use HasFactory<WhatsappSessionFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'phone_number',
        'status',
        'qr_code_data',
        'last_connected_at',
        'last_disconnected_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WhatsappSessionStatus::class,
            'last_connected_at' => 'datetime',
            'last_disconnected_at' => 'datetime',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    /**
     * The key used everywhere else in this module (Redis queue name, Node
     * service session id, auth_state folder name) — reseller_id as a
     * string, or the literal "direct" for the reseller-less ISP session.
     */
    public function sessionKey(): string
    {
        return self::sessionKeyFor($this->reseller_id);
    }

    public static function sessionKeyFor(?int $resellerId): string
    {
        return $resellerId !== null ? (string) $resellerId : 'direct';
    }
}
