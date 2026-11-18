<?php

namespace Tests\Feature;

use App\Support\NameNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Memcached;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\FakesNationalize;
use Tests\TestCase;

/**
 * Runs against a real memcached (the `memcached` service in compose.yaml) and is skipped
 * when none is reachable. Upstream is still faked.
 */
#[Group('memcached')]
class MemcachedIntegrationTest extends TestCase
{
    use FakesNationalize;

    protected function setUp(): void
    {
        parent::setUp();

        $host = (string) env('MEMCACHED_HOST', '127.0.0.1');
        if (! class_exists(Memcached::class)) {
            $this->markTestSkipped('The memcached extension is not installed.');
        }
        $probe = new Memcached;
        $probe->addServer($host, 11211);
        if (! $probe->set('nationalize:probe', 1, 5)) {
            $this->markTestSkipped("No memcached reachable at $host:11211.");
        }

        config(['cache.default' => 'memcached']);
        Cache::forgetDriver('memcached');
        $this->fakeNationalize();
    }

    public function test_names_with_spaces_and_unicode_are_cached_and_served_from_memcached(): void
    {
        $name = '李 de la Cruz '.bin2hex(random_bytes(4)); // unique so earlier runs cannot interfere

        $this->postJson('/nationalize', ['names' => [$name]])->assertOk();
        $this->postJson('/nationalize', ['names' => ["  $name  "]])->assertOk();

        Http::assertSentCount(1);
        $this->assertIsArray(Cache::get(NameNormalizer::cacheKey(NameNormalizer::normalize($name))));
    }

    public function test_the_entry_is_stored_under_the_hashed_key_not_the_plain_name(): void
    {
        $name = 'plain-'.bin2hex(random_bytes(4));
        $this->postJson('/nationalize', ['names' => [$name]])->assertOk();

        $memcached = new Memcached;
        $memcached->addServer((string) env('MEMCACHED_HOST', '127.0.0.1'), 11211);

        $this->assertNotFalse($memcached->get((string) config('cache.prefix').NameNormalizer::cacheKey($name)));
        $this->assertFalse($memcached->get((string) config('cache.prefix').$name));
        // The 24 h expiry itself is asserted with time travel in NationalityCacheTest.
    }
}
