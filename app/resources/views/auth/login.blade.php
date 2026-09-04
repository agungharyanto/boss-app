<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-100 min-h-screen flex items-center justify-center">
        <div class="w-full max-w-sm p-6 bg-white rounded-md shadow">
            <div class="flex justify-end mb-2">
                <x-language-switcher />
            </div>

            <h1 class="text-xl font-semibold text-gray-800 mb-4">{{ config('app.name') }}</h1>

            @if ($errors->any())
                <div class="mb-4 text-sm text-red-600">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @if (session('status'))
                <div class="mb-4 text-sm text-green-600">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Email atau Nomor HP') }}</label>
                    <input type="text" name="login" value="{{ old('login') }}" required autofocus
                        autocomplete="username" inputmode="text"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <p class="text-xs text-gray-500 mt-1">{{ __('Staff: email. Referral: nomor HP terdaftar.') }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Password</label>
                    <input type="password" name="password" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="remember"> Ingat saya
                </label>

                <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                    Masuk
                </button>
            </form>

            <div class="mt-4 text-sm text-center">
                {{-- Alur reset password via OTP WhatsApp (v0.9.6). Untuk akun
                     Referral (input nomor HP); akun staff yang lupa password
                     hubungi admin. --}}
                <a href="{{ route('referrer.password.request') }}" class="text-blue-600 hover:underline">{{ __('Lupa password?') }}</a>
            </div>
        </div>
    </body>
</html>
