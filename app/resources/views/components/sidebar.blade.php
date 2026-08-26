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
            ]),
        ],
        [
            'id' => 'operasional',
            'label' => __('Operasional'),
            'active' => request()->routeIs('web.resellers.*') || request()->routeIs('web.reseller-package-pricing.*') || request()->routeIs('web.referrers.*'),
            'links' => array_filter([
                auth()->user()->can('viewAny', \App\Models\Reseller::class)
                    ? ['route' => 'web.resellers.index', 'label' => __('Reseller')]
                    : null,
                auth()->user()->can('viewAny', \App\Models\ResellerPackagePricing::class)
                    ? ['route' => 'web.reseller-package-pricing.index', 'label' => __('Package Pricing')]
                    : null,
                auth()->user()->can('viewAny', \App\Models\Referrer::class)
                    ? ['route' => 'web.referrers.index', 'label' => __('Referrer')]
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
                || request()->routeIs('web.payment-reconciliation.*'),
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
            // navigation still works independently of expand state.
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

        @foreach ($clusters as $cluster)
            <div x-data="{ open: localStorage.getItem('sidebar-cluster-{{ $cluster['id'] }}') !== 'false' }">
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
                            {{-- v0.8.1 — nested sub-group: the parent row is
                                 still a real link to its own index page, the
                                 chevron independently toggles the children
                                 list below it (own localStorage key so the
                                 expand state persists like top-level
                                 clusters do). --}}
                            <div x-data="{ subOpen: localStorage.getItem('sidebar-subgroup-{{ $link['id'] }}') !== 'false' }">
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
