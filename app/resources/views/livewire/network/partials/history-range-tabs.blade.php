{{--
    v0.8.3 — shared "Hourly/Daily/Weekly/Monthly/Yearly + Custom" tab row,
    included (not duplicated) by every history modal that reuses
    CpeSignalHistoryRange's 5-tab vocabulary: CpeSignalHistoryGraph's
    modal, DeviceHistoryModal, DeviceTrafficGraph's modal. See CLAUDE.md's
    own section on this for why one partial, not three implementations.

    Expects, from the including view's own Livewire component (all three
    hosts expose these — App\Livewire\Concerns\ValidatesCustomHistoryRange
    provides customRangeMode/customFrom/customTo/customRangeError/
    selectCustomRangeTab() automatically; each host still defines its own
    applyCustomRange()):
      - $ranges              CpeSignalHistoryRange::cases()
      - $currentRangeValue   the host's own current range STRING (e.g.
                              $modalRange, $range) — plain string
                              comparison against $r->value, never the enum
                              instance itself, so this partial works
                              identically regardless of whether a host
                              stores its range as a string or resolves an
                              enum instance separately for other purposes.
      - $changeRangeMethod   name of the host's own preset-tab handler
                              (e.g. "changeModalRange", "changeRange")
--}}
<div class="flex items-center gap-1 flex-wrap" role="tablist" aria-label="{{ __('Rentang waktu') }}">
    @foreach ($ranges as $r)
        <button
            type="button"
            role="tab"
            aria-selected="{{ (! $customRangeMode && $r->value === $currentRangeValue) ? 'true' : 'false' }}"
            wire:click="{{ $changeRangeMethod }}('{{ $r->value }}')"
            class="px-3 py-1 text-xs rounded-md border {{ (! $customRangeMode && $r->value === $currentRangeValue) ? 'bg-primary text-white border-primary' : 'border-gray-300 text-gray-600 hover:bg-gray-50' }}"
        >
            {{ $r->label() }}
        </button>
    @endforeach
    <button
        type="button"
        role="tab"
        aria-selected="{{ $customRangeMode ? 'true' : 'false' }}"
        wire:click="selectCustomRangeTab"
        class="px-3 py-1 text-xs rounded-md border {{ $customRangeMode ? 'bg-primary text-white border-primary' : 'border-gray-300 text-gray-600 hover:bg-gray-50' }}"
    >
        {{ __('Custom') }}
    </button>
</div>

@if ($customRangeMode)
    <div class="flex items-end gap-2 flex-wrap bg-gray-50 border border-gray-200 rounded-md p-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('Dari') }}</label>
            <input type="date" wire:model="customFrom" class="border-gray-300 rounded-md px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('Sampai') }}</label>
            <input type="date" wire:model="customTo" class="border-gray-300 rounded-md px-3 py-2 text-sm">
        </div>
        <button
            type="button"
            wire:click="applyCustomRange"
            wire:loading.attr="disabled"
            class="px-3 py-2 text-sm bg-primary text-white rounded-md hover:opacity-90"
        >
            {{ __('Terapkan') }}
        </button>
    </div>
    @if ($customRangeError)
        <p class="text-xs text-red-600">{{ $customRangeError }}</p>
    @endif
@endif
