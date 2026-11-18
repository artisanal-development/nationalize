<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesNationalize;
use Tests\TestCase;

class NationalizeEndpointTest extends TestCase
{
    use FakesNationalize;

    private function mario(): array
    {
        // Deliberately unsorted and longer than 5 entries.
        return ['mario' => ['count' => 1234, 'country' => [
            ['country_id' => 'AR', 'probability' => 0.06],
            ['country_id' => 'IT', 'probability' => 0.83],
            ['country_id' => 'ES', 'probability' => 0.04],
            ['country_id' => 'BR', 'probability' => 0.03],
            ['country_id' => 'US', 'probability' => 0.02],
            ['country_id' => 'FR', 'probability' => 0.01],
            ['country_id' => 'DE', 'probability' => 0.004],
        ]]];
    }

    public function test_returns_the_documented_shape_ranked_and_limited_to_five(): void
    {
        $this->fakeNationalize($this->mario());

        $this->postJson('/nationalize', ['names' => ['Mario']])
            ->assertOk()
            ->assertExactJson(['results' => [[
                'name' => 'Mario',
                'most_probable_country' => 'IT',
                'probability' => '83%',
                'countries' => [
                    ['country_id' => 'IT', 'probability' => '83%'],
                    ['country_id' => 'AR', 'probability' => '6%'],
                    ['country_id' => 'ES', 'probability' => '4%'],
                    ['country_id' => 'BR', 'probability' => '3%'],
                    ['country_id' => 'US', 'probability' => '2%'],
                ],
            ]]]);
    }

    public function test_probabilities_are_rounded_to_whole_percents(): void
    {
        $this->fakeNationalize(['x' => ['count' => 5, 'country' => [
            ['country_id' => 'AA', 'probability' => 0.826],
            ['country_id' => 'BB', 'probability' => 0.5],
            ['country_id' => 'CC', 'probability' => 0.004],
            ['country_id' => 'DD', 'probability' => 0.0],
        ]]]);

        $this->postJson('/nationalize', ['names' => ['x']])
            ->assertOk()
            ->assertJsonPath('results.0.probability', '83%')
            ->assertJsonPath('results.0.countries.1.probability', '50%')
            ->assertJsonPath('results.0.countries.2.probability', '0%')
            ->assertJsonPath('results.0.countries.3.probability', '0%');
    }

    public function test_ties_keep_the_upstream_order(): void
    {
        $this->fakeNationalize(['x' => ['count' => 5, 'country' => [
            ['country_id' => 'ZZ', 'probability' => 0.3],
            ['country_id' => 'AA', 'probability' => 0.3],
            ['country_id' => 'MM', 'probability' => 0.3],
        ]]]);

        $this->postJson('/nationalize', ['names' => ['x']])
            ->assertJsonPath('results.0.countries.0.country_id', 'ZZ')
            ->assertJsonPath('results.0.countries.1.country_id', 'AA')
            ->assertJsonPath('results.0.countries.2.country_id', 'MM');
    }

    public function test_unknown_name_returns_nulls_and_an_empty_list(): void
    {
        $this->fakeNationalize(['zzqxkw' => ['count' => 0, 'country' => []]]);

        $this->postJson('/nationalize', ['names' => ['zzqxkw']])
            ->assertOk()
            ->assertExactJson(['results' => [[
                'name' => 'zzqxkw',
                'most_probable_country' => null,
                'probability' => null,
                'countries' => [],
            ]]]);
    }

    public function test_all_cache_misses_go_upstream_in_one_call_with_name_array_and_key(): void
    {
        $this->fakeNationalize();

        $this->postJson('/nationalize', ['names' => ['nguyen', 'schmidt', 'garcia']])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://api.nationalize.io/?')
                && $query['name'] === ['nguyen', 'schmidt', 'garcia']
                && $query['apikey'] === 'test-key-not-real';
        });
    }

    public function test_a_single_name_is_still_sent_as_a_name_array(): void
    {
        $this->fakeNationalize();

        $this->postJson('/nationalize', ['names' => ['Mario']])->assertOk();

        Http::assertSent(fn (Request $request) => str_contains(rawurldecode($request->url()), 'name[]=Mario'));
    }

    public function test_duplicates_are_sent_once_but_answered_each_time_in_input_order(): void
    {
        $this->fakeNationalize();

        $response = $this->postJson('/nationalize', ['names' => ['nguyen', 'mario', 'nguyen']])->assertOk();

        $this->assertSame(['nguyen', 'mario', 'nguyen'], array_column($response->json('results'), 'name'));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $this->requestedNames($request) === ['nguyen', 'mario']);
    }

    public function test_names_that_normalize_alike_are_one_upstream_name_but_keep_their_trimmed_spelling(): void
    {
        $this->fakeNationalize();

        $response = $this->postJson('/nationalize', ['names' => ['  Mario ', 'mario', 'MARIO', 'de   la Cruz', "de la\tCruz"]])
            ->assertOk();

        $this->assertSame(
            ['Mario', 'mario', 'MARIO', 'de   la Cruz', "de la\tCruz"],
            array_column($response->json('results'), 'name'),
        );
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $this->requestedNames($request) === ['Mario', 'de la Cruz']);
    }

    public function test_composed_and_decomposed_spellings_are_one_upstream_name(): void
    {
        $this->fakeNationalize();

        $this->postJson('/nationalize', ['names' => ["Jos\u{00E9}", "Jose\u{0301}"]])->assertOk();

        Http::assertSent(fn (Request $request) => $this->requestedNames($request) === ["Jos\u{00E9}"]);
    }

    public function test_each_result_is_matched_to_its_own_name_by_position(): void
    {
        $this->fakeNationalize([
            'nguyen' => ['count' => 1, 'country' => [['country_id' => 'VN', 'probability' => 0.9]]],
            'schmidt' => ['count' => 1, 'country' => [['country_id' => 'DE', 'probability' => 0.4]]],
        ]);

        $response = $this->postJson('/nationalize', ['names' => ['schmidt', 'nguyen']])->assertOk();

        $this->assertSame('DE', $response->json('results.0.most_probable_country'));
        $this->assertSame('VN', $response->json('results.1.most_probable_country'));
    }
}
