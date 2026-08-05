<div class="p-6 max-w-4xl mx-auto">
    <h1 class="text-2xl font-semibold mb-2" style="color: var(--color-text)">Script Generator</h1>
    <p class="text-sm text-gray-500 mb-6">
        Generate script Mikrotik siap-paste untuk koneksi VPN ke BOSS App dan setup RADIUS.
    </p>

    <div class="flex gap-2 border-b border-gray-200 mb-6">
        <button wire:click="setTab('vpn')" class="px-3 py-2 text-sm font-medium {{ $tab === 'vpn' ? 'border-b-2 border-primary text-primary' : 'text-gray-500' }}">VPN Script</button>
        <button wire:click="setTab('radius')" class="px-3 py-2 text-sm font-medium {{ $tab === 'radius' ? 'border-b-2 border-primary text-primary' : 'text-gray-500' }}">RADIUS Script</button>
    </div>

    <div class="mb-6">
        <label class="block text-sm font-medium mb-1">NAS</label>
        <select wire:model="selectedNasId" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
            <option value="">— Pilih NAS —</option>
            @foreach ($nasList as $nas)
                <option value="{{ $nas->id }}">{{ $nas->name }}{{ $nas->reseller ? ' ('.$nas->reseller->name.')' : ' (direct)' }}</option>
            @endforeach
        </select>
        @error('selectedNasId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    {{-- TAB: VPN SCRIPT --}}
    @if ($tab === 'vpn')
        <div class="p-4 rounded-md border border-gray-200 space-y-4 mb-6">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">RouterOS Version</label>
                    <select wire:model="routerOsVersion" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                        <option value="7">RouterOS 7.x</option>
                        <option value="6">RouterOS 6.x</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Protokol VPN</label>
                    <select wire:model="vpnProtocol" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                        <option value="openvpn">OpenVPN</option>
                        <option value="wireguard" @if ($routerOsVersion === '6') disabled @endif>
                            WireGuard @if ($routerOsVersion === '6') (butuh RouterOS 7.x) @endif
                        </option>
                        {{-- v0.6.4: tetap ditampilkan (bukan disembunyikan) dengan
                             label eksplisit — admin masih boleh generate script
                             L2TP kalau mau, tapi harus tahu batasannya dulu. Lihat
                             catatan amber di bawah untuk detail lengkap. --}}
                        <option value="l2tp_ipsec">L2TP/IPsec (known limitation)</option>
                    </select>
                </div>
            </div>

            <button wire:click="generateVpn" wire:loading.attr="disabled" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                Generate VPN Script
            </button>

            @if ($vpnProtocol === 'wireguard')
                <p class="text-xs text-amber-600">
                    Catatan: private key WireGuard hanya ditampilkan SEKALI saat pertama kali di-generate untuk NAS
                    ini — BOSS App tidak menyimpannya. Kalau NAS ini sudah punya akun WireGuard aktif, generate
                    ulang akan meminta konfirmasi cabut akun lama dulu (lihat tombol di bawah kalau ini terjadi).
                </p>
            @endif

            @if ($vpnProtocol === 'l2tp_ipsec')
                <p class="text-xs text-amber-600">
                    <strong>Known limitation (v0.6.3, belum teratasi):</strong> IPsec berhasil terhubung (SA
                    established, DPD sukses), tapi trafik L2TP-nya sendiri tidak pernah benar-benar ter-enkripsi
                    ESP — root cause ada di perilaku RouterOS <code>l2tp-client use-ipsec=yes</code> yang di luar
                    kendali sisi server, bukan bug di script ini. L2TP juga TIDAK ikut pool multi-node v0.6.4 —
                    cuma 1 node, tanpa auto-switch failover. Disarankan pakai OpenVPN atau WireGuard (keduanya
                    fully functional dan sudah diverifikasi nyata) sampai ini ditemukan solusinya.
                </p>
            @endif
        </div>
    @endif

    {{-- TAB: RADIUS SCRIPT --}}
    @if ($tab === 'radius')
        <div class="p-4 rounded-md border border-gray-200 space-y-4 mb-6">
            <p class="text-sm text-gray-500">
                Script ini mengaktifkan RADIUS di NAS (PPP AAA + Hotspot profile) dan membuat user API BOSS App
                baru dengan permission terbatas (dipakai untuk fitur "Tes Koneksi" NAS).
            </p>
            <p class="text-xs text-gray-500">
                Memakai port auth/acct unik milik NAS ini sendiri (dynamic virtual server FreeRADIUS, v0.6.5) —
                bukan lagi port default 1812/1813 bersama.
            </p>

            <button wire:click="generateRadius" wire:loading.attr="disabled" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                Generate RADIUS Script
            </button>
        </div>
    @endif

    @if ($errorMessage)
        <div class="mb-4 p-3 rounded-md bg-red-50 border border-red-200 text-sm text-red-700 space-y-2">
            <p>{{ $errorMessage }}</p>

            @if ($canRevokeAndRegenerate)
                {{-- Danger-action color matches the app's established pattern for
                     destructive buttons (text-red-600 hover:underline — see e.g.
                     nas-index.blade.php's "Hapus" button) rather than a one-off
                     filled bg-red-600 button, which turned out to be the only
                     place in the whole app using that style — and was reported
                     invisible in real testing because the Tailwind CSS bundle
                     hadn't been rebuilt since this view was added (bg-red-600
                     was simply missing from the compiled CSS, not a class-name
                     typo). Switching to the shared pattern fixes both issues at
                     once and keeps this button visually consistent app-wide. --}}
                <button
                    wire:click="revokeAndRegenerate"
                    wire:confirm="Yakin? Akun VPN lama akan DICABUT PERMANEN (private key/sesi lama hilang, NAS yang masih pakai koneksi lama akan terputus), lalu akun baru langsung dibuat dan script digenerate ulang."
                    wire:loading.attr="disabled"
                    class="px-3 py-2 border border-red-300 rounded-md text-red-600 hover:underline hover:bg-red-50 text-xs font-medium"
                >
                    Cabut &amp; Generate Ulang
                </button>
            @endif
        </div>
    @endif

    @if ($fetchCommand)
        <div class="space-y-4" x-data="{
            copy(text, evt) {
                const btn = evt.currentTarget;
                const done = () => { btn.innerText = 'Tersalin!'; setTimeout(() => btn.innerText = 'Salin', 1500); };
                if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done).catch(() => this.copyFallback(text, done));
                } else {
                    this.copyFallback(text, done);
                }
            },
            copyFallback(text, done) {
                const el = document.createElement('textarea');
                el.value = text;
                el.setAttribute('readonly', '');
                el.style.position = 'fixed';
                el.style.left = '-9999px';
                document.body.appendChild(el);
                el.select();
                el.setSelectionRange(0, text.length);
                try { document.execCommand('copy'); done(); } catch (e) {}
                document.body.removeChild(el);
            }
        }">
            <div class="p-4 rounded-md border border-primary/30 bg-primary/5 space-y-2">
                <div class="flex items-center justify-between">
                    <label class="block text-sm font-medium">1 baris — paste ke terminal Mikrotik</label>
                    <button
                        type="button"
                        x-on:click="copy($refs.fetchCommandOutput.innerText, $event)"
                        class="text-xs px-2 py-1 border border-gray-300 rounded-md hover:bg-gray-50"
                    >Salin</button>
                </div>
                <pre x-ref="fetchCommandOutput" class="bg-gray-900 text-gray-100 text-xs rounded-md p-4 overflow-x-auto whitespace-pre-wrap break-all">{{ $fetchCommand }}</pre>
                <p class="text-xs text-gray-500">
                    Router akan mengunduh &amp; menjalankan script lewat <code>/tool fetch</code> + <code>/import</code>
                    (non-interaktif) — bukan paste langsung, supaya prompt konfirmasi RouterOS di tengah paste script
                    panjang (terutama OpenVPN yang berisi private key) tidak lagi memutus sisa perintah. Link download
                    berlaku {{ $downloadTtlMinutes }} menit dan hanya bisa diakses SATU KALI — kalau gagal/kedaluwarsa,
                    generate ulang dari sini.
                </p>
            </div>

            <details class="text-xs">
                <summary class="cursor-pointer text-gray-500 hover:text-gray-700">Lihat isi script lengkap (referensi/audit)</summary>
                <pre class="mt-2 bg-gray-900 text-gray-100 text-xs rounded-md p-4 overflow-x-auto whitespace-pre">{{ $generatedScript }}</pre>
            </details>
        </div>
    @endif
</div>
