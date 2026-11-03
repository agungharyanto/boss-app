<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappGroupRoute;
use App\Models\WhatsappNotificationGroup;

/**
 * v0.13.4.4 — me-resolve "pesan kategori X untuk tenant Y harus ke grup WA
 * mana". Satu-satunya sumber kebenaran routing grup; menggantikan pembacaan
 * `work_order_dispatch_settings.wa_group_jid` langsung di
 * WorkOrderDispatchService.
 *
 * Aturan (dikunci Agung):
 *   1. Ada routing aktif untuk (tenant, kategori) -> grup yang dituju, TAPI
 *      hanya kalau grup itu `is_active`.
 *   2. Tidak ada routing / grup tujuan nonaktif / routing group_id null ->
 *      jatuh ke grup DEFAULT aktif sesi 'direct'.
 *   3. Tidak ada default aktif sama sekali -> null (pemanggil SKIP DIAM-DIAM,
 *      tidak pernah throw).
 *
 * Query `tenant_id` eksplisit, TANPA global scope — dipanggil dari
 * command/queue/afterCommit tanpa Auth.
 */
class WhatsappGroupRouter
{
    public function resolve(int $tenantId, string $category): ?WhatsappNotificationGroup
    {
        $route = WhatsappGroupRoute::query()
            ->where('tenant_id', $tenantId)
            ->where('category', $category)
            ->first();

        if ($route !== null && $route->group_id !== null) {
            $group = $route->group;

            if ($group !== null && $group->is_active) {
                return $group;
            }
        }

        return $this->defaultGroup($tenantId);
    }

    /**
     * Grup default aktif sesi 'direct' untuk tenant ini. Null kalau belum ada
     * (belum di-setup) atau default-nya dinonaktifkan.
     */
    public function defaultGroup(int $tenantId): ?WhatsappNotificationGroup
    {
        return WhatsappNotificationGroup::query()
            ->where('tenant_id', $tenantId)
            ->where('session_key', 'direct')
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }
}
