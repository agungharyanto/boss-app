<div class="mt-8 space-y-6">
    {{-- Kartu: Rekening Pembayaran --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-gray-800">Rekening Pembayaran (transfer manual)</h2>
        </div>

        @if (session('bank-status'))
            <div class="mb-3 text-sm text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2">{{ session('bank-status') }}</div>
        @endif
        @if (session('bank-error'))
            <div class="mb-3 text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2">{{ session('bank-error') }}</div>
        @endif

        @forelse ($accounts as $acc)
            <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 py-2" wire:key="bank-{{ $acc->id }}">
                @if ($editingId === $acc->id)
                    <input type="text" wire:model="editBankName" placeholder="Bank" class="border-gray-300 rounded-md px-2 py-1 text-sm w-28">
                    <input type="text" wire:model="editAccountNumber" placeholder="No. Rekening" class="border-gray-300 rounded-md px-2 py-1 text-sm w-40">
                    <input type="text" wire:model="editAccountHolder" placeholder="Atas Nama" class="border-gray-300 rounded-md px-2 py-1 text-sm w-40">
                    <button wire:click="saveEdit" class="text-sm text-indigo-600 hover:underline">Simpan</button>
                    <button wire:click="cancelEdit" class="text-sm text-gray-500 hover:underline">Batal</button>
                    @error('editBankName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    @error('editAccountNumber') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    @error('editAccountHolder') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                @else
                    <span class="w-5 text-amber-500">@if ($acc->is_default)&#9733;@endif</span>
                    <span class="text-sm font-medium text-gray-800 w-28">{{ $acc->bank_name }}</span>
                    <span class="text-sm text-gray-700 w-40">{{ $acc->account_number }}</span>
                    <span class="text-sm text-gray-600 flex-1 min-w-[8rem]">a.n. {{ $acc->account_holder }}</span>
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $acc->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">{{ $acc->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                    @if ($acc->is_default)<span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">Default</span>@endif
                    <button wire:click="startEdit({{ $acc->id }})" class="text-sm text-indigo-600 hover:underline">Ubah</button>
                    @unless ($acc->is_default)
                        <button wire:click="makeDefault({{ $acc->id }})" class="text-sm text-indigo-600 hover:underline">Jadikan Default</button>
                    @endunless
                    <button wire:click="toggleActive({{ $acc->id }})" class="text-sm text-gray-600 hover:underline">{{ $acc->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                    <button wire:click="deleteBank({{ $acc->id }})" wire:confirm="Hapus rekening ini?" class="text-sm text-red-600 hover:underline">Hapus</button>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500 py-2">Belum ada rekening. Tambahkan di bawah — rekening pertama otomatis jadi default.</p>
        @endforelse

        {{-- Form tambah --}}
        <div class="flex flex-wrap items-center gap-2 mt-4">
            <input type="text" wire:model="newBankName" placeholder="Bank (mis. BCA)" class="border-gray-300 rounded-md px-2 py-1 text-sm w-32">
            <input type="text" wire:model="newAccountNumber" placeholder="No. Rekening" class="border-gray-300 rounded-md px-2 py-1 text-sm w-44">
            <input type="text" wire:model="newAccountHolder" placeholder="Atas Nama" class="border-gray-300 rounded-md px-2 py-1 text-sm w-44">
            <button wire:click="addBank" class="px-3 py-1.5 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700">+ Tambah Rekening</button>
        </div>
        @error('newBankName') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
        @error('newAccountNumber') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
        @error('newAccountHolder') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror

        <div class="mt-4">
            <button wire:click="$toggle('showLogs')" class="text-xs text-gray-500 hover:underline">{{ $showLogs ? 'Sembunyikan' : 'Lihat' }} riwayat perubahan rekening</button>
            @if ($showLogs)
                <div class="mt-2 text-xs text-gray-600 space-y-1">
                    @forelse ($logs as $log)
                        <div wire:key="log-{{ $log->id }}">{{ $log->created_at?->format('d/m/Y H:i') }} — <strong>{{ $log->action }}</strong> {{ $log->after['bank_name'] ?? ($log->before['bank_name'] ?? '') }} {{ $log->after['account_number'] ?? ($log->before['account_number'] ?? '') }} (oleh user #{{ $log->user_id ?? 'sistem' }})</div>
                    @empty
                        <div class="text-gray-400">Belum ada riwayat.</div>
                    @endforelse
                </div>
            @endif
        </div>
    </div>

    {{-- Kartu: Metode Pembayaran di Invoice --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <h2 class="text-base font-semibold text-gray-800 mb-3">Metode Pembayaran di Invoice</h2>
        @if (session('method-status'))
            <div class="mb-3 text-sm text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2">{{ session('method-status') }}</div>
        @endif
        <div class="space-y-3">
            <label class="flex items-start gap-2">
                <input type="checkbox" wire:model="manualEnabled" class="mt-1">
                <span class="text-sm"><strong>Transfer Manual</strong> — tampilkan daftar rekening di atas (default paling atas).</span>
            </label>
            <label class="flex items-start gap-2">
                <input type="checkbox" wire:model="gatewayEnabled" class="mt-1">
                <span class="text-sm"><strong>Payment Gateway (Xendit)</strong> — hanya menampilkan tautan bayar bila channel Xendit diaktifkan terpisah di Pengaturan Payment Gateway. Saat ini channel nonaktif.</span>
            </label>
            <label class="flex items-start gap-2">
                <input type="checkbox" wire:model="agentEnabled" class="mt-1">
                <span class="text-sm"><strong>Bayar via Agen</strong> — menampilkan kontak Referrer/Sales penagih (alur Titip) bila pelanggan punya referrer.</span>
            </label>
        </div>
        <div class="mt-4">
            <button wire:click="saveSwitches" class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700">Simpan Metode</button>
        </div>
    </div>
</div>
