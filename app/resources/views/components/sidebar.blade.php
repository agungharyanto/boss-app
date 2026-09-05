@php
    $clusters = [
        [
            'id' => 'pelanggan',
            'label' => __('Pelanggan'),
            'active' => request()->routeIs('web.customers.*'),
            'links' => array_filter([
                ['route' => 'web.customers.index', 'label' => __('Daftar Pelanggan')],
                auth()->user()->can('register-customer')
                    ? ['route' => 'web.customers.register', 'label' => __('Registrasi Pelanggan')]
                    : null,
                // v0.16.0 Langkah 12 — manual coordinate bind (feeds the
                // Peta Topologi "Pelanggan" layer). Same gate as "Daftar
                // Pelanggan"'s own page (CustomerPolicy::viewAny — admits
                // internal staff + reseller staffers); the per-row "Set
                // Lokasi" action is separately gated on customers.manage /
                // CustomerPolicy::update inside the component.
                auth()->user()->can('viewAny', \App\Models\Customer::class)
                    ? ['route' => 'web.customers.coordinates', 'label' => __('Lengkapi Koordinat')]
                    : null,
            ]),
        ],
        [
            'id' => 'operasional',
            'label' => __('Operasional'),
            // "Package Pricing" (reseller_package_pricing) link dihapus dari
            // sidebar — fitur itu 0 baris data & digantikan cost_price/
            // sell_price di Profil Hotspot/Profil PPP; 2 tempat harga bikin
            // ambigu. Route/controller/model-nya sengaja TIDAK dihapus
            // (resiko ke fitur reseller lain), cuma link menunya.
            // "Referrer" + "Rate Komisi" dipindah ke cluster "Billing &
            // Finance" (konsep komisi/keuangan). Cluster ini sekarang
            // tinggal "Reseller" — dibiarkan 1-item dulu, keputusan
            // membubarkan/memindah menunggu konfirmasi.
            'active' => request()->routeIs('web.resellers.*'),
            'links' => array_filter([
                auth()->user()->can('viewAny', \App\Models\Reseller::class)
                    ? ['route' => 'web.resellers.index', 'label' => __('Reseller')]
                    : null,
            ]),
        ],
        [
            'id' => 'billing-finance',
            'label' => __('Billing & Finance'),
            'active' => request()->routeIs('web.tax-components.*')
                || request()->routeIs('web.reseller-tax-policies.*')
                || request()->routeIs('web.subscriptions.*')
                || request()->routeIs('web.invoices.*')
                || request()->routeIs('web.payment-reconciliation.*')
                // Cluster "Profil Paket" dipindah ke sini dari "Network"
                // (harga jual/modal paket = konsep Billing, bukan Network).
                || request()->routeIs('web.bandwidth-profiles.*')
                || request()->routeIs('web.customer-ip-pools.*')
                || request()->routeIs('web.network-profile-groups.*')
                || request()->routeIs('web.hotspot-packages.*')
                || request()->routeIs('web.ppp-packages.*')
                // "Referrer" + "Rate Komisi" dipindah ke sini dari
                // "Operasional" (komisi/keuangan).
                || request()->routeIs('web.referrers.*')
                || request()->routeIs('web.commission-rates.*')
                || request()->routeIs('web.titip-masuk.*')
                || request()->routeIs('web.monthly-payout.*'),
            'links' => array_filter([
                auth()->user()->can('viewAny', \App\Models\TaxComponent::class)
                    ? ['route' => 'web.tax-components.index', 'label' => __('Tax Components')]
                    : null,
                auth()->user()->can('viewAny', \App\Models\ResellerTaxPolicy::class)
                    ? ['route' => 'web.reseller-tax-policies.index', 'label' => __('Reseller Tax Policy')]
                    : null,
                auth()->user()->can('viewAny', \App\Models\Subscription::class)
                    ? ['route' => 'web.subscriptions.index', 'label' => __('Subscriptions')]
                    : null,
                auth()->user()->can('viewAny', \App\Models\Invoice::class)
                    ? ['route' => 'web.invoices.index', 'label' => __('Invoices')]
                    : null,
                auth()->user()->can('viewAny', \App\Models\Invoice::class)
                    ? ['route' => 'web.payment-reconciliation.index', 'label' => __('Payment Reconciliation')]
                    : null,
                // "Profil Paket" — grup collapsible TOGGLE-MURNI (tanpa
                // key 'route'): klik parent HANYA expand/collapse, tidak
                // navigasi ke mana pun. Beda dari NAS/Perangkat CPE yang
                // parent row-nya tetap link ke halaman index-nya sendiri.
                // Bandwidth Profile (dulu jadi link parent) kini jadi child
                // pertama. Semua 5 permission selalu diberikan bersamaan
                // (giveToAdminTier), jadi meng-gate seluruh grup pada
                // viewAny(BandwidthProfile) tidak pernah menyembunyikan
                // halaman yang bisa dicapai user — tiap child tetap punya
                // check sendiri (defense in depth).
                auth()->user()->can('viewAny', \App\Models\BandwidthProfile::class)
                    ? [
                        'id' => 'profil-paket',
                        'toggle_only' => true,
                        'label' => __('Profil Paket'),
                        'children' => array_filter([
                            ['route' => 'web.bandwidth-profiles.index', 'label' => __('Bandwidth Profile')],
                            auth()->user()->can('viewAny', \App\Models\CustomerIpPool::class)
                                ? ['route' => 'web.customer-ip-pools.index', 'label' => __('IP Pool Pelanggan')]
                                : null,
                            auth()->user()->can('viewAny', \App\Models\NetworkProfileGroup::class)
                                ? ['route' => 'web.network-profile-groups.index', 'label' => __('Grup Profil')]
                                : null,
                            auth()->user()->can('viewAny', \App\Models\HotspotPackage::class)
                                ? ['route' => 'web.hotspot-packages.index', 'label' => __('Profil Hotspot')]
                                : null,
                            auth()->user()->can('viewAny', \App\Models\PppPackage::class)
                                ? ['route' => 'web.ppp-packages.index', 'label' => __('Profil PPP')]
                                : null,
                        ]),
                    ]
                    : null,
                // Dipindah dari cluster "Operasional" — Referrer adalah
                // konsep komisi/keuangan. Tier-admin-only, gate sama dengan
                // ReferrerPolicy.
                auth()->user()->can('viewAny', \App\Models\Referrer::class)
                    ? ['route' => 'web.referrers.index', 'label' => __('Referrer')]
                    : null,
                // "Komisi" — grup collapsible TOGGLE-MURNI (tanpa key
                // 'route'): klik parent HANYA expand/collapse. Pola persis
                // "Profil Paket" di atas. `commission_rates.*` dan
                // `commission_ledger.*` selalu diberikan bersamaan
                // (giveToAdminTier), jadi meng-gate seluruh grup pada
                // viewAny(CommissionRate) tidak pernah menyembunyikan
                // halaman yang bisa dicapai user — tiap child tetap punya
                // check sendiri (defense in depth).
                auth()->user()->can('viewAny', \App\Models\CommissionRate::class)
                    ? [
                        'id' => 'komisi',
                        'toggle_only' => true,
                        'label' => __('Komisi'),
                        'children' => array_filter([
                            ['route' => 'web.commission-rates.index', 'label' => __('Rate Komisi')],
                            // Route/URL tetap `titip-masuk` (hindari break
                            // bookmark) — hanya label tampilan yang berubah
                            // jadi "Fee Komisi".
                            auth()->user()->can('viewAny', \App\Models\CommissionLedger::class)
                                ? ['route' => 'web.titip-masuk.index', 'label' => __('Fee Komisi')]
                                : null,
                            // v0.9.11 — payout batch komisi bulanan (jendela
                            // tanggal 5-7), beda halaman dari Fee Komisi
                            // (yang khusus Titip, payout instan).
                            auth()->user()->can('viewAny', \App\Models\CommissionLedger::class)
                                ? ['route' => 'web.monthly-payout.index', 'label' => __('Payout Bulanan')]
                                : null,
                        ]),
                    ]
                    : null,
            ]),
        ],
        [
            'id' => 'komunikasi',
            'label' => __('Komunikasi'),
            'active' => request()->routeIs('web.whatsapp-gateway.*'),
            'links' => array_filter([
                auth()->user()->can('viewAny', \App\Models\WhatsappSession::class)
                    ? ['route' => 'web.whatsapp-gateway.index', 'label' => __('WhatsApp Gateway')]
                    : null,
            ]),
        ],
        [
            'id' => 'network',
            'label' => __('Network'),
            'active' => request()->routeIs('web.nas.*') || request()->routeIs('web.vpn-script-generator.*') || request()->routeIs('web.cpe-devices.*') || request()->routeIs('web.olt-devices.*') || request()->routeIs('web.monitoring.*'),
            // v0.8.1 — nested one level deeper than a plain link: an item
            // with a 'children' key renders as its own expand/collapse
            // sub-group (own localStorage key, same pattern as the
            // top-level clusters below) instead of a bare <a>. NAS/
            // Perangkat CPE both keep their own real index page as the
            // parent row's link — only the chevron toggles the sub-item,
            // navigation still works independently of expand state. (Grup
            // "Profil Paket" dulu di sini juga; sekarang pindah ke cluster
            // "Billing & Finance" sebagai grup toggle-murni.)
            'links' => array_filter([
                auth()->user()->can('viewAny', \App\Models\Nas::class)
                    ? [
                        'id' => 'nas',
                        'route' => 'web.nas.index',
                        'label' => __('NAS'),
                        'children' => [
                            ['route' => 'web.vpn-script-generator.index', 'label' => __('Script Generator')],
                        ],
                    ]
                    : null,
                auth()->user()->can('viewAny', \App\Models\OltDevice::class)
                    ? ['route' => 'web.olt-devices.index', 'label' => __('OLT')]
                    : null,
                // v0.8.2 — plain permission check (no Eloquent model backs
                // this page, LibreNMS device data isn't a boss_db table),
                // same posture as MonitoringIndex/DeviceMonitoringList/
                // DeviceTrafficGraph's own $this->authorize('monitoring.view').
                auth()->user()->can('monitoring.view')
                    ? ['route' => 'web.monitoring.index', 'label' => __('Monitoring')]
                    : null,
                auth()->user()->can('viewAny', \App\Models\CpeDevice::class)
                    ? [
                        'id' => 'cpe-devices',
                        'route' => 'web.cpe-devices.index',
                        'label' => __('Perangkat CPE'),
                        // Admin-only (cpe_devices.view directly, not the
                        // reseller carve-out CpeDevicePolicy::viewAny()
                        // also allows) — exposes legacy-import/matching
                        // internals not meant for reseller users.
                        'children' => auth()->user()->can('cpe_devices.view')
                            ? [['route' => 'web.cpe-devices.status-check', 'label' => __('Cek Status Device')]]
                            : [],
                    ]
                    : null,
            ]),
        ],
        [
            // v0.16.0 Langkah 8 — the fiber-topology module (Daftar
            // Perangkat Passive / Peta Topologi / Kapasitas Jaringan) was
            // 2 flat items inside the "Network" cluster; promoted to its
            // own top-level cluster, parallel to "Network", since it's a
            // distinct passive-plant concern from the active-network
            // (NAS/OLT/CPE/monitoring) items. All 3 links share the one
            // network_infrastructure.view/.manage permission pair, gated
            // via FiberNodePolicy::viewAny() same as before the move.
            'id' => 'topology-fiber',
            'label' => __('Topology Fiber'),
            'active' => request()->routeIs('web.fiber-nodes.*') || request()->routeIs('web.odps.*') || request()->routeIs('web.capacity-report.*') || request()->routeIs('web.fiber-topology-map.*') || request()->routeIs('web.odp-route-check.*'),
            'links' => array_filter([
                auth()->user()->can('viewAny', \App\Models\FiberNode::class)
                    ? ['route' => 'web.fiber-nodes.index', 'label' => __('Daftar Perangkat Passive')]
                    : null,
                auth()->user()->can('viewAny', \App\Models\FiberNode::class)
                    ? ['route' => 'web.fiber-topology-map.index', 'label' => __('Peta Topologi')]
                    : null,
                // v0.16.0 Langkah 11 — same permission gate (fiber module),
                // reachable by sales in practice pending a separate RBAC
                // decision to grant sales roles network_infrastructure.view.
                auth()->user()->can('viewAny', \App\Models\FiberNode::class)
                    ? ['route' => 'web.odp-route-check.index', 'label' => __('Cek Jalur ke ODP')]
                    : null,
                auth()->user()->can('viewAny', \App\Models\FiberNode::class)
                    ? ['route' => 'web.capacity-report.index', 'label' => __('Kapasitas Jaringan')]
                    : null,
            ]),
        ],
        [
            'id' => 'pengaturan',
            'label' => __('Pengaturan'),
            'active' => request()->routeIs('web.settings.*'),
            'links' => array_filter([
                ['route' => 'web.settings.theme', 'label' => __('Tema')],
                auth()->user()->can('view', \App\Models\PaymentGatewaySettings::class)
                    ? ['route' => 'web.settings.payment-gateway', 'label' => __('Payment Gateway')]
                    : null,
            ]),
        ],
    ];
