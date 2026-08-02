<div class="p-6 max-w-6xl mx-auto" x-data="{ showSelector: false }">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold" style="color: var(--color-text)">{{ __('Dashboard') }}</h1>

        <button
            type="button"
            x-on:click="showSelector = !showSelector"
            class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
        >
            {{ __('Atur Widget') }}
        </button>
    </div>

    <div x-show="showSelector" x-transition class="mb-6" x-on:widgets-updated.window="showSelector = false">
        <livewire:dashboard.widget-selector />
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4" wire:key="widget-grid">
        @forelse ($activeWidgets as $widget)
            @livewire($widget->component(), [], $widget->value)
        @empty
            <p class="text-gray-500 col-span-2">{{ __('Tidak ada widget yang ditampilkan.') }}</p>
        @endforelse
    </div>
</div>
