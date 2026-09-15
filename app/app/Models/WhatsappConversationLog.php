<?php

namespace App\Models;

use App\Enums\WhatsappConversationDirection;
use Illuminate\Database\Eloquent\Model;

/**
 * v0.13.2 — audit trail APPEND-ONLY untuk state machine percakapan
 * WhatsApp. Ditulis SATU-SATUNYA oleh App\Services\Whatsapp\
 * WhatsappConversationStateService — tidak pernah diupdate/dihapus
 * setelah tercipta. Lihat migration create_whatsapp_conversation_logs_table
 * untuk penjelasan lengkap tiap kolom, dan docblock service itu sendiri
 * untuk hubungan tabel ini dengan state Redis (sumber kebenaran
 * sesungguhnya ada di Redis, tabel ini murni jejak historis).
 */
class WhatsappConversationLog extends Model
{
    protected $fillable = [
        'state_id',
        'phone_number',
        'scope',
        'direction',
        'content',
        'step',
    ];

    protected function casts(): array
    {
        return [
            'direction' => WhatsappConversationDirection::class,
        ];
    }
}
