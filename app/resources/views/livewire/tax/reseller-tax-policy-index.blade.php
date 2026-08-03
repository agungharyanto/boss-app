<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Reseller Tax Policy') }}</h1>

        @if (! $isAdmin && $currentReseller)
            <p class="text-sm text-gray-500">{{ __('Menampilkan policy untuk') }}: <strong>{{ $currentReseller->name }}</strong></p>
        @endif

        @if ($canCreate)
            <button
                wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
            >
                {{ $showCreateForm ? __('Batal') : __('+ Set Policy Baru') }}
            </button>
        @endif
    </div>

    @if ($showCreateForm)
        <form wire:submit="createPolicy" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-3">
            @if ($isAdmin)
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Reseller') }}</label>
                    <select wire:model="targetResellerId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">{{ __('-- Direct Retail (ISP langsung) --') }}</option>
                        @foreach ($resellers as $reseller)
                            <option value="{{ $reseller->id }}">{{ $reseller->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Tax Component') }}</label>
                <select wire:model="tax_component_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">{{ __('-- Pilih --') }}</option>
                    @foreach ($taxComponents as $component)
                        <option value="{{ $component->id }}">{{ $component->code }} ({{ $component->name }})</option>
                    @endforeach
                </select>
                @error('tax_component_id') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Burden') }}</label>
                <select wire:model.live="burden" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="customer_borne">{{ __('Customer Borne') }}</option>
                    <option value="reseller_borne">{{ __('Reseller Borne') }}</option>
                    <option value="split">{{ __('Split') }}</option>
                </select>
            </div>

            @if ($burden === 'split')
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Split Ratio — % ditanggung Customer') }}</label>
                    <input type="number" step="0.01" min="0" max="100" wire:model="split_ratio" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('split_ratio') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Berlaku Sejak') }}</label>
                <input type="date" wire:model="effective_from" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('effective_from') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                {{ __('Simpan') }}
            </button>
        </form>
    @endif

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Reseller') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Tax Component') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Burden') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Split Ratio') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Berlaku') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($policies as $policy)
                    <tr wire:key="policy-{{ $policy->id }}">
                        <td class="px-4 py-2 text-sm text-gray-800">{{ $policy->reseller?->name ?? __('Direct Retail') }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $policy->taxComponent->code }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $policy->burden->label() }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $policy->split_ratio !== null ? $policy->split_ratio . '%' : '—' }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            {{ $policy->effective_from->toDateString() }}
                            @if ($policy->effective_to)
                                &ndash; {{ $policy->effective_to->toDateString() }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada policy.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $policies->links() }}
    </div>
</div>
