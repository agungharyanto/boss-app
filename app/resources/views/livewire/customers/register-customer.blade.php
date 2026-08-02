<div class="p-6 max-w-2xl mx-auto">
    <a href="{{ route('web.customers.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Kembali ke daftar pelanggan</a>

    <h1 class="text-2xl font-semibold text-gray-800 mt-2 mb-6">Registrasi Pelanggan Baru</h1>

    <form wire:submit="register" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">Nama</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
            @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Nomor Telepon</label>
            <input type="text" wire:model="phone_number" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
            @error('phone_number') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">NIK</label>
            <input type="text" wire:model="nik" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
            @error('nik') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Alamat</label>
            <textarea wire:model="address" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
            @error('address') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">Latitude</label>
                <input type="text" wire:model="latitude" placeholder="-6.200000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('latitude') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Longitude</label>
                <input type="text" wire:model="longitude" placeholder="106.816666" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('longitude') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
        </div>
        <p class="text-xs text-gray-500 -mt-2">Input manual dulu — peta interaktif menyusul.</p>

        <div>
            <label class="block text-sm font-medium text-gray-700">Paket</label>
            <input type="text" wire:model="package" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
            @error('package') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Agent Referral</label>

            @if ($linkedAgent)
                <input type="text" value="{{ $linkedAgent->name }} ({{ $linkedAgent->type->label() }})" disabled
                    class="mt-1 block w-full rounded-md border-gray-300 bg-gray-100 text-gray-500 shadow-sm">
                <p class="text-xs text-gray-500 mt-1">Otomatis terisi sesuai akun Anda yang login.</p>
            @else
                <select wire:model="selectedAgentId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">Tidak ada referral</option>
                    @foreach ($availableAgents as $agent)
                        <option value="{{ $agent->id }}">{{ $agent->name }} ({{ $agent->type->label() }})</option>
                    @endforeach
                </select>
                @error('selectedAgentId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            @endif
        </div>

        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
            Daftarkan Pelanggan
        </button>
    </form>
</div>
