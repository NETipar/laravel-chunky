<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Requests;

class InitiateUploadRequest extends AbstractInitiateUploadRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->applyContextRules(
            $this->baseUploadRules(),
            $this->input('context'),
        );
    }
}
