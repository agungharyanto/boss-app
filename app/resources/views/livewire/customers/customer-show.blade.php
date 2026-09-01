<div class="p-6 max-w-4xl mx-auto space-y-6">
    <a href="{{ route('web.customers.index') }}" class="text-sm text-primary hover:underline">&larr; Kembali ke daftar pelanggan</a>

    {{-- Profil --}}
    <div class="p-4 border border-gray-200 rounded-md">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-800">{{ $customer->name }}</h1>
                <p class="text-sm text-gray-500 font-mono">{{ $customer->cid ?? '— (CID belum tersedia)' }}</p>
            </div>
            <span class="px-2 py-1 text-xs rounded-full bg-gray-100">{{ $customer->status->label() }}</span>
        </div>

        @if ($editingProfile)
            <form wire:submit="updateProfile" class="space-y-3">
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
                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Simpan</button>
                    <button type="button" wire:click="$set('editingProfile', false)" class="px-4 py-2 bg-gray-200 rounded-md hover:bg-gray-300">Batal</button>
                </div>
            </form>
        @else
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-500">Alamat</dt>
                <dd class="text-gray-800">{{ $customer->address }}</dd>
                <dt class="text-gray-500">Telepon</dt>
                <dd class="text-gray-800">{{ $customer->phone_number }}</dd>
            </dl>

            @if ($canManage)
                <button wire:click="startEditingProfile" class="mt-3 text-sm text-primary hover:underline">
                    Edit profil
                </button>
            @endif
        @endif
    </div>

    {{-- Paket & Referral (v0.9.4 — link komisi saja, TIDAK terkait billing/subscriptions) --}}
    <div class="p-4 border border-gray-200 rounded-md">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">Paket &amp; Referral</h2>

        @if ($editingCommission)
            <form wire:submit="updateCommissionAttribution" class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Paket</label>
                    <select wire:model.live="editPppPackageId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">— Tidak ada paket —</option>
                        @foreach ($availablePackages as $package)
                            <option value="{{ $package->id }}">{{ $package->name }}</option>
                        @endforeach
                    </select>
                    @error('editPppPackageId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Referrer</label>
                    <select wire:model.live="editReferrerId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">Tidak ada referral</option>
                        @foreach ($availableReferrers as $referrer)
                            <option value="{{ $referrer->id }}">{{ $referrer->name }} ({{ $referrer->type->label() }})</option>
                        @endforeach
                    </select>
                    @error('editReferrerId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    @if ($customer->referred_by_referrer_id !== null)
                        <p class="text-xs text-amber-700 mt-1">Pelanggan ini sudah punya referrer. Mengubahnya hanya memperbarui atribusi — tidak membuat entri komisi baru.</p>
                    @endif
                </div>

                @if ($showCommissionSchemeField)
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Skema Komisi</label>
                        <select wire:model="editCommissionScheme" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">— Tidak ditentukan —</option>
                            @foreach ($commissionSchemeOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Hanya dipakai kalau referrer baru di-set untuk pelanggan yang sebelumnya belum punya referrer.</p>
                    </div>
                @endif

                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Simpan</button>
                    <button type="button" wire:click="cancelEditingCommission" class="px-4 py-2 bg-gray-200 rounded-md hover:bg-gray-300">Batal</button>
                </div>
            </form>
        @else
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-500">Paket</dt>
                <dd class="text-gray-800">{{ $currentPppPackage?->name ?? '—' }}</dd>
                <dt class="text-gray-500">Referrer</dt>
                <dd class="text-gray-800">{{ $currentReferrer ? $currentReferrer->name.' ('.$currentReferrer->type->label().')' : 'Tidak ada referral' }}</dd>
            </dl>

            @if ($canManage)
                <button wire:click="startEditingCommission" class="mt-3 text-sm text-primary hover:underline">
                    Edit paket &amp; referral
                </button>
            @endif
        @endif
    </div>

    {{-- Status lifecycle --}}
    @if ($canManage)
        <div class="p-4 border border-gray-200 rounded-md">
            <h2 class="text-sm font-semibold text-gray-700 mb-2">Ubah Status</h2>

            @if ($availableTransitions->isEmpty())
                <p class="text-sm text-gray-500">Status ini bersifat final, tidak ada transisi yang tersedia.</p>
            @else
                <form wire:submit="updateStatus" class="flex gap-2">
                    <select wire:model="selectedStatus" class="rounded-md border-gray-300 shadow-sm">
                        <option value="">Pilih status tujuan...</option>
                        @foreach ($availableTransitions as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90">Ubah</button>
                </form>
                @error('selectedStatus') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            @endif
        </div>
    @endif

    {{-- Device CPE --}}
    <div class="p-4 border border-gray-200 rounded-md">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">Device CPE</h2>

        @if (session('device_bound_message'))
            <p class="text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2 mb-3">
                {{ session('device_bound_message') }}
            </p>
        @endif

        @if ($cpeDevice)
            <dl class="grid grid-cols-2 gap-2 text-sm mb-2">
                <dt class="text-gray-500">Serial Number</dt>
                <dd class="text-gray-800 font-mono">{{ $cpeDevice->serial_number }}</dd>
                <dt class="text-gray-500">Status</dt>
                <dd class="text-gray-800">{{ $cpeDevice->status->label() }}</dd>
            </dl>
            <p class="text-xs text-gray-500">
                Sudah ter-bind ke device ini. Mau ganti perangkat? Pakai
                <a href="{{ route('web.cpe-devices.index') }}" class="text-primary hover:underline">"Ganti Modem" di Perangkat CPE</a>,
                bukan form di halaman ini — supaya tidak ada dua jalur yang bisa saling tabrakan untuk customer yang sudah punya device.
            </p>
        @else
            <p class="text-sm text-gray-500 mb-3">Customer ini belum punya device CPE ter-bind.</p>

            @if ($canAddDevice)
                @if ($showAddDeviceForm)
                    <form wire:submit="bindDevice" class="p-3 bg-gray-50 rounded-md space-y-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Serial Number</label>
                            <input type="text" wire:model="newDeviceSerial" placeholder="mis. ZTEGCB399CEB" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm font-mono text-sm">
                            @error('newDeviceSerial') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Bind Device</button>
                            <button type="button" wire:click="cancelAddDeviceForm" class="px-4 py-2 bg-gray-200 rounded-md hover:bg-gray-300">Batal</button>
                        </div>
                    </form>
                @else
                    <button wire:click="openAddDeviceForm" class="text-sm text-primary hover:underline">+ Tambah Device CPE</button>
                @endif
            @endif
        @endif
    </div>

    {{-- Kontak keluarga --}}
    <div class="p-4 border border-gray-200 rounded-md">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-gray-700">Kontak Keluarga</h2>
            @if ($canManage)
                <button wire:click="openContactForm" class="text-sm text-primary hover:underline">+ Tambah kontak</button>
            @endif
        </div>

        @if ($showContactForm)
            <form wire:submit="saveContact" class="mb-4 p-3 bg-gray-50 rounded-md space-y-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Nama</label>
                    <input type="text" wire:model="contactName" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('contactName') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Telepon</label>
                    <input type="text" wire:model="contactPhone" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('contactPhone') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Hubungan Keluarga</label>
                    <input type="text" wire:model="contactRelationship" placeholder="Istri, Anak, dst." class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Access Level</label>
                    <select wire:model="contactAccessLevel" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">Pilih...</option>
                        @foreach ($accessLevels as $level)
                            <option value="{{ $level->value }}">{{ $level->label() }}</option>
                        @endforeach
                    </select>
                    @error('contactAccessLevel') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div class="space-y-1">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="contactCanViewBilling"> Bisa lihat billing
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="contactCanRequestServiceChange"> Bisa request perubahan layanan
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="contactCanReceiveNotifications"> Terima notifikasi
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="contactIsAuthorized"> Jadikan authorized contact
                    </label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Simpan</button>
                    <button type="button" wire:click="cancelContactForm" class="px-4 py-2 bg-gray-200 rounded-md hover:bg-gray-300">Batal</button>
                </div>
            </form>
        @endif

        <ul class="divide-y divide-gray-200">
            @forelse ($contacts as $contact)
                <li wire:key="contact-{{ $contact->id }}" class="py-2 flex items-center justify-between text-sm">
                    <div>
                        <span class="font-medium">{{ $contact->name }}</span>
                        <span class="text-gray-500">({{ $contact->relationship ?? '-' }}) &middot; {{ $contact->phone_number }}</span>
                        <span class="px-2 py-0.5 text-xs rounded-full bg-gray-100">{{ $contact->access_level->label() }}</span>
                        @if ($contact->is_authorized_contact)
                            <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800">Authorized Contact</span>
                        @endif
                    </div>
                    @if ($canManage)
                        <div class="flex gap-3">
                            <button wire:click="openContactForm({{ $contact->id }})" class="text-primary hover:underline">Edit</button>
                            <button wire:click="deleteContact({{ $contact->id }})" wire:confirm="Hapus kontak ini?" class="text-red-600 hover:underline">Hapus</button>
                        </div>
                    @endif
                </li>
            @empty
                <li class="py-3 text-center text-gray-500 text-sm">Belum ada kontak keluarga.</li>
            @endforelse
        </ul>
    </div>

    {{-- Timeline --}}
    <div class="p-4 border border-gray-200 rounded-md">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">Timeline</h2>
        <ul class="space-y-3">
            @forelse ($timelineEntries as $entry)
                <li wire:key="timeline-{{ $entry->id }}" class="text-sm border-l-2 border-gray-200 pl-3">
                    <p class="text-gray-800">{{ $entry->description }}</p>
                    <p class="text-xs text-gray-500">
                        {{ $entry->created_at->format('d M Y H:i') }}
                        @if ($entry->actor) &middot; oleh {{ $entry->actor->name }} @endif
                    </p>
                </li>
            @empty
                <li class="text-sm text-gray-500">Belum ada aktivitas.</li>
            @endforelse
        </ul>
        <div class="mt-3">
            {{ $timelineEntries->links() }}
        </div>
    </div>
</div>
