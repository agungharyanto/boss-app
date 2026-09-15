<div class="p-6 max-w-6xl mx-auto" x-data="{
    copy(text, evt) {
        const btn = evt.currentTarget;
        const done = () => { btn.innerText = 'Tersalin!'; setTimeout(() => btn.innerText = 'Salin', 1500); };
        if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(() => this.copyFallback(text, done));
        } else {
            this.copyFallback(text, done);
        }
    },
    copyFallback(text, done) {
        const el = document.createElement('textarea');
        el.value = text;
        el.setAttribute('readonly', '');
        el.style.position = 'fixed';
        el.style.left = '-9999px';
        document.body.appendChild(el);
        el.select();
        el.setSelectionRange(0, text.length);
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(el);
    }
}">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Manajemen Staff') }}</h1>

        @if ($canManage)
            <button
                wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
            >
                {{ $showCreateForm ? __('Batal') : __('+ Staff Baru') }}
            </button>
        @endif
    </div>

    @if ($generatedPassword)
        <div class="mb-6 p-4 rounded-md border border-amber-300 bg-amber-50 space-y-2" x-ref="generatedPasswordPanel">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-amber-800">
                        {{ __('Password login untuk :name — catat/relay sekarang, tidak akan ditampilkan lagi.', ['name' => $generatedPasswordForName]) }}
                    </p>
                    <p class="text-xs text-amber-700 mt-1">
                        {{ __('Relay password ini secara manual ke staff yang bersangkutan.') }}
                    </p>
                </div>
                <button type="button" wire:click="dismissGeneratedPassword" class="text-xs text-amber-700 hover:underline shrink-0">
                    {{ __('Tutup') }}
                </button>
            </div>
            <div class="flex items-center gap-2">
                <code x-ref="generatedPasswordText" class="flex-1 bg-white border border-amber-200 rounded-md px-3 py-2 text-sm font-mono">{{ $generatedPassword }}</code>
                <button
                    type="button"
                    x-on:click="copy($refs.generatedPasswordText.innerText, $event)"
                    class="text-xs px-3 py-2 border border-amber-300 rounded-md hover:bg-amber-100"
                >{{ __('Salin') }}</button>
            </div>
        </div>
    @endif

    @error('deleteStaff')
        <div class="mb-6 p-4 rounded-md border border-red-300 bg-red-50">
            <p class="text-sm text-red-700">{{ $message }}</p>
        </div>
    @enderror

    @if ($showCreateForm)
        <form wire:submit="createStaff" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nama') }}</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Email') }} <span class="text-gray-400 font-normal">({{ __('opsional') }})</span></label>
                <input type="email" wire:model="email" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nomor HP') }} <span class="text-xs text-gray-500 font-normal">({{ __('wajib — alat login utama') }})</span></label>
                <input type="text" wire:model="phone" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('phone') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Role') }}</label>
                <select wire:model="role" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">{{ __('Pilih role') }}</option>
                    @foreach ($roles as $roleName)
                        <option value="{{ $roleName }}">{{ $roleName }}</option>
                    @endforeach
                </select>
                @error('role') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <p class="text-xs text-gray-500">
                {{ __('Password login acak dibuat otomatis dan ditampilkan sekali di layar ini setelah disimpan.') }}
            </p>
            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                {{ __('Simpan') }}
            </button>
        </form>
    @endif

    <div class="mb-4">
        <input
            type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama atau email staff...') }}"
            class="w-full rounded-md border-gray-300 shadow-sm"
        >
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Nama') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Email') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('HP') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Role') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($staff as $member)
                    <tr wire:key="staff-{{ $member->id }}">
                        @if ($editingUserId === $member->id)
                            <td colspan="6" class="px-4 py-3">
                                <form wire:submit="updateStaff" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-start">
                                    <div>
                                        <input type="text" wire:model="editName" placeholder="{{ __('Nama') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        @error('editName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <input type="email" wire:model="editEmail" placeholder="{{ __('Email (opsional)') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        @error('editEmail') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <input type="text" wire:model="editPhone" placeholder="{{ __('Nomor HP (wajib)') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        @error('editPhone') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <select wire:model="editRole" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @foreach ($roles as $roleName)
                                                <option value="{{ $roleName }}">{{ $roleName }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="submit" class="text-sm px-3 py-1.5 bg-green-600 text-white rounded-md hover:bg-green-700">{{ __('Simpan') }}</button>
                                        <button type="button" wire:click="cancelEdit" class="text-sm px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Batal') }}</button>
                                    </div>
                                </form>
                            </td>
                        @else
                            <td class="px-4 py-2 text-sm text-gray-800">{{ $member->name }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $member->email ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $member->phone }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $member->roles->first()?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $member->is_disabled ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' }}">
                                    {{ $member->is_disabled ? __('Disabled') : __('Enabled') }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-sm text-right space-x-2 whitespace-nowrap">
                                @if ($canManage)
                                    <button wire:click="edit({{ $member->id }})" class="text-primary hover:underline">{{ __('Edit') }}</button>

                                    @if ($member->is_disabled)
                                        <button wire:click="toggleDisable({{ $member->id }})" wire:confirm="{{ __('Enable akun ini? Staff akan bisa login kembali.') }}" class="text-primary hover:underline">
                                            {{ __('Enable') }}
                                        </button>
                                    @else
                                        <button wire:click="toggleDisable({{ $member->id }})" wire:confirm="{{ __('Disable akun ini? Staff tidak akan bisa login sampai di-enable kembali.') }}" class="text-red-600 hover:underline">
                                            {{ __('Disable') }}
                                        </button>
                                    @endif

                                    <button wire:click="deleteStaff({{ $member->id }})" wire:confirm="{{ __('Hapus akun staff ini secara PERMANEN? Tidak bisa dibatalkan.') }}" class="text-red-600 hover:underline">
                                        {{ __('Hapus') }}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada staff.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $staff->links() }}
    </div>
</div>
