<?php

namespace App\Http\Requests;

use App\Support\NameNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class NationalizeRequest extends FormRequest
{
    /** @var array<int, string> trimmed input as sent, used for the response `name` */
    private array $displayNames = [];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // `array` alone also accepts JSON objects; `list` requires a plain JSON array.
            'names' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'names.*' => ['required', 'string', 'max:50'],
        ];
    }

    /**
     * Normalize string items only, so numbers, nulls, booleans and nested arrays still
     * fail the `string` rule instead of being coerced.
     */
    protected function prepareForValidation(): void
    {
        $names = $this->input('names');

        if (! is_array($names)) {
            return;
        }

        $this->displayNames = array_values(array_map(
            fn ($name) => is_string($name) ? NameNormalizer::trim($name) : '',
            $names,
        ));

        $this->merge([
            'names' => array_map(
                fn ($name) => is_string($name) ? NameNormalizer::normalize($name) : $name,
                $names,
            ),
        ]);
    }

    /** @return array<int, string> normalized names, in input order */
    public function normalizedNames(): array
    {
        return array_values($this->validated('names'));
    }

    /** @return array<int, string> trimmed names as the caller sent them, in input order */
    public function displayNames(): array
    {
        return $this->displayNames;
    }
}
