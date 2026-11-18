<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps ['name' => string, 'record' => ['count' => int, 'country' => [...]]].
 */
class NationalityResource extends JsonResource
{
    private const MAX_COUNTRIES = 5;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $countries = $this->resource['record']['country'];

        // usort is stable (PHP 8+), so equal probabilities keep the upstream order.
        usort($countries, fn (array $a, array $b) => $b['probability'] <=> $a['probability']);

        $countries = array_map(fn (array $country) => [
            'country_id' => $country['country_id'],
            'probability' => self::percent($country['probability']),
        ], array_slice($countries, 0, self::MAX_COUNTRIES));

        return [
            'name' => $this->resource['name'],
            'most_probable_country' => $countries[0]['country_id'] ?? null,
            'probability' => $countries[0]['probability'] ?? null,
            'countries' => $countries,
        ];
    }

    private static function percent(float $probability): string
    {
        return ((int) round($probability * 100)).'%';
    }
}
