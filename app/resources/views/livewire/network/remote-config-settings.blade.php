@php
    use App\Enums\MikrotikSyncStatus;
    $status = $config->genieacs_sync_status;
@endphp

<div class="p-6 max-w-3xl mx-auto" @if ($hasPendingSync) wire:poll.5s="$refresh" @endif>
    <h1 class="text-2xl font-semibold mb-2" style="color: var(--color-text)">{{ __('Konfig Remote') }}</h1>
    <p class="text-sm text-gray-500 mb-6">
        {{ __('Auto-WAN provisioning GenieACS — VLAN + kredensial default PPPoE yang dulu HARDCODED di script provision, sekarang diatur dari sini. Berlaku fleet-wide (semua ONT yang dikelola GenieACS). Deteksi vendor (Huawei / CMCC / ZTE generic) dan guard idempoten dipertahankan dari script referensi.') }}
    </p>

    @if ($flash)
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2">{{ $flash }}</p>
    @endif

    {{-- Status sinkronisasi GenieACS --}}
    <div class="mb-6 flex flex-wrap items-center gap-3 text-sm">
        <span class="text-gray-500">{{ __('Status sinkron GenieACS') }}:</span>
        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium {{ $status->badgeClasses() }}">{{ $status->label() }}</span>
        @if ($config->genieacs_synced_at)
            <span class="text-gray-400 text-xs">{{ __('terakhir') }}: {{ $config->genieacs_synced_at->diffForHumans() }}</span>
        @endif
        @if ($canManage && $status === MikrotikSyncStatus::Failed)
            <button type="button" wire:click="resync" class="text-red-600 hover:underline text-xs">{{ __('Sync Ulang') }}</button>
        @endif
    </div>
    @if ($status === MikrotikSyncStatus::Failed && $config->genieacs_sync_error)
        <p class="mb-4 text-xs text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2 font-mono break-all">{{ $config->genieacs_sync_error }}</p>
    @endif

    {{-- State efektif di preset GenieACS live --}}
    @if ($liveState !== null)
        <div class="mb-6 text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-md px-3 py-2">
            <p>{{ __('State di GenieACS live') }}:
                {{ __('provision default-wan') }}
                @if ($liveState['provision_exists']) <span class="text-green-700">{{ __('ada') }}</span> @else <span class="text-amber-700">{{ __('belum ada') }}</span> @endif
                ·
                {{ __('dipakai preset default') }}
                @if ($liveState['in_preset']) <span class="text-green-700">{{ __('ya') }}</span> @else <span class="text-gray-600">{{ __('tidak') }}</span> @endif
                @if ($liveState['preset_args'])
                    · <span class="font-mono">args={{ json_encode($liveState['preset_args']) }}</span>
                @endif
            </p>
        </div>
    @else
        <p class="mb-6 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
            {{ __('genieacs-nbi tidak terjangkau saat memuat halaman — state live tidak bisa ditampilkan (nilai form tetap dari database).') }}
        </p>
    @endif

    <form wire:submit="save" class="space-y-6">
        {{-- Master switch --}}
        <label class="flex items-start gap-3 p-4 rounded-md border border-gray-200 bg-white">
            <input type="checkbox" wire:model="enabled" @disabled(!$canManage) class="mt-0.5 rounded border-gray-300">
            <span>
                <span class="block text-sm font-medium text-gray-800">{{ __('Aktifkan Auto-WAN') }}</span>
                <span class="block text-xs text-gray-500 mt-0.5">{{ __('Kalau OFF, provision default-wan dikeluarkan total dari preset GenieACS — tidak ada perangkat yang disentuh.') }}</span>
            </span>
        </label>

        {{-- WAN1 --}}
        <div class="p-4 rounded-md border border-gray-200 bg-white space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="wan1_enabled" @disabled(!$canManage) class="mt-0.5 rounded border-gray-300">
                <span>
                    <span class="block text-sm font-medium text-gray-800">{{ __('WAN1 — Internet PPPoE') }}</span>
                    <span class="block text-xs text-gray-500 mt-0.5">{{ __('Guard idempoten: dilewati kalau perangkat SUDAH punya PPPoE username terisi → aman untuk pelanggan existing, hanya ONT baru / factory-reset yang dikonfigurasi.') }}</span>
                </span>
            </label>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pl-7">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('VLAN') }}</label>
                    <input type="number" wire:model="wan1_vlan" min="1" max="4094" @disabled(!$canManage) class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                    @error('wan1_vlan') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('PPPoE Username default') }}</label>
                    <input type="text" wire:model="wan1_pppoe_username" @disabled(!$canManage) class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                    @error('wan1_pppoe_username') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('PPPoE Password default') }}</label>
                    <input type="text" wire:model="wan1_pppoe_password" @disabled(!$canManage) class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                    @error('wan1_pppoe_password') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        {{-- WAN2 --}}
        <div class="p-4 rounded-md border border-gray-200 bg-white space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="wan2_enabled" @disabled(!$canManage) class="mt-0.5 rounded border-gray-300">
                <span>
                    <span class="block text-sm font-medium text-gray-800">{{ __('WAN2 — Bridge kedua (IPTV / layanan lain)') }}</span>
                    <span class="block text-xs text-amber-700 mt-0.5">{{ __('PERHATIAN: guard mengecek instance WAN ke-2 yang saat ini TIDAK dimiliki perangkat mana pun di fleet → mengaktifkan = perubahan ke SEMUA ONT. Uji ke 1-2 perangkat dulu.') }}</span>
                </span>
            </label>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pl-7">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('VLAN WAN2') }}</label>
                    <input type="number" wire:model="wan2_vlan" min="1" max="4094" @disabled(!$canManage) class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                    @error('wan2_vlan') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        @if ($canManage)
            <div class="flex items-center gap-3">
                <button type="submit" class="px-4 py-2 text-sm font-medium bg-primary text-white rounded-md hover:opacity-90">{{ __('Simpan & Sinkron') }}</button>
                @if ($config->updated_by)
                    <span class="text-xs text-gray-400">{{ __('diubah terakhir oleh') }} {{ $config->updatedBy?->name ?? '—' }}</span>
                @endif
            </div>
        @else
            <p class="text-sm text-gray-500">{{ __('Anda tidak punya izin mengubah konfigurasi ini.') }}</p>
        @endif
    </form>

    <div class="mt-8 text-xs text-gray-400 border-t border-gray-100 pt-4 space-y-1">
        <p>{{ __('Cache preset GenieACS di-refresh tiap ~5,5 menit — perubahan VLAN berlaku otomatis dalam rentang itu tanpa restart.') }}</p>
        <p>{{ __('Provision default-wan yang BENAR-BENAR baru (deploy pertama) mungkin butuh `docker compose restart genieacs-cwmp` sekali.') }}</p>
    </div>
</div>
