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
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Referrer') }}</h1>

        @if ($canManage)
            <button
                wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
            >
                {{ $showCreateForm ? __('Batal') : __('+ Referrer Baru') }}
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
                        {{ __('Relay password ini secara manual ke Referrer. JANGAN dikirim otomatis lewat WhatsApp.') }}
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

    @error('generateLoginAccount') <p class="mb-4 text-sm text-red-600">{{ $message }}</p> @enderror

    @if ($showCreateForm)
        <form wire:submit="createReferrer" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nama') }}</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nomor HP') }}</label>
                <input type="text" wire:model="phone" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('phone') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Tipe') }}</label>
                <select wire:model="type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">{{ __('Pilih tipe') }}</option>
                    @foreach ($referrerTypes as $referrerType)
                        <option value="{{ $referrerType->value }}">{{ $referrerType->label() }}</option>
                    @endforeach
                </select>
                @error('type') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="isActive"> {{ __('Aktif') }}
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="createLoginAccount"> {{ __('Buat akun login sekarang') }}
            </label>
            <p class="text-xs text-gray-500">
                {{ __('Kalau dicentang, sistem membuat akun login (password acak) dan menampilkannya sekali di layar ini setelah disimpan — belum dicentang berarti Referrer dibuat tanpa akun, bisa dihubungkan/dibuatkan akun belakangan lewat halaman ini.') }}
            </p>
            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                {{ __('Simpan') }}
            </button>
        </form>
    @endif

    <div class="mb-4">
        <input
            type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama referrer...') }}"
            class="w-full rounded-md border-gray-300 shadow-sm"
        >
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Nama') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('HP') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Tipe') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Akun Login') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($referrers as $referrer)
                    <tr wire:key="referrer-{{ $referrer->id }}">
                        @if ($editingReferrerId === $referrer->id)
                            <td colspan="6" class="px-4 py-3">
                                <form wire:submit="updateReferrer" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-start">
                                    <div>
                                        <input type="text" wire:model="editName" placeholder="{{ __('Nama') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        @error('editName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <input type="text" wire:model="editPhone" placeholder="{{ __('HP') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        @error('editPhone') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <select wire:model="editType" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @foreach ($referrerTypes as $referrerType)
                                                <option value="{{ $referrerType->value }}">{{ $referrerType->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <label class="flex items-center gap-2 text-sm text-gray-700 mt-2">
                                        <input type="checkbox" wire:model="editIsActive"> {{ __('Aktif') }}
                                    </label>
                                    <div class="flex gap-2">
                                        <button type="submit" class="text-sm px-3 py-1.5 bg-green-600 text-white rounded-md hover:bg-green-700">{{ __('Simpan') }}</button>
                                        <button type="button" wire:click="cancelEdit" class="text-sm px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Batal') }}</button>
                                    </div>
                                </form>
                            </td>
                        @else
                            <td class="px-4 py-2 text-sm text-gray-800">{{ $referrer->name }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $referrer->phone }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $referrer->type->label() }}</td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $referrer->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $referrer->is_active ? __('Aktif') : __('Nonaktif') }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600">
                                {{ $referrer->user_id ? __('Ada') : __('Belum ada') }}
                            </td>
                            <td class="px-4 py-2 text-sm text-right space-x-2 whitespace-nowrap">
                                @if ($canManage)
                                    <button wire:click="edit({{ $referrer->id }})" class="text-primary hover:underline">{{ __('Edit') }}</button>

                                    @if (! $referrer->user_id)
                                        <button wire:click="generateLoginAccount({{ $referrer->id }})" wire:confirm="{{ __('Buat akun login baru untuk referrer ini?') }}" class="text-primary hover:underline">
                                            {{ __('Buat Akun Login') }}
                                        </button>
                                        <button wire:click="openLinkUser({{ $referrer->id }})" class="text-primary hover:underline">
                                            {{ __('Hubungkan User') }}
                                        </button>
                                    @endif

                                    @if ($referrer->is_active)
                                        <button wire:click="deactivateReferrer({{ $referrer->id }})" wire:confirm="{{ __('Nonaktifkan referrer ini?') }}" class="text-red-600 hover:underline">
                                            {{ __('Nonaktifkan') }}
                                        </button>
                                    @endif
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada referrer.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $referrers->links() }}
    </div>

    @if ($linkingReferrerId)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="cancelLinkUser">
            <div class="bg-white rounded-md shadow-lg p-6 w-full max-w-md space-y-4">
                <h2 class="text-lg font-semibold text-gray-800">{{ __('Hubungkan User Existing') }}</h2>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Pilih User') }}</label>
                    <select wire:model="selectedUserId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">{{ __('Pilih user...') }}</option>
                        @foreach ($availableUsers as $user)
                            <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </select>
                    @error('selectedUserId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelLinkUser" class="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Batal') }}</button>
                    <button type="button" wire:click="confirmLinkUser" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90">{{ __('Hubungkan') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
