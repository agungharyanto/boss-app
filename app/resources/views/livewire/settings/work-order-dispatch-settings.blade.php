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

    {{-- ── Kirim PDF Invoice Otomatis (v0.13.5.3.1) ─────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-md p-6 mb-6">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold text-gray-800">{{ __('Kirim PDF Invoice Otomatis') }}</h2>
            @if ($invoicePdfAutoSendEnabled)
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">{{ __('AKTIF') }}</span>
            @else
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">{{ __('MATI') }}</span>
            @endif
        </div>
        <p class="text-xs text-gray-500 mb-1">
            {{ __('Sweep terjadwal mengirim PDF invoice (tagihan terbit) ke pelanggan secara OTOMATIS, bertahap dan aman terhadap ban WhatsApp.') }}
        </p>

        {{-- Peringatan wajib --}}
        <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800 mb-4 space-y-1">
            <p>• {{ __('Hanya invoice yang terbit SETELAH batas mulai yang dikirim (bukan backlog lama).') }}</p>
            <p>• {{ __('Butuh saklar "Notifikasi Otomatis ke Pelanggan" di atas juga AKTIF.') }}</p>
            <p>• {{ __('Maksimal') }} {{ $invoicePdfAutoSendDailyCap }} {{ __('pesan/hari, hanya jam 08:00–20:00 WIB.') }}</p>
            <p>• {{ __('Sweep BERHENTI TOTAL saat "Mode Uji Kirim Invoice" di bawah AKTIF.') }}</p>
            <p>• {{ __('Pastikan pengingat/tagihan MixRadius untuk pelanggan ini sudah DIMATIKAN dulu agar pelanggan tidak menerima tagihan ganda.') }}</p>
        </div>

        {{-- Indikator status --}}
        <div class="rounded-md bg-gray-50 border border-gray-200 p-3 text-xs text-gray-700 mb-4 space-y-0.5">
            <p>{{ __('Status') }}:
                @php $st = $autoSend['status']; @endphp
                <span class="font-medium {{ $st === 'Aktif' ? 'text-green-700' : ($st === 'Mati' ? 'text-gray-600' : 'text-amber-700') }}">{{ $st }}</span>
            </p>
            <p>{{ __('Hari ini terkirim') }}: <span class="font-medium">{{ $autoSend['sent_today'] }}</span> {{ __('dari') }} {{ $autoSend['cap'] }}</p>
            <p>{{ __('Terakhir jalan') }}: <span class="font-medium">{{ $autoSend['last_run'] ?? __('belum pernah') }}</span></p>
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700 mb-4">
            <input type="checkbox" wire:model="invoicePdfAutoSendEnabled" class="rounded border-gray-300">
            <span>{{ __('Aktifkan kirim PDF invoice otomatis') }}</span>
        </label>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Mulai dari tanggal') }}</label>
                <input type="date" wire:model="invoicePdfAutoSendFrom"
                    class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                @error('invoicePdfAutoSendFrom') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                <p class="text-xs text-gray-400 mt-1">{{ __('Kosongkan = mulai dari saat saklar dinyalakan.') }}</p>
                @if ($invoicePdfAutoSendFrom && $invoicePdfAutoSendFrom < now()->toDateString())
                    <p class="text-xs text-amber-700 mt-1">{{ __('Peringatan: invoice Pending yang sudah terbit sejak tanggal itu akan dikirim bertahap, maks') }} {{ $invoicePdfAutoSendDailyCap }} {{ __('/hari.') }}</p>
                @endif
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Batas harian (pesan/hari)') }}</label>
                <input type="number" min="1" max="200" wire:model="invoicePdfAutoSendDailyCap"
                    class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                @error('invoicePdfAutoSendDailyCap') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                <p class="text-xs text-gray-400 mt-1">{{ __('1 sampai 200. Default 80.') }}</p>
            </div>
        </div>

        <button type="button" wire:click="saveInvoicePdfAutoSend" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
            {{ __('Simpan') }}
        </button>
    </div>

    {{-- ── Mode Uji Kirim Invoice (v0.13.5.3) ───────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-md p-6 mb-6">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold text-gray-800">{{ __('Mode Uji Kirim Invoice') }}</h2>
            @if ($invoicePdfTestModeEnabled)
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">{{ __('AKTIF') }}</span>
            @else
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">{{ __('MATI') }}</span>
            @endif
        </div>
        <p class="text-xs text-gray-500 mb-4">
            {{ __('Saat AKTIF, semua "Kirim PDF via WhatsApp" dialihkan ke NOMOR UJI di bawah — pelanggan nyata TIDAK menerima apa pun. Caption diberi awalan "[UJI untuk: ...]" dan log ditandai "Uji". Matikan untuk mengirim ke pelanggan sungguhan.') }}
        </p>

        <label class="flex items-center gap-2 text-sm text-gray-700 mb-3">
            <input type="checkbox" wire:model="invoicePdfTestModeEnabled" class="rounded border-gray-300">
            <span>{{ __('Aktifkan Mode Uji (alihkan semua kiriman invoice ke nomor uji)') }}</span>
        </label>

        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Nomor WhatsApp Uji') }}</label>
        <input type="text" wire:model="invoicePdfTestRecipient" placeholder="6287884374939"
            class="block w-full sm:w-72 rounded-md border-gray-300 shadow-sm text-sm">
        @error('invoicePdfTestRecipient') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
        <p class="text-xs text-gray-400 mt-1 mb-4">{{ __('Otomatis dinormalisasi ke format 62... Contoh: 087884374939 menjadi 6287884374939.') }}</p>

        <button type="button" wire:click="saveInvoicePdfTestMode" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
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
