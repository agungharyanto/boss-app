<div class="p-4 bg-white border border-gray-200 rounded-md">
    <p class="text-sm text-gray-500 mb-3">{{ __('Status Registrasi') }}</p>

    <div class="space-y-1.5">
        @foreach ($breakdown as $row)
            <div class="flex justify-between text-sm">
                <span style="color: var(--color-text)">{{ $row['label'] }}</span>
                <span class="font-medium" style="color: var(--color-text)">{{ $row['total'] }}</span>
            </div>
        @endforeach
    </div>
</div>
