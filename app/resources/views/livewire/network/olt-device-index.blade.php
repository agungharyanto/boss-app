@push('styles')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datatables/2.3.7/css/jquery.dataTables.min.css">
    @include('components.datatable-styles')
@endpush

<div class="p-6 w-full" x-data x-on:olt-device-saved.window="table.ajax.reload()">
    <div class="flex items-center justify-between mb-2">
        <h1 class="text-2xl font-semibold" style="color: var(--color-text)">{{ __('OLT') }}</h1>
        @if (! $showForm)
            <button wire:click="create" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                {{ __('Tambah OLT') }}
            </button>
        @endif
    </div>
    <p class="text-sm text-gray-500 mb-6">
        {{ __('Registry kredensial OLT — dipakai untuk onboarding LibreNMS dan (rencana masa depan) provisioning OMCI. Konektivitas selalu dites lewat router (NAS) yang dipilih, bukan langsung dari server BOSS App.') }}
    </p>

    @if (session('status'))
        <div class="mb-4 text-sm text-green-600">{{ session('status') }}</div>
    @endif

    {{-- FORM CREATE/EDIT --}}
    @if ($showForm)
        <div class="p-4 rounded-md border border-gray-200 space-y-4 mb-6">
            <h2 class="font-medium">{{ $editingOltDeviceId ? __('Edit OLT') : __('Tambah OLT Baru') }}</h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('Name') }}</label>
                    <input type="text" wire:model="name" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                @if ($this->isAdmin())
                    <div>
                        <label class="block text-sm font-medium mb-1">{{ __('Reseller') }}</label>
                        <select wire:model="resellerId" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <option value="">— {{ __('Direct (tanpa reseller)') }} —</option>
                            @foreach (\App\Models\Reseller::query()->orderBy('name')->get() as $reseller)
                                <option value="{{ $reseller->id }}">{{ $reseller->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('Router (NAS)') }}</label>
                    <select wire:model.live="nasId" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                        <option value="">— {{ __('Pilih router') }} —</option>
                        @foreach ($this->nasOptions() as $nas)
                            <option value="{{ $nas->id }}">{{ $nas->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-400 mt-1">{{ __('Test Connection dijalankan sebagai ping DARI router ini menuju IP OLT di bawah.') }}</p>
                    @error('nasId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('IP Address') }}</label>
                    <input type="text" wire:model.live="ipAddress" placeholder="10.1.x.x / 172.16-31.x.x / 192.168.x.x" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    <p class="text-xs text-gray-400 mt-1">{{ __('Wajib IP private — OLT tidak pernah diakses lewat IP publik.') }}</p>
                    @error('ipAddress') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('Manufacturer') }}</label>
                    <div class="flex gap-2">
                        <select wire:model.live="oltManufacturerId" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <option value="">— {{ __('Pilih manufacturer') }} —</option>
                            @foreach ($this->manufacturers() as $manufacturer)
                                <option value="{{ $manufacturer->id }}">{{ $manufacturer->name }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="openManufacturerModal" class="shrink-0 px-3 py-2 border border-gray-300 rounded-md text-sm hover:bg-gray-50" title="{{ __('Kelola manufacturer') }}">+</button>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('Model') }}</label>
                    <div class="flex gap-2">
                        <select wire:model="oltModelId" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" @if(!$oltManufacturerId) disabled @endif>
                            <option value="">— {{ __('Pilih model') }} —</option>
                            @foreach ($this->availableModels() as $model)
                                <option value="{{ $model->id }}">{{ $model->name }} ({{ $model->supported_pon_type->label() }})</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="openModelModal" @if(!$oltManufacturerId) disabled @endif class="shrink-0 px-3 py-2 border border-gray-300 rounded-md text-sm hover:bg-gray-50 disabled:opacity-40" title="{{ __('Kelola model') }}">+</button>
                    </div>
                    @error('oltModelId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            {{--
                SNMP — always-on, NOT one of the Access Protocol choices
                (addendum #2). SNMP is a monitoring protocol independent of
                CLI admin access; it used to live inside the per-protocol
                conditional block below and got wiped whenever Access
                Protocol was changed — that bug is why this section is now
                separate and unconditional. RO/RW community fields are
                plain text (not password-masked) so a technician can
                actually read/copy the value to go configure it manually on
                the OLT itself — these strings are generated by BOSS App,
                never typed in from something the user already has
                memorized.
            --}}
            <div class="p-3 bg-blue-50 rounded-md space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-medium">{{ __('SNMP (untuk monitoring LibreNMS)') }}</h3>
                    <span class="text-xs text-gray-500">{{ __('Community harus sudah/akan dikonfigurasi manual di OLT — form ini hanya menyimpan nilainya.') }}</span>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">{{ __('SNMP Version') }}</label>
                        <select wire:model="snmpVersion" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <option value="v1">v1</option>
                            <option value="v2c">v2c</option>
                            <option value="v3">v3</option>
                        </select>
                        @error('snmpVersion') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">{{ __('SNMP Port') }}</label>
                        <input type="number" wire:model="snmpPort" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                        @error('snmpPort') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">{{ __('Community (Read-Only)') }}</label>
                        <div class="flex gap-2">
                            <input type="text" wire:model="snmpRoCommunity" placeholder="{{ $editingOltDeviceId ? __('(biarkan kosong kalau tidak diubah)') : '' }}" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono">
                            <button type="button" wire:click="regenerateSnmpRoCommunity" class="shrink-0 px-3 py-2 border border-gray-300 rounded-md text-sm hover:bg-gray-50" title="{{ __('Generate ulang') }}">&#8635;</button>
                        </div>
                        @error('snmpRoCommunity') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">{{ __('Community (Read-Write, opsional)') }}</label>
                        <div class="flex gap-2">
                            <input type="text" wire:model="snmpRwCommunity" placeholder="{{ $editingOltDeviceId ? __('(biarkan kosong kalau tidak diubah)') : '' }}" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono">
                            <button type="button" wire:click="regenerateSnmpRwCommunity" class="shrink-0 px-3 py-2 border border-gray-300 rounded-md text-sm hover:bg-gray-50" title="{{ __('Generate ulang') }}">&#8635;</button>
                        </div>
                        @error('snmpRwCommunity') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('Access Protocol') }}</label>
                <select wire:model.live="accessProtocol" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm max-w-xs">
                    <option value="">— {{ __('Pilih protokol') }} —</option>
                    @foreach ($this->protocolOptions() as $protocol)
                        <option value="{{ $protocol->value }}">{{ $protocol->label() }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-400 mt-1">{{ __('Khusus akses CLI admin OLT — terpisah dari SNMP di atas.') }}</p>
                @error('accessProtocol') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Field kredensial dinamis per protokol (CLI admin saja, bukan SNMP) --}}
            @if ($accessProtocol === \App\Enums\OltAccessProtocol::Telnet->value)
                <div class="grid grid-cols-3 gap-4 p-3 bg-gray-50 rounded-md">
                    <div>
                        <label class="block text-sm font-medium mb-1">{{ __('Telnet Port') }}</label>
                        <input type="number" wire:model="telnetPort" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                        @error('telnetPort') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">{{ __('Username') }}</label>
                        <input type="text" wire:model="telnetUsername" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                        @error('telnetUsername') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div x-data="{ showPw: false }">
                        <label class="block text-sm font-medium mb-1">{{ __('Password') }}</label>
                        <div class="flex gap-2">
                            <input :type="showPw ? 'text' : 'password'" wire:model="telnetPassword" placeholder="{{ $editingOltDeviceId ? '•••••••• (biarkan kosong kalau tidak diubah)' : '' }}" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <button type="button" @click="showPw = !showPw" class="shrink-0 px-3 py-2 border border-gray-300 rounded-md text-sm hover:bg-gray-50" x-text="showPw ? '{{ __('Sembunyikan') }}' : '{{ __('Lihat') }}'"></button>
                        </div>
                    </div>
                </div>
            @endif

            @if ($accessProtocol === \App\Enums\OltAccessProtocol::Ssh->value)
                <div class="grid grid-cols-3 gap-4 p-3 bg-gray-50 rounded-md">
                    <div>
                        <label class="block text-sm font-medium mb-1">{{ __('SSH Port') }}</label>
                        <input type="number" wire:model="sshPort" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                        @error('sshPort') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">{{ __('Username') }}</label>
                        <input type="text" wire:model="sshUsername" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                        @error('sshUsername') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div x-data="{ showPw: false }">
                        <label class="block text-sm font-medium mb-1">{{ __('Password') }}</label>
                        <div class="flex gap-2">
                            <input :type="showPw ? 'text' : 'password'" wire:model="sshPassword" placeholder="{{ $editingOltDeviceId ? '•••••••• (biarkan kosong kalau tidak diubah)' : '' }}" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <button type="button" @click="showPw = !showPw" class="shrink-0 px-3 py-2 border border-gray-300 rounded-md text-sm hover:bg-gray-50" x-text="showPw ? '{{ __('Sembunyikan') }}' : '{{ __('Lihat') }}'"></button>
                        </div>
                    </div>
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('Notes') }}</label>
                <textarea wire:model="notes" rows="2" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></textarea>
            </div>

            {{-- Test Connection --}}
            <div class="p-3 border border-dashed border-gray-300 rounded-md">
                <div class="flex items-center justify-between">
                    <div class="text-sm">
                        <span class="font-medium">{{ __('Test Connection') }}</span>
                        <span class="text-gray-400 ml-1">{{ __('(ping IP OLT dari router terpilih)') }}</span>
                    </div>
                    <button
                        type="button" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection"
                        class="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="testConnection">{{ __('Test Connection') }}</span>
                        <span wire:loading wire:target="testConnection">{{ __('Menguji...') }}</span>
                    </button>
                </div>

                @if ($testConnectionResult)
                    <p class="text-sm mt-2 {{ $testConnectionResult['result'] === 'success' ? 'text-green-600' : 'text-red-600' }}">
                        {{ $testConnectionResult['message'] }}
                    </p>
                @endif

                @if ($testPassedForKey !== ($nasId . '|' . $ipAddress))
                    <p class="text-xs text-amber-600 mt-2">{{ __('Save terkunci — Test Connection harus berhasil dulu untuk kombinasi Router + IP yang sedang diisi.') }}</p>
                @endif
            </div>

            <div class="flex gap-2 pt-2">
                <button wire:click="save" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                    {{ __('Save') }}
                </button>
                <button wire:click="cancel" class="px-4 py-2 border border-gray-300 rounded-md text-sm hover:bg-gray-50">
                    {{ __('Batal') }}
                </button>
            </div>
        </div>
    @endif

    {{-- Modal: kelola manufacturer (addendum #3 — tambah + hapus dalam satu modal) --}}
    @if ($showManufacturerModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="$set('showManufacturerModal', false)">
            <div class="bg-white rounded-md p-4 w-full max-w-sm space-y-3">
                <h3 class="font-medium text-sm">{{ __('Kelola Manufacturer') }}</h3>

                @if ($manufacturerDeleteError)
                    <p class="text-xs text-red-600 bg-red-50 rounded-md px-2 py-1.5">{{ $manufacturerDeleteError }}</p>
                @endif

                @if ($this->manufacturers()->isNotEmpty())
                    <ul class="max-h-40 overflow-y-auto divide-y divide-gray-100 border border-gray-100 rounded-md">
                        @foreach ($this->manufacturers() as $manufacturer)
                            <li class="flex items-center justify-between px-2 py-1.5 text-sm">
                                <span>{{ $manufacturer->name }}</span>
                                <button
                                    type="button" wire:click="deleteManufacturer({{ $manufacturer->id }})"
                                    wire:confirm="{{ __('Hapus manufacturer :name?', ['name' => $manufacturer->name]) }}"
                                    class="text-red-600 hover:underline text-xs"
                                >{{ __('Hapus') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="pt-2 border-t border-gray-100 space-y-2">
                    <input type="text" wire:model="newManufacturerName" placeholder="{{ __('Nama manufacturer baru, mis. ZTE') }}" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('newManufacturerName') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex gap-2 justify-end">
                    <button wire:click="$set('showManufacturerModal', false)" class="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Tutup') }}</button>
                    <button wire:click="addManufacturer" class="px-3 py-1.5 text-sm bg-primary text-white rounded-md hover:opacity-90">{{ __('Tambah') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal: kelola model (addendum #3 — tambah + hapus dalam satu modal) --}}
    @if ($showModelModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="$set('showModelModal', false)">
            <div class="bg-white rounded-md p-4 w-full max-w-sm space-y-3">
                <h3 class="font-medium text-sm">{{ __('Kelola Model') }}</h3>

                @if ($modelDeleteError)
                    <p class="text-xs text-red-600 bg-red-50 rounded-md px-2 py-1.5">{{ $modelDeleteError }}</p>
                @endif

                @if ($this->availableModels()->isNotEmpty())
                    <ul class="max-h-40 overflow-y-auto divide-y divide-gray-100 border border-gray-100 rounded-md">
                        @foreach ($this->availableModels() as $model)
                            <li class="flex items-center justify-between px-2 py-1.5 text-sm">
                                <span>{{ $model->name }} ({{ $model->supported_pon_type->label() }})</span>
                                <button
                                    type="button" wire:click="deleteModel({{ $model->id }})"
                                    wire:confirm="{{ __('Hapus model :name?', ['name' => $model->name]) }}"
                                    class="text-red-600 hover:underline text-xs"
                                >{{ __('Hapus') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="pt-2 border-t border-gray-100 space-y-2">
                    <input type="text" wire:model="newModelName" placeholder="{{ __('Nama model baru, mis. C300') }}" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('newModelName') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <select wire:model="newModelPonType" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                        <option value="">— {{ __('PON Type') }} —</option>
                        @foreach ($this->ponTypeOptions() as $pon)
                            <option value="{{ $pon->value }}">{{ $pon->label() }}</option>
                        @endforeach
                    </select>
                    @error('newModelPonType') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex gap-2 justify-end">
                    <button wire:click="$set('showModelModal', false)" class="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Tutup') }}</button>
                    <button wire:click="addModel" class="px-3 py-1.5 text-sm bg-primary text-white rounded-md hover:opacity-90">{{ __('Tambah') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{--
        LIST — wire:ignore is REQUIRED here (addendum #3 bugfix), not
        decorative. Without it, Livewire's own DOM morph runs on every
        component re-render (e.g. every save()/delete(), which touches
        many other public properties too) and reconciles this whole
        subtree back to the pristine server-rendered markup below (empty
        <tbody>, no DataTables wrapper divs) — silently wiping out
        whatever rows/pagination DataTables had injected client-side,
        regardless of whether table.ajax.reload() also fires. Confirmed
        for real via a headless Playwright run before this fix: after a
        successful save, the table was empty for the SAME reason above,
        with zero console errors (the detached/replaced DOM node just
        stops reflecting anything visible) — and confirmed fixed the same
        way afterward. wire:ignore tells Livewire to never touch this
        subtree at all; the only thing that may still mutate it is
        DataTables' own jQuery calls, driven exclusively by
        table.ajax.reload() (see the olt-device-saved dispatch/listener
        below).
    --}}
    <div class="overflow-x-auto border border-gray-200 rounded-md" wire:ignore>
        <table id="olt-devices-table" class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-gray-500 uppercase tracking-wider">
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Router') }}</th>
                    <th>{{ __('IP') }}</th>
                    <th>{{ __('Manufacturer / Model') }}</th>
                    <th>{{ __('Protocol') }}</th>
                    <th>{{ __('Status Koneksi Terakhir') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

@push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/2.3.7/js/jquery.dataTables.min.js"></script>
    <script>
        (function () {
            window.table = $('#olt-devices-table').DataTable({
                processing: true,
                serverSide: true,
                autoWidth: false,
                ajax: '{{ route('web.olt-devices.internal.datatable') }}',
                lengthMenu: [10, 15, 25, 50],
                pageLength: 15,
                order: [],
                columns: [
                    { data: 'name', name: 'name' },
                    { data: 'nas_name', orderable: false },
                    { data: 'ip_address', name: 'ip_address' },
                    {
                        data: null, orderable: false,
                        render: (data, type, row) => [row.manufacturer_name, row.model_name].filter(Boolean).join(' ') || '—',
                    },
                    { data: 'protocol_label', orderable: false },
                    {
                        data: 'test_result_value', orderable: false,
                        render: function (value, type, row) {
                            if (type !== 'display') return value;
                            const color = value === 'success' ? 'bg-green-100 text-green-700' : (value === 'failed' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-500');
                            return `<span class="px-2 py-0.5 rounded-full text-xs ${color}">${row.test_result_label}</span> <span class="text-gray-400">${row.last_connection_test_at_human}</span>`;
                        },
                    },
                    {
                        data: 'id', orderable: false, className: 'text-right',
                        render: (id) => `
                            <button onclick="Livewire.dispatch('editOltDevice', { oltDeviceId: ${id} })" class="text-primary hover:underline mr-2">{{ __('Edit') }}</button>
                            <button onclick="if(confirm('{{ __('Hapus OLT ini?') }}')) Livewire.dispatch('deleteOltDevice', { oltDeviceId: ${id} })" class="text-red-600 hover:underline">{{ __('Hapus') }}</button>
                        `,
                    },
                ],
            });
        })();
    </script>
@endpush
