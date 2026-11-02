<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Tutup Work Order - {{ config('app.name') }}</title>
        {{-- v0.13.4.3.1 — gerbang ONU/WiFi belum siap: segarkan otomatis sampai
             siap (submit tetap ditolak server). Form hanya dirender saat siap. --}}
        @unless ($gate['ready'])
            <meta http-equiv="refresh" content="8">
        @endunless
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-100 min-h-screen py-6 px-4">
        {{-- Public-facing, TANPA login (signature = otorisasi). Blade + Alpine
             inline (Alpine.data('workOrderCloseForm'), dikenali FrontendBuildTest).
             POST ke url()->full() agar signature ikut. --}}
        <div class="max-w-2xl mx-auto bg-white rounded-md shadow p-6">
            <h1 class="text-xl font-semibold text-gray-800 mb-1">Tutup Work Order</h1>
            <p class="text-sm text-gray-500 mb-4">WO #{{ $workOrder->id }} — {{ $customer?->name ?? '-' }}</p>

            <div class="border border-gray-200 rounded-md p-4 mb-6 bg-gray-50">
                <dl class="text-sm text-gray-700 space-y-1">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Pelanggan</dt>
                        <dd class="font-medium">{{ $customer?->name ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Alamat</dt>
                        <dd class="font-medium text-right">{{ $customer?->address ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Nama WiFi (SSID)</dt>
                        <dd class="font-medium">{{ $ssid ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Teknisi</dt>
                        <dd class="font-medium">{{ $claimingTechnician->name }}</dd>
                    </div>
                </dl>
            </div>

            @unless ($gate['ready'])
                {{-- GERBANG KERAS belum terpenuhi — jelaskan + auto-refresh, form disembunyikan. --}}
                <div class="border border-amber-200 bg-amber-50 text-amber-800 rounded-md p-4 text-sm">
                    <p class="font-semibold mb-2">Belum bisa menutup Work Order.</p>
                    <ul class="space-y-1">
                        <li>{{ $gate['onu_online'] ? '✓' : '•' }} ONU online di sistem {{ $gate['onu_online'] ? '' : '(menunggu perangkat melapor…)' }}</li>
                        <li>{{ $gate['wifi_sent'] ? '✓' : '•' }} WiFi terkirim ke perangkat {{ $gate['wifi_sent'] ? '' : '(menunggu…)' }}</li>
                    </ul>
                    <p class="mt-3 text-xs">Halaman ini menyegar otomatis. Penutupan baru bisa dilakukan setelah keduanya tercapai.</p>
                </div>
            @else
                @isset($submitError)
                    <div class="border border-red-200 bg-red-50 text-red-800 rounded-md p-3 mb-4 text-sm">{{ $submitError }}</div>
                @endisset
                @if ($errors->any())
                    <div class="border border-red-200 bg-red-50 text-red-800 rounded-md p-3 mb-4 text-sm">
                        <ul class="list-disc list-inside">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ url()->full() }}" enctype="multipart/form-data"
                    x-data="workOrderCloseForm()" @submit="onSubmit($event)">
                    @csrf
                    <input type="hidden" name="signature" x-ref="signatureInput">

                    {{-- 6 foto wajib --}}
                    <h2 class="text-sm font-semibold text-gray-700 mb-2">Foto Dokumentasi (6 wajib)</h2>
                    <div class="space-y-4 mb-6">
                        @foreach ($photoTypes as $type)
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    {{ $type->label() }} <span class="text-red-600">*</span>
                                </label>
                                <input type="file" name="photos[{{ $type->value }}]" accept="image/*" capture="environment" required
                                    @change="compressInput($event)"
                                    class="w-full border border-gray-300 bg-white rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:border-primary">
                            </div>
                        @endforeach
                    </div>

                    {{-- Persyaratan + tanda tangan --}}
                    <h2 class="text-sm font-semibold text-gray-700 mb-2">Persyaratan &amp; Tanda Tangan Pelanggan</h2>
                    <p class="text-xs text-gray-500 mb-2">Versi: {{ $termsVersion }}</p>
                    <div class="border border-gray-300 rounded-md p-3 mb-4 bg-gray-50 max-h-56 overflow-y-auto text-xs text-gray-700 whitespace-pre-line">{{ $termsText }}</div>

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Tanda Tangan Pelanggan <span class="text-red-600">*</span>
                        </label>
                        <div class="border-2 border-dashed border-gray-400 rounded-md bg-white">
                            <canvas x-ref="pad" class="w-full" style="height: 220px; touch-action: none;"
                                @pointerdown="startDraw($event)" @pointermove="draw($event)"
                                @pointerup="endDraw()" @pointerleave="endDraw()"></canvas>
                        </div>
                        <div class="flex justify-between items-center mt-1">
                            <p class="text-xs text-gray-500">Pelanggan menandatangani setelah membaca persyaratan.</p>
                            <button type="button" @click="clearPad()"
                                class="text-xs text-red-600 hover:underline">Hapus / Ulang</button>
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Ketik Nama Pelanggan (konfirmasi) <span class="text-red-600">*</span>
                        </label>
                        <input type="text" name="customer_name" value="{{ $oldName ?? old('customer_name') }}" required autocomplete="off"
                            class="w-full border border-gray-300 bg-white rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:border-primary"
                            placeholder="Ketik ulang nama pelanggan persis">
                        <p class="text-xs text-gray-500 mt-1">Harus cocok dengan nama pelanggan pada Work Order ini.</p>
                    </div>

                    <button type="submit"
                        class="w-full bg-primary text-white rounded-md py-2 text-sm font-semibold hover:opacity-90">
                        Tutup Work Order
                    </button>
                </form>
            @endunless
        </div>

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('workOrderCloseForm', () => ({
                    drawing: false,
                    hasInk: false,
                    ctx: null,
                    init() {
                        const c = this.$refs.pad;
                        // Samakan resolusi internal kanvas dengan ukuran tampilannya.
                        const rect = c.getBoundingClientRect();
                        c.width = Math.max(300, Math.floor(rect.width));
                        c.height = 220;
                        this.ctx = c.getContext('2d');
                        this.ctx.lineWidth = 2;
                        this.ctx.lineCap = 'round';
                        this.ctx.strokeStyle = '#111827';
                    },
                    pos(e) {
                        const r = this.$refs.pad.getBoundingClientRect();
                        return { x: e.clientX - r.left, y: e.clientY - r.top };
                    },
                    startDraw(e) {
                        this.drawing = true;
                        const p = this.pos(e);
                        this.ctx.beginPath();
                        this.ctx.moveTo(p.x, p.y);
                    },
                    draw(e) {
                        if (!this.drawing) return;
                        const p = this.pos(e);
                        this.ctx.lineTo(p.x, p.y);
                        this.ctx.stroke();
                        this.hasInk = true;
                    },
                    endDraw() { this.drawing = false; },
                    clearPad() {
                        this.ctx.clearRect(0, 0, this.$refs.pad.width, this.$refs.pad.height);
                        this.hasInk = false;
                        this.$refs.signatureInput.value = '';
                    },
                    async compressInput(e) {
                        const input = e.target;
                        const file = input.files && input.files[0];
                        if (!file || !file.type.startsWith('image/')) return;
                        try {
                            const bitmap = await createImageBitmap(file);
                            const max = 1600;
                            let { width, height } = bitmap;
                            if (Math.max(width, height) > max) {
                                const s = max / Math.max(width, height);
                                width = Math.round(width * s);
                                height = Math.round(height * s);
                            }
                            const canvas = document.createElement('canvas');
                            canvas.width = width; canvas.height = height;
                            canvas.getContext('2d').drawImage(bitmap, 0, 0, width, height);
                            const blob = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', 0.8));
                            if (!blob) return;
                            const compressed = new File([blob], (file.name.replace(/\.[^.]+$/, '') || 'foto') + '.jpg', { type: 'image/jpeg' });
                            const dt = new DataTransfer();
                            dt.items.add(compressed);
                            input.files = dt.files;
                        } catch (_) { /* biarkan file asli; server tetap memvalidasi & membatasi ukuran */ }
                    },
                    onSubmit(e) {
                        if (!this.hasInk) {
                            e.preventDefault();
                            alert('Tanda tangan pelanggan wajib diisi.');
                            return;
                        }
                        this.$refs.signatureInput.value = this.$refs.pad.toDataURL('image/png');
                    },
                }));
            });
        </script>

        @livewireScripts
    </body>
</html>
