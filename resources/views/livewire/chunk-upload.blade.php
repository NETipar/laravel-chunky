<div
    x-data="chunkUpload({{ json_encode($this->alpineOptions) }})"
    x-on:chunky:complete.window="$wire.set('uploadId', $event.detail.uploadId); $wire.completeUpload()"
>
    {{ $slot }}

    @if(! $slot->isNotEmpty())
        <div class="chunky-upload">
            <template x-if="!isUploading && !isComplete">
                <label class="chunky-upload__dropzone">
                    <input
                        type="file"
                        class="sr-only"
                        x-on:change="handleFileInput($event)"
                    >
                    <span>Click or drag a file to upload</span>
                </label>
            </template>

            <template x-if="isUploading">
                <div class="chunky-upload__progress">
                    <div class="chunky-upload__bar">
                        <div
                            class="chunky-upload__fill"
                            x-bind:style="'width: ' + progress + '%'"
                        ></div>
                    </div>
                    <span x-text="Math.round(progress) + '%'"></span>
                    <div class="chunky-upload__actions">
                        <button type="button" x-on:click="isPaused ? resume() : pause()" x-text="isPaused ? 'Resume' : 'Pause'"></button>
                        <button type="button" x-on:click="cancel()">Cancel</button>
                    </div>
                </div>
            </template>

            <template x-if="isComplete">
                <div class="chunky-upload__complete">
                    <span>Upload complete!</span>
                </div>
            </template>

            <template x-if="error">
                <div class="chunky-upload__error">
                    <span x-text="error"></span>
                    <button type="button" x-on:click="retry()">Retry</button>
                </div>
            </template>
        </div>
    @endif
</div>
