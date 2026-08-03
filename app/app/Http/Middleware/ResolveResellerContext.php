<?php

namespace App\Http\Middleware;

use App\Enums\ResellerUserStatus;
use App\Models\ResellerUser;
use App\Support\ResellerContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves "which reseller is the logged-in user currently operating as"
 * into the request-scoped App\Support\ResellerContext singleton, based on
 * their active reseller_users membership(s):
 *
 * - 0 active memberships  -> null context (ISP admin / direct staff, sees
 *   everything — App\Models\Scopes\ResellerScope only filters when a
 *   reseller IS resolved, never the other way around).
 * - 1 active membership   -> that reseller.
 * - 2+ active memberships -> first one (by id), with a warning logged.
 *   Picking-which-reseller-to-act-as is a real gap (a UI switcher), but is
 *   explicitly out of scope for v0.3.2 — tracked as backlog.
 */
class ResolveResellerContext
{
    public function __construct(private readonly ResellerContext $context) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $memberships = ResellerUser::query()
                ->where('user_id', $user->id)
                ->where('status', ResellerUserStatus::Active)
                ->orderBy('reseller_id')
                ->with('reseller')
                ->get();

            if ($memberships->count() > 1) {
                Log::warning('User belongs to more than one active reseller; defaulting to the first (multi-reseller switcher is backlog, not built in v0.3.2).', [
                    'user_id' => $user->id,
                    'reseller_ids' => $memberships->pluck('reseller_id')->all(),
                ]);
            }

            $this->context->set($memberships->first()?->reseller);
        }

        return $next($request);
    }
}
