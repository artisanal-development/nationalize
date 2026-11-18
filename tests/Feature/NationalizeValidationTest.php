<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesNationalize;
use Tests\TestCase;

class NationalizeValidationTest extends TestCase
{
    use FakesNationalize;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeNationalize();
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_bodies_are_rejected_with_422(array $body): void
    {
        $this->postJson('/nationalize', $body)
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    public static function invalidBodies(): array
    {
        return [
            'missing names' => [[]],
            'names null' => [['names' => null]],
            'names is a string' => [['names' => 'Mario']],
            'names is a number' => [['names' => 5]],
            'names is a JSON object' => [['names' => ['a' => 'Mario']]],
            'zero names' => [['names' => []]],
            'six names' => [['names' => ['a', 'b', 'c', 'd', 'e', 'f']]],
            'empty string' => [['names' => ['']]],
            'whitespace only' => [['names' => ['   ']]],
            'tabs and newlines only' => [['names' => ["\t\n "]]],
            'integer item' => [['names' => [123]]],
            'float item' => [['names' => [1.5]]],
            'null item' => [['names' => [null]]],
            'boolean item' => [['names' => [true]]],
            'array item' => [['names' => [['Mario']]]],
            'object item' => [['names' => [['a' => 'Mario']]]],
            'valid item next to invalid one' => [['names' => ['Mario', 7]]],
            'valid item next to blank one' => [['names' => ['Mario', ' ']]],
            '51 characters' => [['names' => [str_repeat('a', 51)]]],
            '51 multibyte characters' => [['names' => [str_repeat('李', 51)]]],
        ];
    }

    #[DataProvider('validBodies')]
    public function test_valid_bodies_are_accepted(array $body): void
    {
        $this->postJson('/nationalize', $body)->assertOk();
    }

    public static function validBodies(): array
    {
        return [
            'one name' => [['names' => ['Mario']]],
            'five names' => [['names' => ['a', 'b', 'c', 'd', 'e']]],
            'duplicates' => [['names' => ['Mario', 'Mario']]],
            'exactly 50 characters' => [['names' => [str_repeat('a', 50)]]],
            '50 multibyte characters' => [['names' => [str_repeat('李', 50)]]],
            'accented' => [['names' => ["N\u{00FA}\u{00F1}ez"]]],
            'cjk' => [['names' => ['李']]],
            'name with spaces' => [['names' => ['  de la Cruz ']]],
            'numeric-looking string' => [['names' => ['123']]],
        ];
    }

    public function test_fifty_characters_are_counted_after_normalization(): void
    {
        // 50 letters padded with spaces and a doubled inner space are still 50 characters.
        $name = '  '.str_repeat('a', 25).'  '.str_repeat('b', 24).'  ';

        $this->postJson('/nationalize', ['names' => [$name]])->assertOk();
    }

    public function test_malformed_json_returns_400_not_422(): void
    {
        $this->call('POST', '/nationalize', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{"names": ["Mario"')
            ->assertStatus(400)
            ->assertExactJson(['message' => 'Malformed JSON.']);
    }

    public function test_empty_body_returns_422(): void
    {
        $this->call('POST', '/nationalize', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '')
            ->assertStatus(422);
    }

    public function test_validation_errors_do_not_echo_names_beyond_field_messages(): void
    {
        $response = $this->postJson('/nationalize', ['names' => ['Mario', '']]);

        $response->assertStatus(422)->assertJsonValidationErrors(['names.1']);
        $this->assertStringNotContainsString('Mario', $response->getContent());
    }
}
