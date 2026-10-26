<div class="space-y-2">
    <h2 class="text-sm font-semibold text-gray-800">{{ __('Profil Provisioning (ZTP)') }}</h2>

    {{-- Hasil resolver (read) --}}
    <div class="text-xs bg-gray-50 border border-gray-200 rounded px-3 py-2">
        @if ($resolution->resolved())
            <span class="text-gray-500">{{ __('Ter-resolve ke') }}:</span>
            <span class="font-medium text-gray-800">{{ $resolution->profile->name }}</span>
            <span class="ml-1 text-[10px] uppercase tracking-wide bg-gray-100 text-gray-500 rounded px-1">{{ $resolution->source }}</span>
        @else
            <span class="text-amber-700">{{ __('Belum ada profil ter-resolve') }}</span>
            <span class="ml-1 text-[10px] uppercase tracking-wide bg-amber-100 text-amber-700 rounded px-1">{{ $resolution->source }}</span>
            @if ($resolution->reason)
                <p class="text-gray-500 mt-1">{{ $resolution->reason }}</p>
            @endif
        @endif
    </div>

    @if ($notice)
        <div class="text-xs text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2">{{ $notice }}</div>
    @endif

    @if ($canManage)
        <div class="flex items-end gap-2">
            <div>
                <label class="block text-xs text-gray-600 mb-1">{{ __('Override profil (opsional)') }}</label>
                <select wire:model="profileId" class="border-gray-300 rounded-md px-2 py-2 text-sm">
                    <option value="">{{ __('— Tanpa override (pakai resolusi paket) —') }}</option>
                    @foreach ($profileOptions as $opt)
                        <option value="{{ $opt->id }}">{{ $opt->name }}</option>
                    @endforeach
                </select>
            </div>
            <button wire:click="save" class="px-3 py-2 text-sm bg-primary text-white rounded-md hover:opacity-90">{{ __('Simpan override') }}</button>
        </div>
        <p class="text-[11px] text-gray-400">{{ __('Hanya menyimpan pilihan — tidak men-provision device.') }}</p>
    @endif
</div>
