<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

trait FakesNationalize
{
    /**
     * Fake Nationalize.io: answers every batch with one record per requested name, in
     * request order. $records maps lowercase name => ['count' => int, 'country' => [...]].
     * Names without an entry get a default record.
     *
     * @param  array<string, array{count: int, country: array<int, array{country_id: string, probability: float}>}>  $records
     */
    protected function fakeNationalize(array $records = []): void
    {
        Http::fake([
            'api.nationalize.io/*' => function (Request $request) use ($records) {
                $names = $this->requestedNames($request);

                return Http::response(array_map(function (string $name) use ($records) {
                    $record = $records[mb_strtolower($name)] ?? [
                        'count' => 1000,
                        'country' => [
                            ['country_id' => 'US', 'probability' => 0.6],
                            ['country_id' => 'GB', 'probability' => 0.3],
                        ],
                    ];

                    return ['count' => $record['count'], 'name' => $name, 'country' => $record['country']];
                }, $names));
            },
        ]);
    }

    /** @return array<int, string> the name[] values the app sent upstream */
    protected function requestedNames(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return array_values((array) ($query['name'] ?? []));
    }
}
