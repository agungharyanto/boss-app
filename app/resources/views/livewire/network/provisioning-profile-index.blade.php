<div class="space-y-4">
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <h1 class="text-lg font-semibold text-gray-800">{{ __('Profil Provisioning') }}</h1>
            <p class="text-xs text-gray-500">{{ __('Template WAN + SSID untuk Zero-Touch Provisioning. Disimpan di BOSS App — tidak ada push ke device dari halaman ini.') }}</p>
        </div>
        @if ($canManage)
            <button wire:click="openCreate" class="px-3 py-1.5 text-sm bg-primary text-white rounded-md hover:opacity-90">{{ __('+ Profil Provisioning Baru') }}</button>
        @endif
    </div>

    {{-- ===================== FORM ===================== --}}
    @if ($showForm)
        <div class="border border-gray-200 rounded-md p-4 space-y-5 bg-gray-50/50">
            <h2 class="text-sm font-semibold text-gray-800">{{ $editingId ? __('Ubah Profil') : __('Profil Baru') }}</h2>

            {{-- Dasar --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs text-gray-600 mb-1">{{ __('Nama') }}</label>
                    <input type="text" wire:model="name" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">{{ __('Slug') }}</label>
                    <input type="text" wire:model="slug" placeholder="otomatis dari nama" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('slug') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="enabled"> {{ __('Enabled') }}</label>
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs text-gray-600 mb-1">{{ __('Deskripsi') }}</label>
                    <textarea wire:model="description" rows="2" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm"></textarea>
                </div>
            </div>

            {{-- WAN Internet --}}
            <div class="border border-gray-200 rounded-md p-3 space-y-3 bg-white">
                <h3 class="text-sm font-medium text-gray-800">{{ __('WAN Internet') }} <span class="text-red-600">*</span></h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">{{ __('Mode') }}</label>
                        <select wire:model.live="wanMode" class="w-full border-gray-300 rounded-md px-2 py-2 text-sm">
                            <option value="pppoe">PPPoE</option>
                            <option value="dhcp">DHCP</option>
                            <option value="static">Static</option>
                        </select>
                        @if (in_array($wanMode, ['dhcp', 'static'], true))
                            <span class="mt-1 inline-block text-[10px] uppercase tracking-wide bg-amber-100 text-amber-700 rounded px-1">{{ __('belum teruji di hardware') }}</span>
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">{{ __('VLAN') }}</label>
                        <input type="number" wire:model="wanVlan" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm">
                        @error('wanVlan') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">{{ __('Service Type') }}</label>
                        <input type="text" wire:model="wanServiceType" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">{{ __('Peran WiFi') }}</label>
                        <select wire:model="wanWifiRole" class="w-full border-gray-300 rounded-md px-2 py-2 text-sm">
                            <option value="primary_wifi">primary_wifi</option>
                            <option value="guest_wifi">guest_wifi</option>
                            <option value="none">none</option>
                        </select>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="wanNatEnabled"> {{ __('NAT aktif') }}</label>

                @if ($wanMode === 'pppoe')
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">{{ __('Strategi Username') }}</label>
                            <select wire:model.live="pppoeUsernameStrategy" class="w-full border-gray-300 rounded-md px-2 py-2 text-sm">
                                <option value="derive_from_cid">derive_from_cid</option>
                                <option value="static">static</option>
                            </select>
                        </div>
                        @if ($pppoeUsernameStrategy === 'static')
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">{{ __('Username (static)') }}</label>
                                <input type="text" wire:model="pppoeUsernameStatic" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm">
                                @error('pppoeUsernameStatic') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">{{ __('Strategi Password') }}</label>
                            <select wire:model.live="pppoePasswordStrategy" class="w-full border-gray-300 rounded-md px-2 py-2 text-sm">
                                <option value="constant">constant</option>
                                <option value="static">static</option>
                            </select>
                        </div>
                        @if ($pppoePasswordStrategy === 'static')
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">{{ __('Password (static)') }}</label>
                                <input type="password" wire:model="pppoePasswordStatic" placeholder="{{ $editingId ? '••••• (kosong = tak diubah)' : '' }}" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm">
                            </div>
                        @endif
                    </div>
                @endif

                @if ($wanMode === 'static')
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div><label class="block text-xs text-gray-600 mb-1">IP</label><input type="text" wire:model="staticIp" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm">@error('staticIp') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror</div>
                        <div><label class="block text-xs text-gray-600 mb-1">Gateway</label><input type="text" wire:model="staticGateway" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm">@error('staticGateway') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror</div>
                        <div><label class="block text-xs text-gray-600 mb-1">Netmask</label><input type="text" wire:model="staticNetmask" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm">@error('staticNetmask') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror</div>
                        <div><label class="block text-xs text-gray-600 mb-1">DNS</label><input type="text" wire:model="staticDns" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm"></div>
                    </div>
                @endif
            </div>

            {{-- WAN Bridge --}}
            <div class="border border-gray-200 rounded-md p-3 space-y-3 bg-white">
                <label class="flex items-center gap-2 text-sm font-medium text-gray-800"><input type="checkbox" wire:model.live="hasBridge"> {{ __('WAN Bridge (opsional)') }}</label>
                @if ($hasBridge)
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div><label class="block text-xs text-gray-600 mb-1">{{ __('VLAN') }}</label><input type="number" wire:model="bridgeVlan" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm">@error('bridgeVlan') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror</div>
                        <div><label class="block text-xs text-gray-600 mb-1">{{ __('Service Type') }}</label><input type="text" wire:model="bridgeServiceType" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm"></div>
                        <div><label class="block text-xs text-gray-600 mb-1">{{ __('Peran WiFi') }}</label>
                            <select wire:model="bridgeWifiRole" class="w-full border-gray-300 rounded-md px-2 py-2 text-sm">
                                <option value="primary_wifi">primary_wifi</option><option value="guest_wifi">guest_wifi</option><option value="none">none</option>
                            </select>
                        </div>
                    </div>
                @endif
            </div>

            {{-- SSID --}}
            <div class="border border-gray-200 rounded-md p-3 space-y-2 bg-white">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-800">{{ __('SSID') }}</h3>
                    <button type="button" wire:click="addSsid" class="text-xs text-primary hover:underline">{{ __('+ Tambah SSID') }}</button>
                </div>
                <p class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1">{{ __('Catatan: WLAN yang tidak ada di daftar SSID ini DAN tidak ter-bind ke WAN apa pun akan DIMATIKAN saat provisioning (aturan disableUnboundWlan).') }}</p>
                @forelse ($ssids as $i => $ssid)
                    <div class="grid grid-cols-1 md:grid-cols-6 gap-2 items-start border-b border-gray-100 pb-2" wire:key="ssid-{{ $i }}">
                        <select wire:model="ssids.{{ $i }}.wifi_role" class="border-gray-300 rounded-md px-2 py-2 text-sm">
                            <option value="primary_wifi">primary_wifi</option><option value="guest_wifi">guest_wifi</option><option value="none">none</option>
                        </select>
                        <select wire:model="ssids.{{ $i }}.band" class="border-gray-300 rounded-md px-2 py-2 text-sm">
                            <option value="2.4g">2.4g</option><option value="5g">5g</option>
                        </select>
                        <div class="md:col-span-2">
                            <input type="text" wire:model.live.debounce.500ms="ssids.{{ $i }}.ssid_name" placeholder="{cid} / {name} boleh" class="w-full border-gray-300 rounded-md px-3 py-2 text-sm">
                            @if (str_contains($ssid['ssid_name'] ?? '', '{'))
                                <p class="text-[10px] text-gray-500 mt-0.5">→ {{ __('contoh') }}: {{ $this->ssidSampleResolve($ssid['ssid_name']) }}</p>
                            @endif
                            @error("ssids.{$i}.ssid_name") <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <select wire:model.live="ssids.{{ $i }}.security_mode" class="border-gray-300 rounded-md px-2 py-2 text-sm">
                            <option value="open">open</option><option value="wpa2">wpa2</option>
                        </select>
                        <div class="flex gap-1 items-center">
                            @if (($ssid['security_mode'] ?? '') === 'wpa2')
                                <input type="password" wire:model="ssids.{{ $i }}.password" placeholder="{{ $editingId ? 'kosong=tetap' : 'password' }}" class="w-full border-gray-300 rounded-md px-2 py-2 text-sm">
                            @else
                                <span class="text-[10px] text-gray-400">{{ __('tanpa password') }}</span>
                            @endif
                            <button type="button" wire:click="removeSsid({{ $i }})" class="text-red-600 hover:underline text-xs">✕</button>
                        </div>
                        @error("ssids.{$i}.band") <p class="text-xs text-red-600 md:col-span-6">{{ $message }}</p> @enderror
                        @error("ssids.{$i}.password") <p class="text-xs text-red-600 md:col-span-6">{{ $message }}</p> @enderror
                    </div>
                @empty
                    <p class="text-xs text-gray-400">{{ __('Belum ada SSID. Klik "+ Tambah SSID".') }}</p>
                @endforelse
            </div>

            {{-- Tautan Paket --}}
            <div class="border border-gray-200 rounded-md p-3 space-y-2 bg-white">
                <h3 class="text-sm font-medium text-gray-800">{{ __('Tautan Paket') }}</h3>
                @forelse ($packageOptions as $pkg)
                    <div class="flex items-center gap-4 text-sm">
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="linkedPackageIds" value="{{ $pkg->id }}"> {{ $pkg->name }}</label>
                        @if (in_array((string) $pkg->id, array_map('strval', $linkedPackageIds), true) || in_array($pkg->id, $linkedPackageIds, true))
                            <label class="flex items-center gap-1 text-xs text-gray-500"><input type="checkbox" wire:model="defaultPackageIds" value="{{ $pkg->id }}"> {{ __('default untuk paket ini') }}</label>
                        @endif
                    </div>
                @empty
                    <p class="text-xs text-gray-400">{{ __('Belum ada Paket PPP.') }}</p>
                @endforelse
            </div>

            <div class="flex gap-2">
                <button wire:click="save" class="px-4 py-2 text-sm bg-primary text-white rounded-md hover:opacity-90">{{ __('Simpan') }}</button>
                <button wire:click="cancel" class="px-4 py-2 text-sm text-gray-600 hover:underline">{{ __('Batal') }}</button>
            </div>
        </div>
    @endif

    {{-- ===================== DAFTAR ===================== --}}
    <input type="text" wire:model.live.debounce.400ms="search" placeholder="{{ __('Cari nama/slug…') }}" class="border-gray-300 rounded-md px-3 py-2 text-sm w-64">

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full text-sm divide-y divide-gray-200">
            <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                <tr>
                    <th class="px-3 py-2 text-left">{{ __('Nama') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Mode WAN') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Bridge') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('SSID') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Paket') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('CPE override') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Enabled') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($profiles as $p)
                    <tr wire:key="profile-{{ $p->id }}">
                        <td class="px-3 py-2">
                            <div class="font-medium text-gray-800">{{ $p->name }}</div>
                            <div class="text-xs text-gray-400">{{ $p->slug }}</div>
                        </td>
                        <td class="px-3 py-2">{{ $p->wanInternet?->wan_mode?->value ?? '-' }}</td>
                        <td class="px-3 py-2">{{ $p->wanBridge ? __('ya') : '-' }}</td>
                        <td class="px-3 py-2">{{ $p->ssids_count }}</td>
                        <td class="px-3 py-2">{{ $p->ppp_packages_count }}</td>
                        <td class="px-3 py-2">{{ $p->cpe_devices_count }}</td>
                        <td class="px-3 py-2">
                            <span class="px-1.5 py-0.5 rounded text-xs {{ $p->enabled ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">{{ $p->enabled ? 'on' : 'off' }}</span>
                        </td>
                        <td class="px-3 py-2 text-right">
                            @if ($canManage)
                                <button wire:click="edit({{ $p->id }})" class="text-primary hover:underline text-xs">{{ __('Edit') }}</button>
                                <button wire:click="delete({{ $p->id }})"
                                    wire:confirm="{{ __('Hapus profil ini? Tertaut :pkg paket & :cpe CPE override (override akan dikosongkan).', ['pkg' => $p->ppp_packages_count, 'cpe' => $p->cpe_devices_count]) }}"
                                    class="ml-2 text-red-600 hover:underline text-xs">{{ __('Hapus') }}</button>
                            @else
                                <span class="text-gray-300 text-xs">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-4 text-center text-gray-500">{{ __('Belum ada profil provisioning.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $profiles->links() }}</div>
</div>
