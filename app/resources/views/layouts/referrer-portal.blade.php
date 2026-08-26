<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? __('Portal Referrer') }} - {{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
        @stack('styles')
    </head>
    <body>
        <div class="min-h-screen bg-gray-50">
            <header class="bg-white border-b border-gray-200">
                <div class="max-w-3xl mx-auto px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">{{ __('Portal Referrer') }}</p>
                        <p class="font-semibold text-gray-800">{{ config('app.name') }}</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <x-language-switcher />
                        <span class="text-sm text-gray-600">{{ request()->attributes->get('referrer')?->name ?? auth()->user()->name }}</span>
                        <form method="POST" action="{{ route('referrer.logout') }}">
                            @csrf
                            <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Logout') }}</button>
                        </form>
                    </div>
                </div>
            </header>

            <main class="max-w-3xl mx-auto px-6 py-6">
                {{ $slot }}
            </main>
        </div>

        @livewireScripts
        @stack('scripts')
    </body>
</html>
