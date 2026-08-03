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
            'active' => request()->routeIs('web.resellers.*') || request()->routeIs('web.reseller-package-pricing.*'),
            'links' => array_filter([
                auth()->user()->can('viewAny', \App\Models\Reseller::class)
                    ? ['route' => 'web.resellers.index', 'label' => __('Reseller')]
                    : null,
                auth()->user()->can('viewAny', \App\Models\ResellerPackagePricing::class)
                    ? ['route' => 'web.reseller-package-pricing.index', 'label' => __('Package Pricing')]
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
                        <a
                            href="{{ route($link['route']) }}"
                            class="block px-3 py-1.5 text-sm rounded-md {{ request()->routeIs($link['route']) ? 'bg-primary text-white' : 'text-gray-600 hover:bg-gray-100' }}"
                        >
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>
</aside>
