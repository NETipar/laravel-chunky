<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Requests;

use NETipar\Chunky\Profiles\ProfileRegistry;
use NETipar\Chunky\Support\Coerce;

class InitiateUploadRequest extends AbstractChunkyRequest
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
        $profileName = Coerce::toNullableString($this->input('profile'));
        $profile = app(ProfileRegistry::class)->resolve($profileName);

        return $this->initiateRules($profile);
    }
}
