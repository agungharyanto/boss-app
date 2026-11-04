<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        {{-- v0.13.4.5 — Layout publik bersama untuk halaman WO signed-link
             (klaim, status aktivasi, status/penutupan, kartu status). Public-
             facing TANPA login; @livewireScripts HANYA dipakai untuk runtime
             Alpine.js yang ikut ter-bundle (tidak ada komponen Livewire di
             halaman publik ini — lihat claim.blade's own docblock). Form
             markup halaman tidak disentuh sprint ini; hanya header/kartu yang
             dipindah ke sini. --}}
        @stack('head')
        <title>@yield('title', 'Work Order') - {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-100 min-h-screen py-6 px-4">
        <div class="max-w-2xl mx-auto bg-white rounded-md shadow p-6">
            @yield('content')
        </div>

        @stack('scripts')
        @livewireScripts
    </body>
</html>
