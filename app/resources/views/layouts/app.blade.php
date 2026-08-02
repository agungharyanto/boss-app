<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

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

        @livewireStyles
    </head>
    <body>
        {{ $slot }}

        @livewireScripts
    </body>
</html>
