{{-- v0.13.6 — render satu sel pesan masuk: teks biasa ATAU media (foto/
     dokumen/audio/video/stiker). File disajikan lewat route ber-auth
     web.whatsapp-incoming.media (disk privat, bukan URL publik). Dipakai di
     tab "Pesan Masuk" (WhatsappGatewayIndex) dan Detail Pelanggan. --}}
@php
    $labels = ['image' => 'Foto', 'document' => 'Dokumen', 'audio' => 'Audio', 'video' => 'Video', 'sticker' => 'Stiker'];
@endphp

@if (! $msg->hasMedia())
    <span title="{{ $msg->text }}">{{ \Illuminate\Support\Str::limit($msg->text, 80) }}</span>
@else
    <div class="space-y-1">
        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">
            {{ $labels[$msg->media_type] ?? ucfirst($msg->media_type) }}
        </span>

        @if ($msg->hasServableMedia())
            @php $url = route('web.whatsapp-incoming.media', $msg); @endphp
            @if ($msg->media_type === 'image' || $msg->media_type === 'sticker')
                <a href="{{ $url }}" target="_blank" rel="noopener">
                    <img src="{{ $url }}" loading="lazy" class="h-20 w-auto rounded border border-gray-200 object-cover" alt="media masuk">
                </a>
            @elseif ($msg->media_type === 'audio')
                <audio controls preload="none" class="h-8 max-w-xs"><source src="{{ $url }}"></audio>
            @elseif ($msg->media_type === 'video')
                <a href="{{ $url }}" target="_blank" rel="noopener" class="text-primary hover:underline text-xs">▶ Buka / unduh video</a>
            @else
                <a href="{{ $url }}" target="_blank" rel="noopener" class="text-primary hover:underline text-xs">⤓ Unduh {{ $msg->media_file_name ?: 'dokumen' }}</a>
            @endif
        @else
            <span class="text-xs text-gray-400 italic" title="{{ $msg->media_rejected_reason }}">(media tidak tersedia)</span>
        @endif

        @if ($msg->media_caption)
            <p class="text-xs text-gray-600" title="{{ $msg->media_caption }}">{{ \Illuminate\Support\Str::limit($msg->media_caption, 80) }}</p>
        @endif
    </div>
@endif
