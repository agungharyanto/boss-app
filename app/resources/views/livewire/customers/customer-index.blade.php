<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Data Pelanggan') }}</h1>

        <div class="flex gap-2">
            @if ($canRegister)
                <a href="{{ route('web.customers.register') }}" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                    Registrasi Pelanggan
                </a>
            @endif

            @if ($canCreate)
                <button
                    wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                    class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
                >
                    {{ $showCreateForm ? 'Batal' : __('+ Pelanggan Baru') }}
                </button>
            @endif
        </div>
    </div>

    @if ($showCreateForm)
        <form wire:submit="createCustomer" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">Nama</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Alamat</label>
                <textarea wire:model="address" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
                @error('address') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Nomor Telepon Utama</label>
                <input type="text" wire:model="phone_number" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('phone_number') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                Simpan
            </button>
        </form>
    @endif

    <div class="flex gap-3 mb-4">
        <input
            type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama atau nomor telepon...') }}"
            class="flex-1 rounded-md border-gray-300 shadow-sm"
        >
        <select wire:model.live="statusFilter" class="rounded-md border-gray-300 shadow-sm">
            <option value="">{{ __('Semua Status') }}</option>
            @foreach (\App\Enums\CustomerStatus::cases() as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Nama</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Telepon</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($customers as $customer)
                    <tr wire:key="customer-{{ $customer->id }}">
                        <td class="px-4 py-2">{{ $customer->name }}</td>
                        <td class="px-4 py-2">{{ $customer->phone_number }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-1 text-xs rounded-full bg-gray-100">{{ $customer->status->label() }}</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('web.customers.show', $customer) }}" class="text-primary hover:underline">
                                {{ __('Detail') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-gray-500">{{ __('Belum ada data pelanggan.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $customers->links() }}
    </div>
</div>
