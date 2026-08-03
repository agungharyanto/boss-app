<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WhatsappEventType;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\WhatsappMessageTemplate;
use App\Services\Whatsapp\WhatsappTemplateService;
use App\Support\ResellerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsappMessageTemplateController extends Controller
{
    use ApiResponds;

    /**
     * BelongsToResellerScope narrows this to the reseller's own override
     * rows only; an ISP admin sees every row (default + all overrides).
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', WhatsappMessageTemplate::class);

        return $this->success(WhatsappMessageTemplate::all(), 'Daftar template WhatsApp');
    }

    /**
     * Upserts the acting user's own scope: their reseller's override if a
     * reseller context is resolved, else the tenant's ISP-level default.
     */
    public function update(Request $request, string $eventType, ResellerContext $context, WhatsappTemplateService $service): JsonResponse
    {
        $eventTypeEnum = WhatsappEventType::from($eventType);
        $resellerId = $context->hasReseller() ? $context->reseller()->id : null;

        $target = $this->findExisting($request, $resellerId, $eventTypeEnum)
            ?? new WhatsappMessageTemplate(['reseller_id' => $resellerId]);

        $this->authorize('manage', $target);

        $data = $request->validate([
            'content' => ['required', 'string'],
            'is_active' => ['boolean'],
        ]);

        $template = $service->upsert(
            $request->user()->tenant_id,
            $resellerId,
            $eventTypeEnum,
            $data['content'],
            $data['is_active'] ?? true,
            $request->user()->id,
        );

        return $this->success($template, 'Template disimpan');
    }

    /**
     * Reseller-only: delete their own override row so resolve() falls back
     * to the tenant's default ISP-level template again.
     */
    public function resetToDefault(Request $request, string $eventType, ResellerContext $context, WhatsappTemplateService $service): JsonResponse
    {
        if (! $context->hasReseller()) {
            abort(403, 'Hanya reseller yang dapat reset template ke default ISP.');
        }

        $reseller = $context->reseller();
        $eventTypeEnum = WhatsappEventType::from($eventType);

        $target = $this->findExisting($request, $reseller->id, $eventTypeEnum)
            ?? new WhatsappMessageTemplate(['reseller_id' => $reseller->id]);

        $this->authorize('manage', $target);

        $service->resetToDefault($request->user()->tenant_id, $reseller->id, $eventTypeEnum);

        return $this->success(null, 'Template direset ke default ISP');
    }

    private function findExisting(Request $request, ?int $resellerId, WhatsappEventType $eventType): ?WhatsappMessageTemplate
    {
        return WhatsappMessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('reseller_id', $resellerId)
            ->where('event_type', $eventType->value)
            ->first();
    }
}
