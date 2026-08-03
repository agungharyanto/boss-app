<?php

namespace App\Services\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Models\WhatsappMessageTemplate;
use Illuminate\Database\Eloquent\Collection;

class WhatsappTemplateService
{
    /**
     * @return Collection<int, WhatsappMessageTemplate>
     */
    public function defaultsForTenant(int $tenantId): Collection
    {
        return WhatsappMessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('reseller_id')
            ->get()
            ->keyBy(fn (WhatsappMessageTemplate $t) => $t->event_type->value);
    }

    /**
     * @return Collection<int, WhatsappMessageTemplate>
     */
    public function overridesForReseller(int $tenantId, int $resellerId): Collection
    {
        return WhatsappMessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('reseller_id', $resellerId)
            ->get()
            ->keyBy(fn (WhatsappMessageTemplate $t) => $t->event_type->value);
    }

    public function upsert(int $tenantId, ?int $resellerId, WhatsappEventType $eventType, string $content, bool $isActive, int $updatedBy): WhatsappMessageTemplate
    {
        $template = WhatsappMessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('reseller_id', $resellerId)
            ->where('event_type', $eventType->value)
            ->first();

        if ($template === null) {
            return WhatsappMessageTemplate::create([
                'tenant_id' => $tenantId,
                'reseller_id' => $resellerId,
                'event_type' => $eventType,
                'content' => $content,
                'is_active' => $isActive,
                'updated_by' => $updatedBy,
            ]);
        }

        $template->update([
            'content' => $content,
            'is_active' => $isActive,
            'updated_by' => $updatedBy,
        ]);

        return $template->fresh();
    }

    /**
     * "Reset ke default ISP" — deletes the reseller's override row entirely
     * so resolve() falls through to the tenant's default template again.
     */
    public function resetToDefault(int $tenantId, int $resellerId, WhatsappEventType $eventType): void
    {
        WhatsappMessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('reseller_id', $resellerId)
            ->where('event_type', $eventType->value)
            ->delete();
    }

    /**
     * Reseller override wins whenever it exists AND is active; otherwise
     * fall back to the tenant's default ISP-level template (reseller_id
     * null) for the same event_type. Returns null only if neither exists —
     * a data-integrity gap the default seeder is meant to prevent, not a
     * normal runtime branch.
     */
    public function resolve(WhatsappEventType $eventType, int $tenantId, ?int $resellerId): ?WhatsappMessageTemplate
    {
        if ($resellerId !== null) {
            $override = WhatsappMessageTemplate::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('reseller_id', $resellerId)
                ->where('event_type', $eventType->value)
                ->where('is_active', true)
                ->first();

            if ($override !== null) {
                return $override;
            }
        }

        return WhatsappMessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('reseller_id')
            ->where('event_type', $eventType->value)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Replace {variable} placeholders. Missing or null values render as an
     * empty string rather than leaving the literal placeholder in the
     * message — a customer should never see a raw "{payment_link}" because
     * that variable didn't apply to this event_type.
     *
     * @param  array<string, mixed>  $variables
     */
    public function render(string $content, array $variables): string
    {
        $replacements = [];

        foreach ($variables as $key => $value) {
            $replacements['{'.$key.'}'] = $value === null ? '' : (string) $value;
        }

        $rendered = strtr($content, $replacements);

        // Any {word} left over is a variable this event_type never
        // supplied at all (not merely null) — still shouldn't leak a raw
        // placeholder into the outgoing message.
        return preg_replace('/\{[a-zA-Z0-9_]+\}/', '', $rendered);
    }
}
