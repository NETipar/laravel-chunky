{{--
    Headless Livewire + Alpine upload widget. Register the Alpine component once
    in your bundle:

        import { registerChunky } from '@netipar/chunky-alpine';
        Alpine.plugin((Alpine) => registerChunky(Alpine, {
            baseUrl: '/{{ $baseUrl }}',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' },
        }));

    The chunk protocol runs entirely in the core JS; this view is just markup.
--}}
<div
    x-data="chunkyUpload({ profile: @js($profile) })"
    class="chunky-upload"
>
    <input type="file" @change="onFileChange($event)" />

    <template x-if="state">
        <div class="chunky-upload__progress">
            <span x-text="state.status"></span>
            <progress :value="state.progress" max="100"></progress>
            <span x-text="`${state.progress}%`"></span>
        </div>
    </template>
</div>
