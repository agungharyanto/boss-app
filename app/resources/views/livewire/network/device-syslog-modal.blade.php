<div>
    @if ($showModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closeModal">
            <div class="bg-white rounded-md p-5 w-full max-w-4xl space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-medium text-sm text-gray-700">{{ __('Log — :name', ['name' => $deviceName]) }}</h3>
                    <button type="button" wire:click="closeModal" class="text-gray-400 hover:text-gray-600 text-sm">&#10005;</button>
                </div>

                <div class="flex items-center gap-3">
                    <label class="text-xs text-gray-500">
                        {{ __('Level') }}
                        <select wire:change="changeLevel($event.target.value)" class="ml-1 border-gray-300 rounded-md text-xs">
                            <option value="">{{ __('Semua') }}</option>
                            @foreach (\App\Livewire\Network\DeviceSyslogModal::LEVEL_LABELS as $value => $label)
                                <option value="{{ $value }}" @selected($level === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="text-xs text-gray-500">
                        {{ __('Tampilkan') }}
                        <select wire:change="changeLimit($event.target.value)" class="ml-1 border-gray-300 rounded-md text-xs">
                            @foreach ([25, 50, 100, 200] as $option)
                                <option value="{{ $option }}" @selected($limit === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                @if ($state === 'empty')
                    <p class="text-sm text-gray-500 italic">
                        {{ __('Belum ada data log untuk device ini — NAS/router belum dikonfigurasi kirim syslog, atau belum ada aktivitas yang cocok topik yang diaktifkan.') }}
                    </p>
                @elseif ($state === 'unavailable')
                    <p class="text-sm text-amber-600 italic">{{ __('Data log tidak tersedia — coba lagi beberapa saat.') }}</p>
                @else
                    <div class="overflow-x-auto max-h-96 overflow-y-auto border border-gray-200 rounded-md">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50 text-gray-500 uppercase sticky top-0">
                                <tr>
                                    <th class="px-3 py-2 text-left">{{ __('Waktu') }}</th>
                                    <th class="px-3 py-2 text-left">{{ __('Level') }}</th>
                                    <th class="px-3 py-2 text-left">{{ __('Program') }}</th>
                                    <th class="px-3 py-2 text-left">{{ __('Pesan') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($rows as $row)
                                    @php($badge = $this->levelBadge($row['level']))
                                    <tr>
                                        <td class="px-3 py-2 whitespace-nowrap text-gray-500">{{ $row['timestamp'] ?? '-' }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">
                                            <span class="px-2 py-0.5 rounded-full text-[11px] {{ $badge['class'] }}">{{ $badge['label'] }}</span>
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $row['program'] ?? '-' }}</td>
                                        <td class="px-3 py-2 text-gray-700">{{ $row['msg'] ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