@endphp

<aside class="w-64 shrink-0 bg-gray-50 border-r border-gray-200 min-h-screen p-4" aria-label="{{ __('Navigasi utama') }}">
    <nav class="space-y-2">
        <a
            href="{{ route('web.dashboard') }}"
            class="block px-3 py-2 text-sm font-semibold rounded-md {{ request()->routeIs('web.dashboard') ? 'bg-primary text-white' : 'text-gray-700 hover:bg-gray-100' }}"
        >
            {{ __('Dashboard') }}
        </a>

        {{-- Semua grup collapsible DEFAULT TERTUTUP saat halaman pertama
             dibuka. Pengecualian: grup yang route aktif-nya ada di dalamnya
             ($cluster['active'] / $subActive) di-auto-buka supaya user tahu
             posisinya. Pilihan manual user tetap dipersist di localStorage
             ('true' = pernah dibuka manual) dan dihormati untuk grup yang
             TIDAK sedang aktif. --}}
        @foreach ($clusters as $cluster)
            <div x-data="{ open: {{ $cluster['active'] ? 'true' : 'false' }} || localStorage.getItem('sidebar-cluster-{{ $cluster['id'] }}') === 'true' }">
                <button
                    type="button"
                    x-on:click="open = !open; localStorage.setItem('sidebar-cluster-{{ $cluster['id'] }}', open)"
                    x-bind:aria-expanded="open.toString()"
                    aria-controls="sidebar-cluster-{{ $cluster['id'] }}"
                    class="w-full flex items-center justify-between px-3 py-2 text-sm font-semibold text-gray-700 rounded-md hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-primary"
                >
                    <span>{{ $cluster['label'] }}</span>
                    <svg x-bind:class="open ? 'rotate-90' : ''" class="w-4 h-4 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>

                <div
                    id="sidebar-cluster-{{ $cluster['id'] }}"
                    x-show="open"
                    x-transition
                    class="mt-1 ml-3 space-y-1"
                >
                    @foreach ($cluster['links'] as $link)
                        @if (! empty($link['children']))
                            @php
                                // Dua tipe parent grup:
                                //  - link-and-toggle: punya key 'route' — label = link ke
                                //    halaman index-nya sendiri, chevron toggle child (NAS,
                                //    Perangkat CPE). Perilaku lama, tidak diubah.
                                //  - toggle-murni: TANPA key 'route' (atau 'toggle_only') —
                                //    klik parent HANYA expand/collapse, tidak navigasi
                                //    (Profil Paket).
                                $isToggleOnly = empty($link['route']);
                                $subActive = (! $isToggleOnly && request()->routeIs($link['route']))
                                    || collect($link['children'])->contains(fn ($c) => request()->routeIs($c['route']));
                            @endphp
                            <div x-data="{ subOpen: {{ $subActive ? 'true' : 'false' }} || localStorage.getItem('sidebar-subgroup-{{ $link['id'] }}') === 'true' }">
                                @if ($isToggleOnly)
                                    <button
                                        type="button"
                                        x-on:click="subOpen = !subOpen; localStorage.setItem('sidebar-subgroup-{{ $link['id'] }}', subOpen)"
                                        x-bind:aria-expanded="subOpen.toString()"
                                        aria-controls="sidebar-subgroup-{{ $link['id'] }}"
                                        class="w-full flex items-center justify-between px-3 py-1.5 text-sm rounded-md focus:outline-none {{ $subActive ? 'bg-primary text-white' : 'text-gray-600 hover:bg-gray-100' }}"
                                    >
                                        <span>{{ $link['label'] }}</span>
                                        <svg x-bind:class="subOpen ? 'rotate-90' : ''" class="w-3.5 h-3.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                        </svg>
                                    </button>
                                @else
                                    <div class="flex items-center rounded-md {{ request()->routeIs($link['route']) ? 'bg-primary text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                                        <a href="{{ route($link['route']) }}" class="flex-1 px-3 py-1.5 text-sm">
                                            {{ $link['label'] }}
                                        </a>
                                        <button
                                            type="button"
                                            x-on:click="subOpen = !subOpen; localStorage.setItem('sidebar-subgroup-{{ $link['id'] }}', subOpen)"
                                            x-bind:aria-expanded="subOpen.toString()"
                                            aria-controls="sidebar-subgroup-{{ $link['id'] }}"
                                            class="px-2 py-1.5 focus:outline-none"
                                        >
                                            <svg x-bind:class="subOpen ? 'rotate-90' : ''" class="w-3.5 h-3.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                            </svg>
                                        </button>
                                    </div>
                                @endif
                                <div id="sidebar-subgroup-{{ $link['id'] }}" x-show="subOpen" x-transition class="ml-4 mt-1 space-y-1">
                                    @foreach ($link['children'] as $child)
                                        <a
                                            href="{{ route($child['route']) }}"
                                            class="block px-3 py-1.5 text-sm rounded-md {{ request()->routeIs($child['route']) ? 'bg-primary text-white' : 'text-gray-600 hover:bg-gray-100' }}"
                                        >
                                            {{ $child['label'] }}
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <a
                                href="{{ route($link['route']) }}"
                                class="block px-3 py-1.5 text-sm rounded-md {{ request()->routeIs($link['route']) ? 'bg-primary text-white' : 'text-gray-600 hover:bg-gray-100' }}"
                            >
                                {{ $link['label'] }}
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>
</aside>
