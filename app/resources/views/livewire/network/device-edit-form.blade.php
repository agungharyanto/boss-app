<div>
    @if ($showModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closeModal">
            <div class="bg-white rounded-md p-4 w-full max-w-sm space-y-3">
                <h3 class="font-medium text-sm">{{ __('Edit Device — :hostname', ['hostname' => $hostname]) }}</h3>

                @if ($errorMessage)
                    <p class="text-xs text-red-600 bg-red-50 rounded-md px-2 py-1.5">{{ $errorMessage }}</p>
                @endif

                @if ($successMessage)
                    <p class="text-xs text-green-700 bg-green-50 rounded-md px-2 py-1.5">{{ $successMessage }}</p>
                @endif

                @if ($deviceId)
                    <div class="space-y-2">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('Display Name (opsional)') }}</label>
                            <input type="text" wire:model="displayName" placeholder="{{ __('Default: hostname') }}" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                            @error('displayName') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('SNMP Version') }}</label>
                            <select wire:model="snmpVersion" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                                <option value="v1">v1</option>
                                <option value="v2c">v2c</option>
                            </select>
                            @error('snmpVersion') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('Community String') }}</label>
                            <input type="text" wire:model="community" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                            @error('community') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('Port') }}</label>
                            <input type="number" wire:model="port" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                            @error('port') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex gap-2 justify-end pt-2">
                        <button wire:click="closeModal" class="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Tutup') }}</button>
                        <button wire:click="save" wire:loading.attr="disabled" class="px-3 py-1.5 text-sm bg-primary text-white rounded-md hover:opacity-90">{{ __('Simpan') }}</button>
                    </div>
                @else
                    <div class="flex gap-2 justify-end pt-2">
                        <button wire:click="closeModal" class="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Tutup') }}</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
