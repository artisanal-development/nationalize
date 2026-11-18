<?php

namespace App\Support;

use Normalizer;

/**
 * The single place that defines what "the same name" means (spec FR-4).
 */
final class NameNormalizer
{
    public const CACHE_PREFIX = 'nationalize:v1:';

    /** Trim, convert to Unicode NFC, and collapse internal whitespace to one space. */
    public static function normalize(string $name): string
    {
        $composed = Normalizer::normalize($name, Normalizer::FORM_C);
        if ($composed !== false) {
            $name = $composed;
        }

        return self::trim(preg_replace('/[\p{Z}\s]+/u', ' ', $name) ?? $name);
    }

    /** Trim leading/trailing whitespace only (Unicode-aware); internal whitespace is kept. */
    public static function trim(string $name): string
    {
        return preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $name) ?? $name;
    }

    /**
     * Memcached-safe, case-insensitive key for an already normalized name. Hashed because
     * memcached keys cannot contain whitespace and are limited to 250 bytes.
     */
    public static function cacheKey(string $normalizedName): string
    {
        return self::CACHE_PREFIX.sha1(mb_strtolower($normalizedName));
    }
}
