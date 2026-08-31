<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Topologi Fiber') }}</h1>

        @if ($canManage)
            <a href="{{ route('web.fiber-nodes.create') }}" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90">
                {{ __('+ Titik Baru') }}
            </a>
        @endif
    </div>

    <div class="flex flex-wrap gap-3 mb-4">
        <select wire:model.live="nodeTypeFilter" class="rounded-md border-gray-300 shadow-sm text-sm">
            <option value="">{{ __('Semua Tipe') }}</option>
            <option value="otb">{{ __('OTB') }}</option>
            <option value="closure">{{ __('Closure') }}</option>
            <option value="odc">{{ __('ODC') }}</option>
            <option value="odp">{{ __('ODP') }}</option>
        </select>
        <input
            type="text"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Cari kode/label...') }}"
            class="rounded-md border-gray-300 shadow-sm text-sm flex-1 min-w-[200px]"
        >
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left font-medium text-gray-600">{{ __('Tipe') }}</th>
                    <th class="px-4 py-2 text-left font-medium text-gray-600">{{ __('Label / Kode') }}</th>
                    <th class="px-4 py-2 text-left font-medium text-gray-600">{{ __('Koordinat') }}</th>
                    <th class="px-4 py-2 text-right font-medium text-gray-600">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($points as $point)
                    <tr>
                        <td class="px-4 py-2 uppercase text-xs font-semibold text-gray-500">{{ $point->node_type }}</td>
                        <td class="px-4 py-2">{{ $point->label }}</td>
                        <td class="px-4 py-2 text-gray-500">
                            @if ($point->latitude !== null && $point->longitude !== null)
                                {{ $point->latitude }}, {{ $point->longitude }}
                                {{-- v0.16.0 Langkah 4 — Google Maps direction icon, functional
                                     addition only (no visual re-polish of this pre-existing page). --}}
                                <a
                                    href="https://www.google.com/maps/dir/?api=1&destination={{ $point->latitude }},{{ $point->longitude }}"
                                    target="_blank" rel="noopener"
                                    title="{{ __('Buka arah di Google Maps') }}"
                                    class="inline-block align-text-bottom text-primary hover:opacity-75"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 inline" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M9.69 18.933l.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 00.281-.14c.186-.096.446-.24.757-.433.62-.384 1.445-.966 2.274-1.765C15.302 14.988 17 12.493 17 9A7 7 0 103 9c0 3.492 1.698 5.988 3.355 7.584a13.731 13.731 0 002.273 1.765 11.842 11.842 0 00.976.544l.062.029.018.008.006.003zM10 11.25a2.25 2.25 0 100-4.5 2.25 2.25 0 000 4.5z" clip-rule="evenodd" />
                                    </svg>
                                </a>
                            @else
                                <span class="text-gray-400">{{ __('Belum ada') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right space-x-2">
                            @if ($point->source === 'fiber_node')
                                <a href="{{ route('web.fiber-nodes.detail', $point->id) }}" class="text-primary hover:underline">{{ __('Detail') }}</a>
                                <a href="{{ route('web.fiber-nodes.edit', $point->id) }}" class="text-primary hover:underline">{{ __('Edit') }}</a>
                                @if ($canManage)
                                    <button
                                        type="button"
                                        wire:click="deleteNode({{ $point->id }})"
                                        wire:confirm="{{ __('Hapus titik ini? Kabel/splitter yang masih menempel padanya tidak ikut terhapus.') }}"
                                        class="text-red-600 hover:underline"
                                    >{{ __('Hapus') }}</button>
                                @endif
                            @else
                                <a href="{{ route('web.odps.detail', $point->id) }}" class="text-primary hover:underline">{{ __('Detail') }}</a>
                                <a href="{{ route('web.odps.edit', $point->id) }}" class="text-primary hover:underline">{{ __('Edit') }}</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-gray-400">{{ __('Belum ada titik topologi.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
