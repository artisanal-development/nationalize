<?php

namespace App\Services;

use App\Exceptions\UpstreamUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only code that talks to Nationalize.io. It never logs or throws anything containing
 * the request URL (which carries the API key) or the names that were looked up.
 */
class NationalizeClient
{
    /**
     * Look up a batch of names in one request.
     *
     * @param  array<int, string>  $names  normalized, distinct names
     * @return array<int, array{count: int, country: array<int, array{country_id: string, probability: float}>}>
     *                                                                                                           records in the same order as $names (matched by position)
     *
     * @throws UpstreamUnavailableException
     */
    public function fetch(array $names): array
    {
        try {
            $response = Http::timeout((int) config('services.nationalize.timeout', 5))
                ->acceptJson()
                // One retry at most, and only for connection errors and 5xx. Never retry
                // 4xx/429: that would spend paid quota on calls that cannot succeed.
                ->retry(
                    2,
                    200,
                    fn ($exception) => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException && $exception->response->serverError()),
                    throw: false,
                )
                ->get($this->url($names));
        } catch (ConnectionException) {
            // Deliberately not chained: its message contains the full URL including the key.
            throw UpstreamUnavailableException::unavailable('connection failed or timed out');
        }

        $this->warnWhenQuotaIsLow($response);

        return $this->records($response, count($names));
    }

    /** Exactly the documented form: ?name[]=a&name[]=b&apikey=... */
    private function url(array $names): string
    {
        $query = array_map(fn (string $name) => 'name[]='.rawurlencode($name), $names);
        $query[] = 'apikey='.rawurlencode((string) config('services.nationalize.key'));

        return rtrim((string) config('services.nationalize.base_url'), '/').'/?'.implode('&', $query);
    }

    private function records(Response $response, int $expected): array
    {
        $status = $response->status();

        if ($status === 401 || $status === 402) {
            Log::error('Nationalize.io rejected our credentials or subscription', ['status' => $status]);
            throw UpstreamUnavailableException::unavailable("upstream status $status");
        }

        if ($status === 422) {
            throw UpstreamUnavailableException::invalidResponse('upstream rejected a name we had validated');
        }

        if ($status === 429) {
            throw UpstreamUnavailableException::unavailable('upstream status 429', $response->header('Retry-After') ?: null);
        }

        if (! $response->successful()) {
            throw UpstreamUnavailableException::unavailable("upstream status $status");
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw UpstreamUnavailableException::invalidResponse('upstream body is not JSON');
        }

        // Plain `name=` returns a bare object; `name[]=` returns a list. Accept both.
        if (array_key_exists('country', $payload)) {
            $payload = [$payload];
        }

        if (! array_is_list($payload) || count($payload) !== $expected) {
            throw UpstreamUnavailableException::invalidResponse('unexpected number of records');
        }

        return array_map($this->record(...), $payload);
    }

    /** Keep only what we cache and render; the echoed `name` is dropped on purpose. */
    private function record(mixed $record): array
    {
        if (! is_array($record) || ! isset($record['country']) || ! is_array($record['country']) || ! array_is_list($record['country'])) {
            throw UpstreamUnavailableException::invalidResponse('record has no country list');
        }

        $countries = [];
        foreach ($record['country'] as $country) {
            if (
                ! is_array($country)
                || ! isset($country['country_id']) || ! is_string($country['country_id']) || $country['country_id'] === ''
                || ! isset($country['probability']) || ! is_numeric($country['probability'])
                || $country['probability'] < 0 || $country['probability'] > 1
            ) {
                throw UpstreamUnavailableException::invalidResponse('malformed country entry');
            }

            $countries[] = ['country_id' => $country['country_id'], 'probability' => (float) $country['probability']];
        }

        return ['count' => (int) ($record['count'] ?? 0), 'country' => $countries];
    }

    private function warnWhenQuotaIsLow(Response $response): void
    {
        $remaining = $response->header('X-Rate-Limit-Remaining');

        if (is_numeric($remaining) && (int) $remaining < (int) config('services.nationalize.low_quota_threshold', 50)) {
            Log::warning('Nationalize.io quota is low', ['remaining' => (int) $remaining]);
        }
    }
}
