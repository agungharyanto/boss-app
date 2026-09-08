<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @auth
            {{-- Per-user theme override — must come after @vite so it wins the cascade
                 over the fallback values declared in resources/css/app.css. --}}
            <style>
                :root {
                    --color-primary: {{ auth()->user()->preference?->theme_primary_color ?? '#2563eb' }};
                    --color-text: {{ auth()->user()->preference?->theme_text_color ?? '#1f2937' }};
                }
            </style>
        @endauth

        <style>[x-cloak] { display: none !important; }</style>

        @livewireStyles
        @stack('styles')
    </head>
    <body>
        @auth
            {{-- v0.17.0 — `sidebarOpen` drives the off-canvas drawer below the
                 md breakpoint only. At md and up the sidebar is a plain inline
                 flex child exactly as before (see the md-prefixed overrides in
                 components/sidebar.blade.php) and this state is never read.
                 Drawer always starts closed on every page load — no
                 localStorage (unlike the accordion cluster state inside the
                 sidebar, which is deliberately persistent). --}}
            {{-- v0.17.0 Langkah 2.1 — setiap kali drawer dibuka/ditutup,
                 broadcast `boss:sidebar-toggled` SETELAH animasi slide
                 selesai (drawer pakai transition-transform duration-200 →
                 260ms aman). Peta Leaflet (di halaman mana pun) listen event
                 ini dan panggil invalidateSize() supaya tile tidak
                 pecah/kolaps setelah drawer menutupi/melepas area peta di HP.
                 Halaman tanpa peta: event ini no-op. --}}
            <div
                x-data="{ sidebarOpen: false }"
                x-on:keydown.escape.window="sidebarOpen = false"
                x-init="$watch('sidebarOpen', () => setTimeout(() => window.dispatchEvent(new CustomEvent('boss:sidebar-toggled')), 260))"
            >
                <div class="flex">
                    {{-- Mobile drawer backdrop — md:hidden. Tap to close.
                         z-[1100] (was z-30) — see the z-index note in
                         components/sidebar.blade.php: must sit above a
                         Leaflet map's own panes/controls (up to z-1000) but
                         below the drawer <aside> at z-[1200]. --}}
                    <div
                        x-show="sidebarOpen"
                        x-cloak
                        x-transition.opacity
                        x-on:click="sidebarOpen = false"
                        class="fixed inset-0 bg-black/40 z-[1100] md:hidden"
                        aria-hidden="true"
                    ></div>

                    <x-sidebar />

                    <div class="flex-1 min-w-0">
                        <div class="flex justify-end items-center gap-3 p-3">
                            {{-- Hamburger — md:hidden. Opens the drawer. --}}
                            <button
                                type="button"
                                x-on:click="sidebarOpen = true"
                                class="md:hidden inline-flex items-center justify-center w-9 h-9 rounded-md text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-primary"
                                aria-label="{{ __('Buka menu navigasi') }}"
                            >
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                </svg>
                            </button>

                        <x-language-switcher />

                        <div class="relative" x-data="{ profileMenuOpen: false }" x-on:click.outside="profileMenuOpen = false">
                            <button
                                type="button"
                                x-on:click="profileMenuOpen = !profileMenuOpen"
                                class="w-8 h-8 rounded-full bg-primary text-white flex items-center justify-center text-sm font-medium hover:opacity-90"
                            >
                                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                            </button>

                            {{-- z-[1250] (was z-50) — same Leaflet stacking
                                 conflict as the drawer: on a map page this
                                 dropdown opens down over the map area and
                                 z-50 put it behind Leaflet's panes/controls.
                                 Above the drawer, below <x-modal> (z-[1300]). --}}
                            <div
                                x-show="profileMenuOpen"
                                x-cloak
                                class="absolute right-0 mt-2 w-48 bg-white border border-gray-200 rounded-md shadow-lg z-[1250] py-1"
                            >
                                <p class="px-4 py-2 text-sm font-medium text-gray-700 border-b border-gray-100 truncate">
                                    {{ auth()->user()->name }}
                                </p>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50">
                                        {{ __('Logout') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                        {{ $slot }}
                    </div>
                </div>
            </div>
        @else
            <div class="flex justify-end p-3">
                <x-language-switcher />
            </div>

            {{ $slot }}
        @endauth

        @livewireScripts
        @stack('scripts')
    </body>
</html>
