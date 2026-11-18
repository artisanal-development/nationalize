<?php

namespace Tests\Unit;

use App\Support\NameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameNormalizerTest extends TestCase
{
    #[DataProvider('normalizations')]
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, NameNormalizer::normalize($input));
    }

    public static function normalizations(): array
    {
        return [
            'plain' => ['Mario', 'Mario'],
            'leading and trailing spaces' => ['  Mario  ', 'Mario'],
            'tabs and newlines at the edges' => ["\t\nMario\r\n", 'Mario'],
            'no-break space at the edges' => ["\u{00A0}Mario\u{00A0}", 'Mario'],
            'internal runs collapse' => ['de   la  Cruz', 'de la Cruz'],
            'internal tab becomes a space' => ["de\tla Cruz", 'de la Cruz'],
            'decomposed e-acute becomes composed' => ["Jose\u{0301}", "Jos\u{00E9}"],
            'composed stays composed' => ["Jos\u{00E9}", "Jos\u{00E9}"],
            'case is preserved' => ['MARIO', 'MARIO'],
            'cjk' => ['李', '李'],
        ];
    }

    public function test_cache_key_ignores_case_and_normalization_but_not_the_name(): void
    {
        $key = NameNormalizer::cacheKey(NameNormalizer::normalize('Mario'));

        $this->assertSame($key, NameNormalizer::cacheKey(NameNormalizer::normalize('  mario ')));
        $this->assertSame($key, NameNormalizer::cacheKey(NameNormalizer::normalize('MARIO')));
        $this->assertNotSame($key, NameNormalizer::cacheKey(NameNormalizer::normalize('Maria')));
        $this->assertSame(
            NameNormalizer::cacheKey(NameNormalizer::normalize("Jose\u{0301}")),
            NameNormalizer::cacheKey(NameNormalizer::normalize("Jos\u{00E9}")),
        );
        $this->assertSame(
            NameNormalizer::cacheKey(NameNormalizer::normalize("\u{00C9}mile")),
            NameNormalizer::cacheKey(NameNormalizer::normalize("\u{00E9}mile")),
        );
    }

    public function test_cache_key_is_memcached_safe(): void
    {
        $key = NameNormalizer::cacheKey(NameNormalizer::normalize('de la Cruz '.str_repeat('x', 40)));

        $this->assertMatchesRegularExpression('/^nationalize:v1:[0-9a-f]{40}$/', $key);
    }
}
