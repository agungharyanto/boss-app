<?php

namespace App\Models;

use App\Support\NameToCodeDeriver;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $tenant) {
            $tenant->uuid ??= (string) Str::uuid();

            // Never overrides an explicitly-set code. Silently leaves code
            // null if name is blank — code is nullable precisely so this
            // never blocks tenant creation.
            if (blank($tenant->code) && filled($tenant->name)) {
                $tenant->code = NameToCodeDeriver::deriveUnique(
                    $tenant->name,
                    fn (string $candidate) => self::where('code', $candidate)->exists()
                );
            }
        });
    }
}
