<div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
        <a href="{{ route('web.resellers.index') }}" class="text-sm text-primary hover:underline">&larr; {{ __('Kembali ke Daftar Reseller') }}</a>
        <h1 class="text-2xl font-semibold text-gray-800 mt-2">{{ $reseller->name }}</h1>
        <p class="text-sm text-gray-500">{{ $reseller->email ?? '—' }} &middot; {{ $reseller->phone ?? '—' }}</p>
    </div>

    <div class="mb-8">
        <h2 class="text-lg font-semibold text-gray-800 mb-3">{{ __('Staff Reseller') }}</h2>

        <form wire:submit="attachStaff" class="mb-4 p-4 border border-gray-200 rounded-md bg-gray-50 flex gap-3 items-end flex-wrap">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700">{{ __('Email User') }}</label>
                <input type="email" wire:model="staffEmail" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('staffEmail') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Role') }}</label>
                <select wire:model="staffRole" class="mt-1 rounded-md border-gray-300 shadow-sm">
                    <option value="owner">{{ __('Owner') }}</option>
                    <option value="staff">{{ __('Staff') }}</option>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                {{ __('Tambah') }}
            </button>
        </form>

        <div class="overflow-x-auto border border-gray-200 rounded-md">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Nama') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Email') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Role') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($staffMembers as $member)
                        <tr wire:key="staff-{{ $member->id }}">
                            <td class="px-4 py-2 text-sm text-gray-800">{{ $member->name }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $member->email }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $member->pivot->role->label() }}</td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $member->pivot->status->value === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $member->pivot->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-sm text-right">
                                @if ($member->pivot->status->value === 'active')
                                    <button
                                        wire:click="detachStaff({{ $member->id }})"
                                        wire:confirm="{{ __('Nonaktifkan staff ini dari reseller?') }}"
                                        class="text-red-600 hover:underline"
                                    >
                                        {{ __('Nonaktifkan') }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                                {{ __('Belum ada staff.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
