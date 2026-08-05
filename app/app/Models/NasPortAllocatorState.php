<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton row (id=1) backing NasPortAllocatorService — same "lockable
 * counter row" idiom as payment_gateway_settings, not tenant/reseller-scoped
 * (port allocation is a single global range shared by every NAS regardless
 * of tenant, matching the fact that all these ports are bound on the ONE
 * shared freeradius container, not per-tenant).
 */
class NasPortAllocatorState extends Model
{
    protected $table = 'nas_port_allocator_state';

    protected $fillable = [
        'next_auth_port',
    ];
}
