<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? __('Portal Referrer') }} - {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="bg-gray-100 min-h-screen flex items-center justify-center">
        <div class="w-full max-w-sm p-6 bg-white rounded-md shadow">
            <div class="flex justify-end mb-2">
                <x-language-switcher />
            </div>

            <h1 class="text-xl font-semibold text-gray-800 mb-1">{{ __('Portal Referrer') }}</h1>
            <p class="text-sm text-gray-500 mb-4">{{ config('app.name') }}</p>

            {{ $slot }}
        </div>

        @livewireScripts
    </body>
</html>
