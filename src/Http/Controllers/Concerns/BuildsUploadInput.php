<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use NETipar\Chunky\Support\Coerce;

trait BuildsUploadInput
{
    /**
     * @return array<string, mixed>
     */
    protected function metadataFrom(Request $request): array
    {
        $metadata = $request->input('metadata', []);

        if (! is_array($metadata)) {
            return [];
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];
        foreach ($metadata as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    protected function nullableString(Request $request, string $key): ?string
    {
        return Coerce::toNullableString($request->input($key));
    }
}
