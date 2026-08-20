<?php

namespace App\Support;

/**
 * Derives a short code from a name by taking the first letter of every
 * word — e.g. "Bajastu Teknologi Waringin" -> "BTW". Deliberately simple:
 * no stopword filtering, no length limit, no dedup — just initials of
 * whatever words are actually there, uppercased.
 */
class NameToCodeDeriver
{
    public static function derive(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return '';
        }

        return implode('', array_map(
            fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)),
            $words
        ));
    }

    /**
     * derive() plus collision handling: if the derived code is already
     * taken (per the caller-supplied $exists check), appends 2, 3, 4, ...
     * until a free one is found — e.g. BTW, BTW2, BTW3. $exists is injected
     * rather than this class querying a table directly, so the same
     * algorithm works identically against an Eloquent scope (model hooks)
     * or a raw DB::table() query (migration backfills) without either
     * depending on the other.
     *
     * Returns null when $name derives to nothing (blank/whitespace-only) —
     * callers should treat that as "leave code unset", not an error.
     */
    public static function deriveUnique(string $name, callable $exists): ?string
    {
        $base = self::derive($name);

        if ($base === '') {
            return null;
        }

        $candidate = $base;
        $suffix = 2;

        while ($exists($candidate)) {
            $candidate = $base.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
