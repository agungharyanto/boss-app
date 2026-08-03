<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPreference;

class ThemeSettingsService
{
    public const DEFAULT_PRIMARY_COLOR = '#2563eb';

    public const DEFAULT_TEXT_COLOR = '#1f2937';

    /**
     * @return array{primary_color: string, text_color: string}
     */
    public function get(User $user): array
    {
        $preference = $user->preference;

        return [
            'primary_color' => $preference?->theme_primary_color ?? self::DEFAULT_PRIMARY_COLOR,
            'text_color' => $preference?->theme_text_color ?? self::DEFAULT_TEXT_COLOR,
        ];
    }

    public function update(User $user, string $primaryColor, string $textColor): UserPreference
    {
        return $user->preference()->updateOrCreate([], [
            'theme_primary_color' => $primaryColor,
            'theme_text_color' => $textColor,
        ]);
    }
}
