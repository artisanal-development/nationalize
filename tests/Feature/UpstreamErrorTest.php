<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpstreamErrorTest extends TestCase
{
    private const URL = 'api.nationalize.io/*';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function record(string $name, string $country = 'US'): array
    {
        return ['count' => 10, 'name' => $name, 'country' => [['country_id' => $country, 'probability' => 0.5]]];
    }

    private function assertSafeBody($response, array $names = ['Secretname']): void
    {
        $body = $response->getContent();
        $this->assertStringNotContainsString('test-key-not-real', $body);
        $this->assertStringNotContainsString('api.nationalize.io', $body);
        foreach ($names as $name) {
            $this->assertStringNotContainsString($name, $body);
        }
        $response->assertHeader('Content-Type', 'application/json');
    }

    #[DataProvider('unavailableStatuses')]
    public function test_upstream_failures_become_503_without_leaking(int $status, array $body): void
    {
        Http::fake([self::URL => Http::response($body, $status)]);

        $response = $this->postJson('/nationalize', ['names' => ['Secretname']]);

        $response->assertStatus(503)
            ->assertExactJson(['message' => 'Name lookup service is temporarily unavailable.']);
        $this->assertSafeBody($response);
    }

    public static function unavailableStatuses(): array
    {
        return [
            'invalid key' => [401, ['error' => 'Invalid API key']],
            'subscription inactive' => [402, ['error' => 'Subscription is not active']],
            'quota exhausted' => [429, ['error' => 'Request limit reached']],
            'quota too low for the batch' => [429, ['error' => 'Request limit too low to process request']],
            'unexpected 4xx' => [404, ['error' => 'nope']],
            '500' => [500, ['error' => 'boom']],
            '503' => [503, []],
        ];
    }

    public function test_422_from_upstream_is_a_502(): void
    {
        Http::fake([self::URL => Http::response(['error' => "Invalid 'name' parameter"], 422)]);

        $response = $this->postJson('/nationalize', ['names' => ['Secretname']]);

        $response->assertStatus(502)
            ->assertExactJson(['message' => 'Name lookup service returned an invalid response.']);
        $this->assertSafeBody($response);
    }

    public function test_retry_after_from_upstream_is_passed_through_on_429(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'Request limit reached'], 429, ['Retry-After' => '120'])]);

        $this->postJson('/nationalize', ['names' => ['Secretname']])
            ->assertStatus(503)
            ->assertHeader('Retry-After', '120');
    }

    #[DataProvider('neverRetried')]
    public function test_client_errors_and_rate_limits_are_never_retried(int $status): void
    {
        Http::fake([self::URL => Http::response(['error' => 'x'], $status)]);

        $this->postJson('/nationalize', ['names' => ['Secretname']]);

        Http::assertSentCount(1);
    }

    public static function neverRetried(): array
    {
        return ['401' => [401], '402' => [402], '404' => [404], '422' => [422], '429' => [429]];
    }

    public function test_a_server_error_is_retried_once_and_can_recover(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push([$this->record('Mario', 'IT')], 200)]);

        $this->postJson('/nationalize', ['names' => ['Mario']])
            ->assertOk()
            ->assertJsonPath('results.0.most_probable_country', 'IT');

        Http::assertSentCount(2);
    }

    public function test_a_persistent_server_error_is_tried_exactly_twice(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'boom'], 500)]);

        $this->postJson('/nationalize', ['names' => ['Secretname']])->assertStatus(503);

        Http::assertSentCount(2);
    }

    public function test_connection_failures_are_retried_once_then_503_without_the_url_or_key(): void
    {
        $attempts = 0;
        Http::fake([self::URL => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException(
                'cURL error 28: timed out for https://api.nationalize.io/?name[]=Secretname&apikey=test-key-not-real'
            );
        }]);

        $response = $this->postJson('/nationalize', ['names' => ['Secretname']]);

        $response->assertStatus(503);
        $this->assertSafeBody($response);
        $this->assertSame(2, $attempts);
    }

    public function test_a_result_count_mismatch_is_a_502(): void
    {
        Http::fake([self::URL => Http::response([$this->record('nguyen')])]);

        $response = $this->postJson('/nationalize', ['names' => ['nguyen', 'schmidt']]);

        $response->assertStatus(502);
        $this->assertSafeBody($response);
    }

    #[DataProvider('malformedPayloads')]
    public function test_malformed_payloads_are_a_502(mixed $payload): void
    {
        Http::fake([self::URL => Http::response($payload)]);

        $response = $this->postJson('/nationalize', ['names' => ['Secretname']]);

        $response->assertStatus(502);
        $this->assertSafeBody($response);
    }

    public static function malformedPayloads(): array
    {
        return [
            'empty array' => [[]],
            'record without country' => [[['count' => 1, 'name' => 'x']]],
            'country not a list' => [[['count' => 1, 'name' => 'x', 'country' => 'US']]],
            'country entry without id' => [[['count' => 1, 'name' => 'x', 'country' => [['probability' => 0.5]]]]],
            'probability not numeric' => [[['count' => 1, 'name' => 'x', 'country' => [['country_id' => 'US', 'probability' => 'high']]]]],
            'probability out of range' => [[['count' => 1, 'name' => 'x', 'country' => [['country_id' => 'US', 'probability' => 1.5]]]]],
            'record is a string' => [['oops']],
            'plain string body' => ['not json at all'],
        ];
    }

    public function test_a_bare_object_for_a_single_name_is_accepted(): void
    {
        Http::fake([self::URL => Http::response($this->record('Mario', 'IT'))]);

        $this->postJson('/nationalize', ['names' => ['Mario']])
            ->assertOk()
            ->assertJsonPath('results.0.most_probable_country', 'IT');
    }

    public function test_a_failed_batch_fails_the_whole_request(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'boom'], 500)]);

        $this->postJson('/nationalize', ['names' => ['nguyen', 'schmidt']])
            ->assertStatus(503)
            ->assertJsonMissingPath('results');
    }
}
