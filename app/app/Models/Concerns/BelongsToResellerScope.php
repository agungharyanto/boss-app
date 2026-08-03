<?php

namespace App\Models\Concerns;

use App\Models\Scopes\ResellerScope;

/**
 * Unlike BelongsToTenant, this scope is opt-in and conditional: it only
 * filters queries when App\Support\ResellerContext has an active reseller
 * resolved for the current request (see
 * App\Http\Middleware\ResolveResellerContext). ISP admins — who never get a
 * reseller context — see every row regardless of this trait.
 */
trait BelongsToResellerScope
{
    public static function bootBelongsToResellerScope(): void
    {
        static::addGlobalScope(new ResellerScope);
    }
}
