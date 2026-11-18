<?php

namespace App\Services;

use App\Exceptions\UpstreamUnavailableException;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class NationalityLookup
{
    /** Cache lifetime for every entry: 24 hours (intent.md). */
    public const CACHE_TTL_SECONDS = 86400;

    public function __construct(private readonly NationalizeClient $client) {}

    /**
     * @param  array<int, string>  $names  normalized names in input order (duplicates allowed)
     * @return array<int, array{count: int, country: array<int, array{country_id: string, probability: float}>}>
     *                                                                                                           one record per input name, in input order
     *
     * @throws UpstreamUnavailableException
     */
    public function lookup(array $names): array
    {
        // Names that differ only by case are the same name (spec FR-4/FR-5): group by cache key.
        $distinct = [];
        foreach ($names as $name) {
            $distinct[NameNormalizer::cacheKey($name)] ??= $name;
        }

        $records = $this->cached(array_keys($distinct));

        $missing = array_diff_key($distinct, $records);

        if ($missing !== []) {
            // One batched call for everything the cache did not have. Failure here fails the
            // whole request (spec FR-12) and nothing is cached.
            $fetched = array_combine(array_keys($missing), $this->client->fetch(array_values($missing)));

            $this->remember($fetched);
            $records += $fetched;
        }

        return array_map(fn (string $name) => $records[NameNormalizer::cacheKey($name)], $names);
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, array> only valid entries; anything else counts as a miss
     */
    private function cached(array $keys): array
    {
        try {
            $found = Cache::many($keys);
        } catch (Throwable $e) {
            // Fail open (spec 4.3): an unreachable cache must not take the service down.
            Log::warning('Name cache unavailable for reads', ['exception' => $e::class]);

            return [];
        }

        return array_filter($found, fn ($value) => is_array($value) && isset($value['country']) && is_array($value['country']));
    }

    /** @param  array<string, array>  $records */
    private function remember(array $records): void
    {
        try {
            foreach ($records as $key => $record) {
                Cache::put($key, $record, self::CACHE_TTL_SECONDS);
            }
        } catch (Throwable $e) {
            Log::warning('Name cache unavailable for writes', ['exception' => $e::class]);
        }
    }
}
