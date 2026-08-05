<div class="p-6 max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-2">
        <h1 class="text-2xl font-semibold" style="color: var(--color-text)">NAS (Router Mikrotik)</h1>
        @if (! $showForm)
            <button wire:click="create" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                Tambah NAS
            </button>
        @endif
    </div>
    <p class="text-sm text-gray-500 mb-6">
        @if ($isAdmin)
            Semua NAS termasuk milik reseller dan yang direct (tanpa reseller).
        @else
            NAS milik reseller {{ $currentResellerName }}.
        @endif
    </p>

    @if (session('status'))
        <div class="mb-4 text-sm text-green-600">{{ session('status') }}</div>
    @endif

    {{-- FORM CREATE/EDIT --}}
    @if ($showForm)
        <div class="p-4 rounded-md border border-gray-200 space-y-4 mb-6">
            <h2 class="font-medium">{{ $editingNasId ? 'Edit NAS' : 'Tambah NAS Baru' }}</h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Nama Router</label>
                    <input type="text" wire:model="name" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                @if ($isAdmin)
                    <div>
                        <label class="block text-sm font-medium mb-1">Reseller</label>
                        <select wire:model="resellerId" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <option value="">— Direct (tanpa reseller) —</option>
                            @foreach ($resellers as $reseller)
                                <option value="{{ $reseller->id }}">{{ $reseller->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium mb-1">Zona Waktu</label>
                    <input type="text" wire:model="timezone" placeholder="Asia/Jakarta" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('timezone') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">
                        IP Router
                        @if ($mikrotikIpLocked)
                            <span class="text-xs text-amber-600 font-normal">(terkunci — sudah lewat provisioning VPN)</span>
                        @endif
                    </label>
                    <input
                        type="text" wire:model="mikrotikIp" placeholder="192.168.1.1"
                        @disabled($mikrotikIpLocked)
                        class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm {{ $mikrotikIpLocked ? 'bg-gray-100 text-gray-500' : '' }}"
                    >
                    @error('mikrotikIp') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    @if (! $mikrotikIpLocked)
                        <p class="text-xs text-gray-400 mt-1">Boleh diisi manual untuk NAS yang belum lewat VPN provisioning (v0.6.2).</p>
                    @endif
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Port API</label>
                    <input type="number" wire:model="apiPort" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('apiPort') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Username API</label>
                    <input type="text" wire:model="apiUsername" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('apiUsername') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Password API</label>
                    <input type="password" wire:model="apiPassword" placeholder="{{ $editingNasId ? '•••••••• (biarkan kosong kalau tidak diubah)' : '' }}" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('apiPassword') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Secret RADIUS</label>
                    <input type="password" wire:model="radiusSecret" placeholder="{{ $editingNasId ? '•••••••• (biarkan kosong kalau tidak diubah)' : '' }}" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('radiusSecret') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2">
                    <label class="block text-sm font-medium mb-1">Deskripsi</label>
                    <textarea wire:model="description" rows="2" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></textarea>
                </div>
            </div>

            @php($ports = $this->currentPorts())
            <div class="p-3 rounded-md bg-gray-50 text-xs text-gray-500">
                @if ($ports['auth_port'] !== null)
                    Authentication Port / Accounting Port / CoA Port:
                    <span class="font-mono">{{ $ports['auth_port'] }} / {{ $ports['acct_port'] }} / {{ $ports['coa_port'] }}</span>
                    (auth/acct teralokasi otomatis dan unik untuk NAS ini; CoA port bisa diedit manual, default 3799).
                @else
                    Auth/Accounting Port akan teralokasi otomatis begitu NAS ini disimpan.
                @endif
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button wire:click="save" wire:loading.attr="disabled" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                    Simpan
                </button>
                <button wire:click="testConnection" wire:loading.attr="disabled" type="button" class="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50 text-sm">
                    Tes Koneksi
                </button>
                <button wire:click="cancel" type="button" class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">
                    Batal
                </button>
            </div>

            @if ($testConnectionResult)
                <div class="text-sm {{ $testConnectionResult['status'] === 'success' ? 'text-green-600' : 'text-red-600' }}">
                    {{ $testConnectionResult['message'] }}
                </div>
            @endif
        </div>
    @endif

    {{-- PROVISION USER API MODAL (v0.6.5) — deliberately its own isolated
         form, never sharing fields with the NAS edit form above. Username/
         password here are the router owner's REAL admin login, used once
         and never persisted — see NasApiUserProvisioningService's docblock
         for why this used to be conflated with nas.api_username/
         api_password and caused a real credential-rotation bug. --}}
    @if ($showProvisionApiModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closeProvisionApiModal">
            <div class="bg-white rounded-md p-6 w-full max-w-md space-y-4">
                <h2 class="font-medium">Buat / Perbarui User API</h2>
                <p class="text-xs text-gray-500">
                    Masukkan username &amp; password ADMIN ASLI router ini (bukan user API BOSS App).
                    Dipakai sekali untuk membuat/memperbarui user API khusus BOSS App dengan hak akses
                    terbatas — kredensial admin ini TIDAK PERNAH disimpan.
                </p>

                <div>
                    <label class="block text-sm font-medium mb-1">Username Admin</label>
                    <input type="text" wire:model="provisionAdminUsername" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('provisionAdminUsername') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Password Admin</label>
                    <input type="password" wire:model="provisionAdminPassword" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('provisionAdminPassword') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                @if ($provisionApiResult)
                    <div class="text-sm {{ $provisionApiResult['status'] === 'success' ? 'text-green-600' : 'text-red-600' }}">
                        {{ $provisionApiResult['message'] }}
                    </div>
                @endif

                <div class="flex items-center gap-3 pt-2">
                    <button wire:click="provisionApiUser" wire:loading.attr="disabled" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                        Provision
                    </button>
                    <button wire:click="closeProvisionApiModal" type="button" class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- LIST --}}
    <div class="border border-gray-200 rounded-md overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Nama</th>
                    @if ($isAdmin)
                        <th class="px-4 py-2">Reseller</th>
                    @endif
                    <th class="px-4 py-2">IP Router</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Tes Terakhir</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($nasList as $nas)
                    <tr class="border-t border-gray-100">
                        <td class="px-4 py-2">{{ $nas->name }}</td>
                        @if ($isAdmin)
                            <td class="px-4 py-2">{{ $nas->reseller?->name ?? 'Direct' }}</td>
                        @endif
                        <td class="px-4 py-2 font-mono text-xs">{{ $nas->mikrotik_ip ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span class="{{ $nas->status->value === 'online' ? 'text-green-600' : ($nas->status->value === 'offline' ? 'text-red-600' : 'text-gray-400') }}">
                                {{ $nas->status->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-400">
                            {{ $nas->last_ping_at?->diffForHumans() ?? '—' }}
                        </td>
                        <td class="px-4 py-2 text-right space-x-2">
                            <button wire:click="edit({{ $nas->id }})" class="text-primary hover:underline">Edit</button>
                            <button wire:click="openProvisionApiModal({{ $nas->id }})" class="text-primary hover:underline">User API</button>
                            <button wire:click="delete({{ $nas->id }})" wire:confirm="Hapus NAS ini?" class="text-red-500 hover:underline">Hapus</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $isAdmin ? 6 : 5 }}" class="px-4 py-6 text-center text-gray-400">
                            Belum ada NAS — klik "Tambah NAS" untuk membuat yang pertama.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $nasList->links() }}
    </div>
</div>
