<div class="p-6 max-w-5xl mx-auto">
    <h1 class="text-2xl font-semibold mb-2" style="color: var(--color-text)">WhatsApp Gateway</h1>
    <p class="text-sm text-gray-500 mb-6">
        @if ($isAdmin)
            Overview seluruh sesi (reseller + direct), template default ISP, dan antrian gabungan.
        @else
            Konfigurasi, template pesan, dan antrian WhatsApp untuk reseller Anda.
        @endif
    </p>

    @if (session('status'))
        <div class="mb-4 text-sm text-green-600">{{ session('status') }}</div>
    @endif

    <div class="flex gap-2 border-b border-gray-200 mb-6">
        @if ($isAdmin)
            <button wire:click="setTab('overview')" class="px-3 py-2 text-sm font-medium {{ $tab === 'overview' ? 'border-b-2 border-primary text-primary' : 'text-gray-500' }}">Overview Sesi</button>
        @else
            <button wire:click="setTab('konfigurasi')" class="px-3 py-2 text-sm font-medium {{ $tab === 'konfigurasi' ? 'border-b-2 border-primary text-primary' : 'text-gray-500' }}">Konfigurasi</button>
        @endif
        <button wire:click="setTab('template')" class="px-3 py-2 text-sm font-medium {{ $tab === 'template' ? 'border-b-2 border-primary text-primary' : 'text-gray-500' }}">Template Pesan</button>
        <button wire:click="setTab('antrian')" class="px-3 py-2 text-sm font-medium {{ $tab === 'antrian' ? 'border-b-2 border-primary text-primary' : 'text-gray-500' }}">Antrian</button>
        @if ($isAdmin)
            <button wire:click="setTab('settings')" class="px-3 py-2 text-sm font-medium {{ $tab === 'settings' ? 'border-b-2 border-primary text-primary' : 'text-gray-500' }}">Rate Limit</button>
        @endif
    </div>

    {{-- KONFIGURASI (reseller) --}}
    @if (! $isAdmin && $tab === 'konfigurasi')
        <div
            class="p-4 rounded-md border border-gray-200 space-y-4"
            @if ($mySession !== null && $mySession->status->value !== 'connected') wire:poll.3s @endif
        >
            @if ($mySession === null)
                <p class="text-sm text-gray-500">Anda belum punya sesi WhatsApp — hubungkan nomor untuk mulai mengirim notifikasi.</p>
                <button wire:click="createSession" wire:loading.attr="disabled" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                    Hubungkan Nomor WhatsApp Reseller
                </button>
            @else
                <p class="text-sm">
                    Status:
                    <span class="font-medium {{ $mySession->status->value === 'connected' ? 'text-green-600' : 'text-amber-600' }}">
                        {{ $mySession->status->label() }}
                    </span>
                    @if ($mySession->phone_number)
                        — {{ $mySession->phone_number }}
                    @endif
                </p>

                @if ($mySession->status->value !== 'connected')
                    @include('livewire.whatsapp.partials.pairing-connect-panel', ['session' => $mySession, 'labelPrefix' => 'Anda'])
                @endif
            @endif
        </div>
    @endif

    {{-- OVERVIEW (admin) --}}
    @if ($isAdmin && $tab === 'overview')
        <div
            class="p-4 rounded-md border border-gray-200 space-y-4 mb-6"
            @if ($directSession !== null && $directSession->status->value !== 'connected') wire:poll.3s @endif
        >
            <h3 class="font-medium text-gray-800">Sesi Direct (ISP A)</h3>

            @if ($directSession === null)
                <p class="text-sm text-gray-500">Belum ada sesi WhatsApp untuk pelanggan langsung (tanpa reseller).</p>
                <button wire:click="createSession" wire:loading.attr="disabled" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                    Hubungkan Nomor ISP A
                </button>
            @else
                <p class="text-sm">
                    Status:
                    <span class="font-medium {{ $directSession->status->value === 'connected' ? 'text-green-600' : 'text-amber-600' }}">
                        {{ $directSession->status->label() }}
                    </span>
                    @if ($directSession->phone_number)
                        — {{ $directSession->phone_number }}
                    @endif
                </p>

                @if ($directSession->status->value !== 'connected')
                    @include('livewire.whatsapp.partials.pairing-connect-panel', ['session' => $directSession, 'labelPrefix' => 'ISP A'])
                @endif
            @endif
        </div>

        <h3 class="font-medium text-gray-800 mb-2">Sesi Reseller</h3>
        <p class="text-xs text-gray-400 mb-2">
            Read-only — pembuatan/refresh QR sesi reseller adalah hak reseller yang bersangkutan sendiri.
        </p>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 border-b border-gray-200">
                        <th class="py-2 pr-4">Reseller</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2 pr-4">Nomor</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($resellerSessions as $session)
                        <tr class="border-b border-gray-100">
                            <td class="py-2 pr-4">{{ $session->reseller?->name }}</td>
                            <td class="py-2 pr-4 {{ $session->status->value === 'connected' ? 'text-green-600' : 'text-amber-600' }}">
                                {{ $session->status->label() }}
                            </td>
                            <td class="py-2 pr-4">{{ $session->phone_number ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-4 text-gray-400">Belum ada reseller yang punya sesi WhatsApp.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- TEMPLATE --}}
    @if ($tab === 'template')
        <div class="space-y-4">
            @foreach ($templates as $row)
                <div class="p-4 rounded-md border border-gray-200">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-medium text-gray-800">{{ $row->event_type->label() }}</h3>
                        <div class="flex gap-3 text-sm">
                            <button wire:click="editTemplate('{{ $row->event_type->value }}')" class="text-primary hover:underline">Edit</button>
                            @if (! $isAdmin && $row->is_override)
                                <button wire:click="resetTemplateToDefault('{{ $row->event_type->value }}')" class="text-gray-500 hover:underline">Reset ke Default ISP</button>
                            @endif
                        </div>
                    </div>
                    @if (! $isAdmin)
                        <p class="text-xs text-gray-400 mb-1">{{ $row->is_override ? 'Override reseller aktif' : 'Memakai template default ISP' }}</p>
                    @endif
                    <p class="text-sm text-gray-600 whitespace-pre-line">{{ $row->effective_content }}</p>
                </div>
            @endforeach
        </div>

        @if ($showTemplateForm)
            <div class="fixed inset-0 bg-black/30 flex items-center justify-center z-50" wire:click.self="$set('showTemplateForm', false)">
                <div class="bg-white rounded-md p-6 w-full max-w-lg space-y-4">
                    <h3 class="font-medium text-gray-800">Edit Template</h3>
                    <textarea wire:model="editingContent" rows="6" class="block w-full rounded-md border-gray-300 shadow-sm text-sm"></textarea>
                    @error('editingContent') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    <p class="text-xs text-gray-400">
                        Variabel tersedia: {customer_name}, {customer_id}, {invoice_number}, {due_date}, {total_amount}, {package_name}, {company_name}, {payment_link}
                    </p>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="editingIsActive" class="rounded border-gray-300">
                        Aktif
                    </label>
                    <div class="flex gap-2">
                        <button wire:click="saveTemplate" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">Simpan</button>
                        <button wire:click="$set('showTemplateForm', false)" class="px-4 py-2 text-gray-500 text-sm">Batal</button>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- ANTRIAN --}}
    @if ($tab === 'antrian')
        <div class="mb-4 flex gap-3 items-center">
            <select wire:model.live="statusFilter" class="rounded-md border-gray-300 text-sm">
                <option value="">Semua Status</option>
                <option value="queued">Antri</option>
                <option value="sent">Terkirim</option>
                <option value="failed">Gagal</option>
                <option value="delivered">Diterima</option>
            </select>
            @if ($isAdmin)
                <select wire:model.live="resellerFilter" class="rounded-md border-gray-300 text-sm">
                    <option value="">Semua Reseller</option>
                    @foreach ($resellers as $reseller)
                        <option value="{{ $reseller->id }}">{{ $reseller->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 border-b border-gray-200">
                        <th class="py-2 pr-4">Waktu</th>
                        <th class="py-2 pr-4">Nomor</th>
                        <th class="py-2 pr-4">Event</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2 pr-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr class="border-b border-gray-100">
                            <td class="py-2 pr-4">{{ $log->created_at->format('d/m/Y H:i') }}</td>
                            <td class="py-2 pr-4">{{ $log->phone_number }}</td>
                            <td class="py-2 pr-4">{{ $log->event_type->label() }}</td>
                            <td class="py-2 pr-4 {{ $log->status->value === 'failed' ? 'text-red-600' : ($log->status->value === 'sent' ? 'text-green-600' : 'text-gray-500') }}">
                                {{ $log->status->label() }}
                                @if ($log->failed_reason)
                                    <span class="block text-xs text-gray-400">{{ Str::limit($log->failed_reason, 60) }}</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4">
                                @if ($log->status->value === 'failed')
                                    <button wire:click="retryMessage({{ $log->id }})" class="text-primary hover:underline">Retry</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-gray-400">Belum ada pesan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $logs->links() }}</div>
    @endif

    {{-- SETTINGS (admin only) --}}
    @if ($isAdmin && $tab === 'settings')
        <form wire:submit="saveSettings" class="space-y-4 p-4 rounded-md border border-gray-200 max-w-md">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Delay antar pesan (detik)</label>
                <div class="flex gap-2">
                    <input type="number" wire:model="rateLimitDelayMin" class="w-full rounded-md border-gray-300 text-sm" placeholder="min">
                    <input type="number" wire:model="rateLimitDelayMax" class="w-full rounded-md border-gray-300 text-sm" placeholder="max">
                </div>
                @error('rateLimitDelayMax') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Batch size (jumlah pesan per batch)</label>
                <input type="number" wire:model="rateLimitBatchSize" class="w-full rounded-md border-gray-300 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Jeda antar batch (menit)</label>
                <div class="flex gap-2">
                    <input type="number" wire:model="rateLimitBatchPauseMin" class="w-full rounded-md border-gray-300 text-sm" placeholder="min">
                    <input type="number" wire:model="rateLimitBatchPauseMax" class="w-full rounded-md border-gray-300 text-sm" placeholder="max">
                </div>
                @error('rateLimitBatchPauseMax') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Jadwal batch reminder harian (pisahkan koma, format HH:MM)</label>
                <input type="text" wire:model="dailyScheduleTimesRaw" class="w-full rounded-md border-gray-300 text-sm" placeholder="08:00,20:00">
            </div>
            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">Simpan</button>
        </form>
    @endif
</div>
