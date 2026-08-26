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
            <div class="flex">
                <x-sidebar />

                <div class="flex-1 min-w-0">
                    <div class="flex justify-end items-center gap-3 p-3">
                        <x-language-switcher />

                        <div class="relative" x-data="{ profileMenuOpen: false }" x-on:click.outside="profileMenuOpen = false">
                            <button
                                type="button"
                                x-on:click="profileMenuOpen = !profileMenuOpen"
                                class="w-8 h-8 rounded-full bg-primary text-white flex items-center justify-center text-sm font-medium hover:opacity-90"
                            >
                                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                            </button>

                            <div
                                x-show="profileMenuOpen"
                                x-cloak
                                class="absolute right-0 mt-2 w-48 bg-white border border-gray-200 rounded-md shadow-lg z-50 py-1"
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
