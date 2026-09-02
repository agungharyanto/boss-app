<?php

namespace App\Models;

use App\Enums\WhatsappEventType;
use App\Enums\WhatsappMessageStatus;
use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WhatsappMessageLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMessageLog extends Model
{
    /** @use HasFactory<WhatsappMessageLogFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'customer_id',
        'invoice_id',
        'phone_number',
        'event_type',
        'template_id',
        'rendered_content',
        'status',
        'failed_reason',
        'attempts',
        'queued_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => WhatsappEventType::class,
            'status' => WhatsappMessageStatus::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Batasi ke baris yang `event_type`-nya masih dikenal
     * `App\Enums\WhatsappEventType` yang sedang berjalan.
     *
     * Alasan: `event_type` di-cast ke enum backed value — sebuah baris log
     * dengan `event_type` yang TIDAK dikenal kode yang berjalan (case enum
     * dihapus/di-rename, atau — kasus nyata yang memicu ini — baris di-seed
     * oleh branch fitur yang lebih baru & belum di-merge, lalu working tree
     * balik ke branch lama) akan melempar `ValueError` saat hidrasi Eloquent
     * dan meng-500-kan seluruh halaman antrian. Setiap listing antrian
     * (Livewire `WhatsappGatewayIndex` + REST `WhatsappMessageLogController`)
     * memakai scope ini supaya baris "asing" cuma tersembunyi, bukan
     * merusak halaman — dan otomatis muncul lagi begitu kode menyusul.
     */
    public function scopeKnownEventType(Builder $query): Builder
    {
        return $query->whereIn('event_type', array_column(WhatsappEventType::cases(), 'value'));
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsappMessageTemplate::class, 'template_id');
    }
}
