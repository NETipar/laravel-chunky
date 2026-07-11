<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Requests;

use NETipar\Chunky\Config\ChunkyConfig;
use NETipar\Chunky\Profiles\ProfileRegistry;
use NETipar\Chunky\Support\Coerce;

class InitiateBatchRequest extends AbstractChunkyRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Validates that a named profile actually exists (throws 422 otherwise).
        app(ProfileRegistry::class)->resolve(Coerce::toNullableString($this->input('profile')));

        $config = app(ChunkyConfig::class);

        return [
            'total_files' => ['required', 'integer', 'min:1'],
            'profile' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array', 'max:'.$config->metadataMaxKeys],
        ];
    }
}
