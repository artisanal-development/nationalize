<?php

namespace Tests\Feature;

use App\Support\NameNormalizer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesNationalize;
use Tests\Support\FailingCacheStore;
use Tests\TestCase;

class NationalityCacheTest extends TestCase
{
    use FakesNationalize;

    private function names(array $names)
    {
        return $this->postJson('/nationalize', ['names' => $names]);
    }

    public function test_a_repeated_name_is_served_from_the_cache_with_no_upstream_call(): void
    {
        $this->fakeNationalize();

        $first = $this->names(['Mario'])->assertOk();
        Http::assertSentCount(1);

        $second = $this->names(['Mario'])->assertOk();

        Http::assertSentCount(1); // still one: the second request made no call
        $this->assertSame($first->json(), $second->json());
    }

    public function test_when_every_name_is_cached_nothing_is_sent(): void
    {
        $this->fakeNationalize();
        $this->names(['nguyen', 'schmidt'])->assertOk();

        Http::fake(); // fresh recording; any call from now on would be recorded
        $this->names(['schmidt', 'nguyen', 'nguyen'])->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_cache_ignores_case_and_surrounding_or_internal_whitespace(): void
    {
        $this->fakeNationalize();
        $this->names(['de la Cruz'])->assertOk();

        $response = $this->names(['  DE   LA   CRUZ '])->assertOk();

        Http::assertSentCount(1);
        $this->assertSame('DE   LA   CRUZ', $response->json('results.0.name')); // echoes the trimmed input
    }

    public function test_only_the_missing_names_are_sent_and_results_stay_matched(): void
    {
        $this->fakeNationalize([
            'nguyen' => ['count' => 1, 'country' => [['country_id' => 'VN', 'probability' => 0.9]]],
            'schmidt' => ['count' => 1, 'country' => [['country_id' => 'DE', 'probability' => 0.4]]],
            'garcia' => ['count' => 1, 'country' => [['country_id' => 'ES', 'probability' => 0.5]]],
        ]);
        $this->names(['schmidt'])->assertOk();
        Http::fake([
            'api.nationalize.io/*' => fn (Request $request) => Http::response(array_map(
                fn (string $name) => ['count' => 1, 'name' => $name, 'country' => [
                    ['country_id' => $name === 'nguyen' ? 'VN' : 'ES', 'probability' => 0.7],
                ]],
                $this->requestedNames($request),
            )),
        ]);

        $response = $this->names(['nguyen', 'schmidt', 'garcia'])->assertOk();

        Http::assertSent(fn (Request $request) => $this->requestedNames($request) === ['nguyen', 'garcia']);
        $this->assertSame(['VN', 'DE', 'ES'], array_column($response->json('results'), 'most_probable_country'));
    }

    public function test_entries_live_for_exactly_24_hours(): void
    {
        $this->fakeNationalize();
        $this->travelTo(now()->startOfSecond());
        $start = now();
        $this->names(['Mario'])->assertOk();

        $this->travelTo($start->copy()->addHours(24)->subSecond());
        $this->names(['Mario'])->assertOk();
        Http::assertSentCount(1); // still cached just before 24h

        $this->travelTo($start->copy()->addHours(24)->addSecond());
        $this->names(['Mario'])->assertOk();
        Http::assertSentCount(2); // expired: asked upstream again
    }

    public function test_unknown_names_are_cached_too(): void
    {
        $this->fakeNationalize(['zzqxkw' => ['count' => 0, 'country' => []]]);

        $this->names(['zzqxkw'])->assertOk()->assertJsonPath('results.0.most_probable_country', null);
        $this->names(['zzqxkw'])->assertOk()->assertJsonPath('results.0.countries', []);

        Http::assertSentCount(1);
    }

    public function test_failures_are_never_cached(): void
    {
        Sleep::fake();
        Http::fake(['api.nationalize.io/*' => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push(['error' => 'boom'], 500)
            ->push([['count' => 5, 'name' => 'Mario', 'country' => [['country_id' => 'IT', 'probability' => 0.8]]]])]);

        $this->names(['Mario'])->assertStatus(503);
        $this->assertNull(Cache::get(NameNormalizer::cacheKey('Mario')));

        $this->names(['Mario'])->assertOk()->assertJsonPath('results.0.most_probable_country', 'IT');
        Http::assertSentCount(3);
    }

    public function test_the_cached_value_is_the_record_without_the_name_under_a_hashed_key(): void
    {
        $this->fakeNationalize(['mario' => ['count' => 7, 'country' => [['country_id' => 'IT', 'probability' => 0.8]]]]);

        $this->names(['Mario'])->assertOk();

        $this->assertSame(
            ['count' => 7, 'country' => [['country_id' => 'IT', 'probability' => 0.8]]],
            Cache::get(NameNormalizer::cacheKey('Mario')),
        );
    }

    public function test_a_corrupt_cache_entry_is_treated_as_a_miss(): void
    {
        $this->fakeNationalize();
        Cache::put(NameNormalizer::cacheKey('Mario'), 'garbage', 3600);

        $this->names(['Mario'])->assertOk();

        Http::assertSentCount(1);
        $this->assertIsArray(Cache::get(NameNormalizer::cacheKey('Mario')));
    }

    private function useCacheStore(FailingCacheStore $store): void
    {
        Cache::extend('failing', fn () => Cache::repository($store));
        config(['cache.stores.failing' => ['driver' => 'failing'], 'cache.default' => 'failing']);
        Cache::forgetDriver('failing');
    }

    public function test_when_the_cache_is_down_requests_still_succeed_via_upstream_and_log_no_names(): void
    {
        Log::spy();
        $this->fakeNationalize();
        $this->useCacheStore(new FailingCacheStore);

        $this->names(['Secretname'])->assertOk()->assertJsonPath('results.0.name', 'Secretname');
        $this->names(['Secretname'])->assertOk();

        Http::assertSentCount(2); // nothing cached, so each request went upstream
        Log::shouldHaveReceived('warning')->atLeast()->once();
        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            $text = $message.json_encode($context);

            return ! str_contains($text, 'Secretname') && ! str_contains($text, 'secret-key-material');
        })->atLeast()->once();
    }

    public function test_when_only_cache_writes_fail_requests_still_succeed(): void
    {
        $this->fakeNationalize();
        $this->useCacheStore(new FailingCacheStore(failReads: false, failWrites: true));

        $this->names(['Mario'])->assertOk()->assertJsonPath('results.0.name', 'Mario');
    }
}
