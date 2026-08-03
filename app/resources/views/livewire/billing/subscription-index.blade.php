<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Subscriptions') }}</h1>

        @if ($canCreate)
            <button
                wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
            >
                {{ $showCreateForm ? __('Batal') : __('+ Subscription Baru') }}
            </button>
        @endif
    </div>

    @if ($showCreateForm)
        <form wire:submit="createSubscription" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Customer') }}</label>
                <select wire:model="customer_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">{{ __('-- Pilih --') }}</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                    @endforeach
                </select>
                @error('customer_id') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Reseller Package Pricing ID (opsional)') }}</label>
                <input type="number" wire:model="reseller_package_pricing_id" placeholder="{{ __('Kosongkan utk direct-retail') }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <p class="mt-1 text-xs text-gray-500">{{ __('Kalau diisi, nama & harga otomatis mengikuti pricing tersebut.') }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nama Paket (kalau tanpa pricing ID)') }}</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Harga Bulanan (kalau tanpa pricing ID)') }}</label>
                <input type="number" step="0.01" wire:model="monthly_amount" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('monthly_amount') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Billing Cycle Day') }}</label>
                <input type="number" min="1" max="31" wire:model="billing_cycle_day" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('billing_cycle_day') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
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
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Reseller') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Paket') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Harga/bulan') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Cycle Day') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($subscriptions as $subscription)
                    <tr wire:key="subscription-{{ $subscription->id }}">
                        <td class="px-4 py-2 text-sm text-gray-800">{{ $subscription->customer?->name }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $subscription->reseller?->name ?? __('Direct') }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $subscription->name }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">Rp {{ number_format((float) $subscription->monthly_amount, 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $subscription->billing_cycle_day }}</td>
                        <td class="px-4 py-2 text-sm">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $subscription->status->value === 'active' ? 'bg-green-100 text-green-700' : ($subscription->status->value === 'suspended' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">
                                {{ $subscription->status->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-sm text-right space-x-2">
                            @if ($subscription->status->value === 'active')
                                <button wire:click="generateInvoiceNow({{ $subscription->id }})" class="text-primary hover:underline">{{ __('Generate Invoice') }}</button>
                                <button wire:click="suspend({{ $subscription->id }})" class="text-yellow-600 hover:underline">{{ __('Suspend') }}</button>
                            @elseif ($subscription->status->value === 'suspended')
                                <button wire:click="reactivate({{ $subscription->id }})" class="text-green-600 hover:underline">{{ __('Aktifkan') }}</button>
                            @endif
                            @if ($subscription->status->value !== 'cancelled')
                                <button
                                    wire:click="cancelSubscription({{ $subscription->id }})"
                                    wire:confirm="{{ __('Batalkan subscription ini?') }}"
                                    class="text-red-600 hover:underline"
                                >
                                    {{ __('Batalkan') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada subscription.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $subscriptions->links() }}
    </div>
</div>
