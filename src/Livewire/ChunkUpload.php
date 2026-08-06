<?php

declare(strict_types=1);

namespace NETipar\Chunky\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use NETipar\Chunky\Config\ChunkyConfig;

/**
 * A thin Livewire wrapper. It carries the profile/config to the blade view,
 * which mounts the framework-agnostic core JS — the actual chunk protocol lives
 * in @netipar/chunky-core, not here.
 */
class ChunkUpload extends Component
{
    public ?string $profile = null;

    public function render(): View
    {
        $config = app(ChunkyConfig::class);

        return view('chunky::livewire.chunk-upload', [
            'profile' => $this->profile,
            'baseUrl' => $config->routesPrefix,
        ]);
    }
}
