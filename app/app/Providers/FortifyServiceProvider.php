<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\LoginResponse;
use App\Support\LoginIdentifierResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Login terpadu — satu redirect pasca-login untuk kedua jalur,
        // mendelegasikan tujuan ke route `/` (lihat LoginResponse).
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));

        // Login terpadu (satu pintu di `/`): field `login` = "Email atau
        // Nomor HP" (lihat config/fortify.php 'username' => 'login').
        // - input berformat email  → jalur staff (tabel users, guard web)
        // - selain itu (nomor HP)  → jalur Referrer (cari Referrer aktif
        //   dengan nomor itu → user tertaut), reuse LoginIdentifierResolver
        //   (dipakai bareng ReferrerLoginController jalur lama).
        // Kembalikan null pada kegagalan APA PUN → Fortify melempar
        // ValidationException dengan trans('auth.failed') yang identik untuk
        // kedua jalur (tidak membocorkan identitas terdaftar / jalur mana).
        Fortify::authenticateUsing(function (Request $request) {
            $identifier = (string) $request->input('login', '');
            $password = (string) $request->input('password', '');

            if ($identifier === '' || $password === '') {
                return null;
            }

            $resolver = app(LoginIdentifierResolver::class);

            $user = $resolver->isEmail($identifier)
                ? $resolver->resolveStaffUser($identifier)
                : $resolver->resolveReferrerUser($identifier);

            if ($user !== null && Hash::check($password, $user->password)) {
                return $user;
            }

            return null;
        });

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });
    }
}
