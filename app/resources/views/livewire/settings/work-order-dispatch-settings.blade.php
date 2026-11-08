@php
    $activeGroups = $notificationGroups->where('is_active', true);
    $existingJids = $notificationGroups->pluck('jid')->all();
@endphp
<div class="p-6 max-w-2xl mx-auto">
    <h1 class="text-2xl font-semibold text-gray-800 mb-1">{{ __('Konfig WA Gateway') }}</h1>
    <p class="text-sm text-gray-500 mb-6">
        {{ __('Pengaturan timing dispatch & reminder Work Order, serta grup WhatsApp untuk broadcast notifikasi dan routing per kategori.') }}
    </p>

    @if (session('status'))
        <div class="mb-4 p-4 rounded-md border border-green-300 bg-green-50">
            <p class="text-sm text-green-700">{{ session('status') }}</p>
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 p-4 rounded-md border border-red-300 bg-red-50">
            <p class="text-sm text-red-700">{{ session('error') }}</p>
        </div>
    @endif

    {{-- ── Timing dispatch / reminder ──────────────────────────────── --}}
    <form wire:submit="save" class="space-y-5 bg-white border border-gray-200 rounded-md p-6 mb-6">
        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Offset Dispatch (jam)') }}</label>
            <p class="text-xs text-gray-500 mb-1">
                {{ __('Berapa jam sebelum janji kunjungan pelanggan, Work Order otomatis "keluar" ke teknisi.') }}
            </p>
            <input type="number" min="1" step="1" wire:model="dispatchOffsetHours" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
            @error('dispatchOffsetHours') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Jam Reminder Harian') }}</label>
            <p class="text-xs text-gray-500 mb-1">
                {{ __('Jam mulai command mengecek Work Order yang sudah dispatch tapi belum selesai, tiap hari.') }}
            </p>
            <input type="time" wire:model="reminderTime" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
            @error('reminderTime') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Interval Command (menit)') }}</label>
            <p class="text-xs text-gray-500 mb-1">
                {{ __('Seberapa sering command dispatch/reminder benar-benar jalan (1-60 menit).') }}
            </p>
            <input type="number" min="1" max="60" step="1" wire:model="commandIntervalMinutes" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
            @error('commandIntervalMinutes') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90">
            {{ __('Simpan') }}
        </button>
    </form>

    {{-- ── Notifikasi Otomatis ke Pelanggan (v0.13.5.1.2) ───────────── --}}
    <div class="bg-white border border-gray-200 rounded-md p-6 mb-6">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold text-gray-800">{{ __('Notifikasi Otomatis ke Pelanggan') }}</h2>
            @if ($customerAutoNotifyEnabled)
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">{{ __('AKTIF') }}</span>
            @else
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">{{ __('MATI') }}</span>
            @endif
        </div>
        <p class="text-xs text-gray-500 mb-1">
            {{ __('Mengatur pesan WhatsApp OTOMATIS ke PELANGGAN: pengingat jatuh tempo invoice, pembayaran diterima, pelanggan terdaftar, dan pengingat suspend.') }}
        </p>
        <p class="text-xs text-gray-400 italic mb-4">
            {{ __('TIDAK memengaruhi: OTP referrer, notifikasi teknisi & Work Order, grup WO, serta balasan chat 2 arah — semuanya tetap jalan. Sesi WhatsApp tidak di-logout.') }}
        </p>

        <label class="flex items-center gap-2 text-sm text-gray-700 mb-4">
            <input type="checkbox" wire:model="customerAutoNotifyEnabled" class="rounded border-gray-300">
            <span>{{ __('Kirim notifikasi otomatis ke pelanggan') }}</span>
        </label>

        <button type="button" wire:click="saveCustomerAutoNotify" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
            {{ __('Simpan') }}
        </button>
    </div>

    {{-- ── Daftar Grup Notifikasi ──────────────────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-md p-6 mb-6">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold text-gray-800">{{ __('Daftar Grup Notifikasi') }}</h2>
            <button type="button" wire:click="reloadGroups" wire:loading.attr="disabled" class="text-xs text-primary hover:underline">
                {{ __('Muat Ulang Daftar Grup') }}
            </button>
        </div>
        <p class="text-xs text-gray-500 mb-1">
            {{ __('Grup WhatsApp tempat notifikasi WO dikirim. Satu grup ditandai Default sebagai tujuan untuk kategori yang belum diatur. Grup Default tidak bisa dihapus/dinonaktifkan selama ada grup lain.') }}
        </p>
        <p class="text-xs text-gray-400 italic mb-4">
            {{ __('Aksi di bagian ini (Tambah, Simpan label, Jadikan Default, Aktif/Nonaktif, Hapus) langsung tersimpan saat diklik — tidak perlu tombol simpan terpisah.') }}
        </p>

        {{-- Tambah grup baru dari daftar grup bot (sesi 'direct'). --}}
        @if (! $sessionConnected)
            <p class="text-sm text-gray-400 italic">{{ __('Sesi WhatsApp "direct" belum terhubung — hubungkan dulu di halaman WhatsApp Gateway untuk memuat daftar grup.') }}</p>
        @elseif ($groupsLoadFailed)
            <p class="text-sm text-gray-400 italic">{{ __('Belum ada grup ditemukan, atau gagal memuat — coba Muat Ulang Daftar Grup.') }}</p>
        @else
            <div class="flex flex-col sm:flex-row gap-2 mb-4">
                <select wire:model.live="newGroupJid" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                    <option value="">{{ __('— Pilih grup bot untuk ditambahkan —') }}</option>
                    @foreach ($groupOptions as $group)
                        @unless (in_array($group['jid'], $existingJids, true))
                            <option value="{{ $group['jid'] }}">{{ $group['name'] }}</option>
                        @endunless
                    @endforeach
                </select>
                <input type="text" wire:model="newGroupLabel" placeholder="{{ __('Label') }}" class="block w-full sm:w-48 rounded-md border-gray-300 shadow-sm text-sm">
                <button type="button" wire:click="addGroup" class="shrink-0 px-3 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                    {{ __('Tambah') }}
                </button>
            </div>
            @error('newGroupJid') <p class="text-sm text-red-600 -mt-2 mb-2">{{ $message }}</p> @enderror
            @error('newGroupLabel') <p class="text-sm text-red-600 -mt-2 mb-2">{{ $message }}</p> @enderror
        @endif

        {{-- Grup yang sudah ada. --}}
        @if ($notificationGroups->isEmpty())
            <p class="text-sm text-gray-400">{{ __('Belum ada grup notifikasi. Notifikasi grup dilewati sampai minimal satu grup Default ditambahkan.') }}</p>
        @else
            <div class="divide-y divide-gray-100 border border-gray-100 rounded-md">
                @foreach ($notificationGroups as $group)
                    <div class="p-3 flex flex-col sm:flex-row sm:items-center gap-2" wire:key="group-{{ $group->id }}">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <input type="text" wire:model="groupLabels.{{ $group->id }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                <button type="button" wire:click="saveLabel({{ $group->id }})" class="shrink-0 text-xs text-primary hover:underline">{{ __('Simpan') }}</button>
                            </div>
                            @error('groupLabels.'.$group->id) <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            <p class="text-xs text-gray-400 mt-1 font-mono truncate">{{ $group->jid }}</p>
                            <p class="text-xs text-gray-400 truncate">
                                {{ __('Nama di WhatsApp:') }}
                                @if (array_key_exists($group->jid, $botGroupNamesByJid))
                                    {{ $botGroupNamesByJid[$group->jid] }}
                                @else
                                    <span class="italic">{{ __('(tidak ada di daftar grup bot — muat ulang)') }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            @if ($group->is_default)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">{{ __('Default') }}</span>
                            @else
                                <button type="button" wire:click="setDefault({{ $group->id }})" class="text-xs text-primary hover:underline">{{ __('Jadikan Default') }}</button>
                            @endif

                            @if ($group->is_active)
                                <button type="button" wire:click="toggleActive({{ $group->id }})" class="text-xs text-gray-600 hover:underline">{{ __('Nonaktifkan') }}</button>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">{{ __('Nonaktif') }}</span>
                                <button type="button" wire:click="toggleActive({{ $group->id }})" class="text-xs text-primary hover:underline">{{ __('Aktifkan') }}</button>
                            @endif

                            <button type="button" wire:click="deleteGroup({{ $group->id }})" wire:confirm="{{ __('Hapus grup ini dari daftar notifikasi?') }}" class="text-xs text-red-600 hover:underline">{{ __('Hapus') }}</button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ── Routing Notifikasi ──────────────────────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-md p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">{{ __('Routing Notifikasi') }}</h2>
        <p class="text-xs text-gray-500 mb-1">
            {{ __('Pilih grup tujuan untuk tiap kategori. Kosongkan (— Default —) untuk memakai grup Default.') }}
        </p>
        <p class="text-xs text-gray-400 italic mb-4">
            {{ __('Perubahan di sini BELUM tersimpan sampai Anda klik tombol "Simpan Routing" di bawah.') }}
        </p>

        <div class="divide-y divide-gray-100">
            @foreach ($categoryLabels as $category => $label)
                <div class="py-3 flex flex-col sm:flex-row sm:items-center gap-2" wire:key="route-{{ $category }}">
                    <div class="flex-1 text-sm text-gray-700">{{ $label }}</div>
                    <div class="w-full sm:w-64">
                        <select wire:model.live="routeGroupId.{{ $category }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                            <option value="">{{ __('— Default —') }}</option>
                            @foreach ($activeGroups as $group)
                                <option value="{{ $group->id }}">{{ $group->label }}</option>
                            @endforeach
                        </select>
                        @error('routeGroupId.'.$category) <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4 flex items-center gap-3">
            <button type="button" wire:click="saveRouting" @disabled(! $routingDirty)
                class="px-4 py-2 bg-primary text-white rounded-md text-sm hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed">
                {{ __('Simpan Routing') }}
            </button>
            @if ($routingDirty)
                <span class="text-xs text-amber-600">{{ __('• Ada perubahan belum disimpan.') }}</span>
            @endif
        </div>
    </div>
</div>
