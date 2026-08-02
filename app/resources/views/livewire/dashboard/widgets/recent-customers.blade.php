<div class="p-4 bg-white border border-gray-200 rounded-md">
    <p class="text-sm text-gray-500 mb-3">{{ __('Pelanggan Terbaru') }}</p>

    @forelse ($customers as $customer)
        <div class="py-1.5 text-sm border-t border-gray-100 first:border-t-0 flex justify-between">
            <span style="color: var(--color-text)">{{ $customer->name }}</span>
            <span class="text-gray-400">{{ $customer->created_at->diffForHumans() }}</span>
        </div>
    @empty
        <p class="text-sm text-gray-400">{{ __('Belum ada data pelanggan.') }}</p>
    @endforelse
</div>
