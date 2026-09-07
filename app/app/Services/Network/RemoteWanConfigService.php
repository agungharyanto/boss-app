<?php

namespace App\Services\Network;

use App\Jobs\SyncRemoteWanConfigToGenieAcsJob;
use App\Models\RemoteWanConfig;
use App\Models\User;

/**
 * Satu-satunya jalur tulis `remote_wan_configs` (singleton id=1). Menyimpan
 * nilai lalu men-dispatch `SyncRemoteWanConfigToGenieAcsJob` — push ke
 * GenieACS selalu async, tidak pernah memblokir submit form.
 *
 * BOSS-006 — dipakai bareng oleh `App\Livewire\Network\RemoteConfigSettings`
 * (UI). Belum ada endpoint REST (foothold bot / API menyusul kalau perlu).
 */
class RemoteWanConfigService
{
    public function current(): RemoteWanConfig
    {
        return RemoteWanConfig::current();
    }

    /**
     * @param  array{
     *   enabled?: bool, wan1_enabled?: bool, wan1_vlan?: int,
     *   wan1_pppoe_username?: string, wan1_pppoe_password?: string,
     *   wan2_enabled?: bool, wan2_vlan?: int
     * }  $data
     */
    public function save(array $data, User $actor): RemoteWanConfig
    {
        $config = RemoteWanConfig::current();

        $config->fill($data);
        $config->updated_by = $actor->id;
        $config->save();

        // Reset status ke Pending secara sinkron — badge tidak boleh
        // menampilkan "Tersinkron" lama sementara push baru masih antre
        // (pola sama CustomerIpPoolService).
        $config->markSyncPending();

        SyncRemoteWanConfigToGenieAcsJob::dispatch();

        return $config->fresh();
    }

    public function resync(): void
    {
        RemoteWanConfig::current()->markSyncPending();

        SyncRemoteWanConfigToGenieAcsJob::dispatch();
    }
}
