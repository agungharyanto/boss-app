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
                            @else
                                <span class="text-gray-400">{{ __('Belum ada') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right space-x-2">
                            @if ($point->source === 'fiber_node')
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
