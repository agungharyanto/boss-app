{{--
    v0.13.5.5 (T4) — modal "Tandai Lunas": pilih metode (default Transfer
    Manual), rekening (bila manual), catatan referensi opsional. Dipakai oleh
    InvoiceIndex. Penulisan baris payments terjadi di InvoiceService::markPaid()
    lewat submitMarkPaid(); modal ini hanya mengumpulkan input.
--}}
@if ($markPaidInvoiceId !== null)
    <div class="fixed inset-0 z-[1100] flex items-center justify-center bg-black/40 p-4"
        wire:click.self="cancelMarkPaid">
        <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
            <div class="border-b border-gray-200 px-5 py-3">
                <h3 class="text-base font-semibold text-gray-800">{{ __('Tandai Lunas') }}</h3>
            </div>

            <div class="px-5 py-4 space-y-3 text-sm">
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('Metode Pembayaran') }}</label>
                    <select wire:model.live="markPaidMethod" class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                        @foreach (\App\Enums\PaymentMethod::cases() as $method)
                            <option value="{{ $method->value }}">{{ $method->label() }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($markPaidMethod === 'manual_transfer')
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('Rekening Tujuan') }}</label>
                        @if (empty($markPaidBankOptions))
                            <p class="text-xs text-amber-600">{{ __('Belum ada rekening aktif. Tambahkan di Profil Perusahaan → Pembayaran.') }}</p>
                        @else
                            <select wire:model="markPaidBankAccountId" class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                                <option value="">{{ __('— Tidak ditentukan —') }}</option>
                                @foreach ($markPaidBankOptions as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                @endif

                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('Catatan Referensi (opsional)') }}</label>
                    <input type="text" wire:model="markPaidReferenceNote" maxlength="255"
                        placeholder="{{ __('mis. no. mutasi / nama pengirim') }}"
                        class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                </div>

                @if ($markPaidError !== null)
                    <div class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-red-700">{{ $markPaidError }}</div>
                @endif
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-200 px-5 py-3">
                <button type="button" wire:click="cancelMarkPaid"
                    class="px-3 py-1.5 text-sm text-gray-600 hover:underline">{{ __('Batal') }}</button>
                <button type="button" wire:click="submitMarkPaid" wire:loading.attr="disabled"
                    class="px-4 py-1.5 text-sm bg-green-600 text-white rounded-md hover:opacity-90">
                    {{ __('Tandai Lunas') }}
                </button>
            </div>
        </div>
    </div>
@endif
