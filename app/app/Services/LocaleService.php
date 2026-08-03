<?php

namespace App\Services;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Models\UserPreference;

class LocaleService
{
    /**
     * @return list<string>
     */
    public function supported(): array
    {
        return SetLocale::SUPPORTED;
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->supported(), true);
    }

    public function get(User $user): string
    {
        return $user->preference?->locale ?? config('app.locale');
    }

    public function update(User $user, string $locale): UserPreference
    {
        return $user->preference()->updateOrCreate([], ['locale' => $locale]);
    }
}
