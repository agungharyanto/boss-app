<div class="p-4 bg-gray-50 border border-gray-200 rounded-md">
    <form wire:submit="save" class="space-y-3">
        <div class="space-y-2">
            @foreach ($widgets as $widget)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="selected.{{ $widget->value }}">
                    <span style="color: var(--color-text)">{{ $widget->label() }}</span>
                </label>
            @endforeach
        </div>

        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90">
            {{ __('Simpan') }}
        </button>
    </form>
</div>
