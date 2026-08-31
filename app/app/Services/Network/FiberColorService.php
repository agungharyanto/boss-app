<?php

namespace App\Services\Network;

use InvalidArgumentException;

/**
 * v0.16.0 — Core Network Infrastructure Management. TIA/EIA-598-C's
 * standard 12-color fiber identification cycle, used to auto-derive
 * FiberCore.tube_color/core_color at creation time (see
 * FiberTopologyService::createCable()) when the operator hasn't supplied
 * an explicit override — the DB columns stay plain nullable strings,
 * this service is only ever consulted at write time, never at read time.
 */
class FiberColorService
{
    /**
     * @var list<array{name: string, hex: string}>
     */
    private const COLOR_CYCLE = [
        ['name' => 'Biru', 'hex' => '#2563EB'],
        ['name' => 'Orange', 'hex' => '#F97316'],
        ['name' => 'Hijau', 'hex' => '#16A34A'],
        ['name' => 'Coklat', 'hex' => '#92400E'],
        ['name' => 'Abu-abu', 'hex' => '#6B7280'],
        ['name' => 'Putih', 'hex' => '#FFFFFF'],
        ['name' => 'Merah', 'hex' => '#DC2626'],
        ['name' => 'Hitam', 'hex' => '#000000'],
        ['name' => 'Kuning', 'hex' => '#EAB308'],
        ['name' => 'Violet', 'hex' => '#7C3AED'],
        ['name' => 'Pink', 'hex' => '#EC4899'],
        ['name' => 'Toska', 'hex' => '#14B8A6'],
    ];

    /**
     * $position is 1-indexed (position 1 = the first tube/core, matching
     * how tube_number/core_number_in_tube are stored) — position 13 wraps
     * back around to the same color as position 1, position 25 to the
     * same as position 1 again, and so on every 12.
     *
     * @return array{name: string, hex: string}
     */
    public function resolveColor(int $position): array
    {
        if ($position < 1) {
            throw new InvalidArgumentException("Posisi warna harus bernilai 1 atau lebih, diberikan: {$position}.");
        }

        $index = ($position - 1) % 12;

        return self::COLOR_CYCLE[$index];
    }

    /**
     * Reverse lookup for rendering a swatch from a stored tube_color/
     * core_color NAME (v0.16.0 Langkah 4, FiberNodeDetail's splice
     * diagram) — a stored name can be a manual override that isn't one of
     * the 12 known cycle colors (see FiberCore's own "auto+override"
     * docblock), so this returns null rather than guessing; the Blade
     * view falls back to a neutral swatch + the text name itself in that
     * case, never hiding the name behind a missing color alone.
     */
    public function hexForName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        foreach (self::COLOR_CYCLE as $color) {
            if ($color['name'] === $name) {
                return $color['hex'];
            }
        }

        return null;
    }
}
