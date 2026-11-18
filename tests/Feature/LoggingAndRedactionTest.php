<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesNationalize;
use Tests\Support\FailingCacheStore;
use Tests\TestCase;

/**
 * Spec FR-9 / 4.4 / 7: submitted names, the API key and the upstream URL (which carries the
 * key) must never reach the logs or any response body, on success or on any failure path.
 */
class LoggingAndRedactionTest extends TestCase
{
    use FakesNationalize;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();

        $this->logFile = tempnam(sys_get_temp_dir(), 'nationalize-log-');
        config([
            'logging.default' => 'capture',
            'logging.channels.capture' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug'],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    private function logged(): string
    {
        return (string) file_get_contents($this->logFile);
    }

    private function assertNothingSensitive(string $text): void
    {
        foreach (['Secretname', 'secretname', 'test-key-not-real', 'api.nationalize.io', 'apikey'] as $needle) {
            $this->assertStringNotContainsString($needle, $text, "'$needle' leaked");
        }
    }

    public function test_a_successful_request_logs_nothing_sensitive(): void
    {
        $this->fakeNationalize();

        $response = $this->postJson('/nationalize', ['names' => ['Secretname']])->assertOk();

        $this->assertNothingSensitive($this->logged());
        $this->assertStringNotContainsString('test-key-not-real', $response->getContent());
    }

    public function test_every_failure_path_logs_and_responds_without_names_keys_or_urls(): void
    {
        $failures = [
            'connection' => fn () => throw new ConnectionException(
                'cURL error 6: could not resolve https://api.nationalize.io/?name[]=Secretname&apikey=test-key-not-real'
            ),
            '500' => fn () => Http::response(['error' => 'Secretname exploded'], 500),
            '401' => fn () => Http::response(['error' => 'Invalid API key test-key-not-real'], 401),
            '429' => fn () => Http::response(['error' => 'Request limit reached'], 429),
            '422' => fn () => Http::response(['error' => "Invalid 'name' parameter: Secretname"], 422),
            'wrong count' => fn () => Http::response([]),
            'garbage' => fn () => Http::response('Secretname test-key-not-real'),
        ];

        foreach ($failures as $label => $failure) {
            Http::fake(['api.nationalize.io/*' => $failure]);
            // A fresh cache each time, otherwise the first success would answer the rest.
            $this->app['cache']->store()->flush();

            $response = $this->postJson('/nationalize', ['names' => ['Secretname']]);

            $this->assertContains($response->status(), [502, 503], $label);
            $this->assertNothingSensitive($response->getContent());
        }

        $this->assertNotSame('', $this->logged(), 'failures should be logged');
        $this->assertNothingSensitive($this->logged());
    }

    public function test_low_quota_warns_with_the_remaining_count_only(): void
    {
        Http::fake(['api.nationalize.io/*' => fn ($request) => Http::response(
            array_map(fn ($name) => ['count' => 1, 'name' => $name, 'country' => []], $this->requestedNames($request)),
            200,
            ['X-Rate-Limit-Remaining' => '3'],
        )]);

        $this->postJson('/nationalize', ['names' => ['Secretname']])->assertOk();

        $this->assertStringContainsString('Nationalize.io quota is low', $this->logged());
        $this->assertStringContainsString('"remaining":3', $this->logged());
        $this->assertNothingSensitive($this->logged());
    }

    public function test_plenty_of_quota_does_not_warn(): void
    {
        Http::fake(['api.nationalize.io/*' => fn ($request) => Http::response(
            array_map(fn ($name) => ['count' => 1, 'name' => $name, 'country' => []], $this->requestedNames($request)),
            200,
            ['X-Rate-Limit-Remaining' => '5000'],
        )]);

        $this->postJson('/nationalize', ['names' => ['Secretname']])->assertOk();

        $this->assertStringNotContainsString('quota is low', $this->logged());
    }

    public function test_a_broken_cache_logs_the_exception_class_but_not_its_message_or_names(): void
    {
        $this->fakeNationalize();
        Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore));
        config(['cache.stores.failing' => ['driver' => 'failing'], 'cache.default' => 'failing']);
        Cache::forgetDriver('failing');

        $this->postJson('/nationalize', ['names' => ['Secretname']])->assertOk();

        $this->assertStringContainsString('Name cache unavailable', $this->logged());
        $this->assertStringNotContainsString('secret-key-material', $this->logged());
        $this->assertNothingSensitive($this->logged());
    }

    public function test_validation_failures_do_not_log_the_submitted_names(): void
    {
        $this->postJson('/nationalize', ['names' => ['Secretname', '']])->assertStatus(422);

        $this->assertNothingSensitive($this->logged());
    }
}
