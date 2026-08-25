<div>
    <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ __('Riwayat Dialup') }}</h2>

    @if (empty($rows))
        <p class="text-sm text-gray-500 italic">
            {{ __('Belum ada riwayat sesi tercatat — customer belum pernah dial sejak accounting RADIUS aktif, atau belum dimigrasi ke RADIUS.') }}
        </p>
    @else
        <div class="overflow-x-auto border border-gray-200 rounded-md">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-3 py-1.5 text-left">{{ __('Acct ID') }}</th>
                        <th class="px-3 py-1.5 text-left">{{ __('Uptime') }}</th>
                        <th class="px-3 py-1.5 text-left">{{ __('Waktu Mulai') }}</th>
                        <th class="px-3 py-1.5 text-left">{{ __('Waktu Berakhir') }}</th>
                        <th class="px-3 py-1.5 text-left">{{ __('NAS') }}</th>
                        <th class="px-3 py-1.5 text-left">{{ __('Upload') }}</th>
                        <th class="px-3 py-1.5 text-left">{{ __('Download') }}</th>
                        <th class="px-3 py-1.5 text-left">{{ __('Terminate By') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-3 py-1.5 font-mono text-xs text-gray-500">{{ $row['acct_id'] }}</td>
                            <td class="px-3 py-1.5 text-gray-700">
                                {{ $this->formatDuration($row['session_seconds']) }}
                                @if ($row['is_active'])
                                    <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] bg-green-100 text-green-700">{{ __('Aktif') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-1.5 whitespace-nowrap text-gray-500">{{ $row['started_at']?->format('d M Y H:i:s') ?? '-' }}</td>
                            <td class="px-3 py-1.5 whitespace-nowrap text-gray-500">{{ $row['stopped_at']?->format('d M Y H:i:s') ?? '-' }}</td>
                            <td class="px-3 py-1.5 font-mono text-xs text-gray-500">{{ $row['nas_ip'] ?? '-' }}</td>
                            <td class="px-3 py-1.5 text-gray-700">{{ $this->formatBytes($row['upload_bytes']) }}</td>
                            <td class="px-3 py-1.5 text-gray-700">{{ $this->formatBytes($row['download_bytes']) }}</td>
                            <td class="px-3 py-1.5 text-gray-500">{{ $row['terminate_cause'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
