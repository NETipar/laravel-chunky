<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use NETipar\Chunky\Config\ChunkyConfig;
use NETipar\Chunky\Http\ErrorCode;
use NETipar\Chunky\Profiles\UploadProfile;

abstract class AbstractChunkyRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(new JsonResponse([
            'error' => [
                'code' => ErrorCode::ValidationFailed->value,
                'message' => ErrorCode::ValidationFailed->message(),
            ],
            'errors' => $validator->errors()->toArray(),
        ], ErrorCode::ValidationFailed->status()));
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(new JsonResponse([
            'error' => [
                'code' => ErrorCode::Unauthorized->value,
                'message' => ErrorCode::Unauthorized->message(),
            ],
        ], ErrorCode::Unauthorized->status()));
    }

    /**
     * Base initiate rules, merged with the given profile's rules.
     *
     * @return array<string, mixed>
     */
    protected function initiateRules(?UploadProfile $profile): array
    {
        $config = app(ChunkyConfig::class);
        $maxFileSize = $profile?->maxFileSize() ?? $config->maxFileSize;

        $fileSizeRules = ['required', 'integer', 'min:1'];
        if ($maxFileSize > 0) {
            $fileSizeRules[] = 'max:'.$maxFileSize;
        }

        $rules = [
            'file_name' => ['required', 'string', 'max:255', $this->safeFileNameRule()],
            'file_size' => $fileSizeRules,
            'mime_type' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array', 'max:'.$config->metadataMaxKeys],
            'profile' => ['nullable', 'string'],
            'fingerprint' => ['nullable', 'string', 'max:255'],
        ];

        return $this->mergeProfileRules($rules, $profile);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function mergeProfileRules(array $rules, ?UploadProfile $profile): array
    {
        if ($profile === null) {
            return $rules;
        }

        foreach ($profile->rules() as $field => $fieldRules) {
            $existing = $rules[$field] ?? [];
            $rules[$field] = array_merge(
                is_array($existing) ? $existing : [$existing],
                is_array($fieldRules) ? $fieldRules : [$fieldRules],
            );
        }

        return $rules;
    }

    private function safeFileNameRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                $fail('The file name is invalid.');

                return;
            }

            $isTraversal = preg_match('#[/\\\\\x00]#', $value) === 1;

            if ($isTraversal || $value === '.' || $value === '..' || trim($value) === '') {
                $fail('The file name is invalid.');
            }
        };
    }
}
