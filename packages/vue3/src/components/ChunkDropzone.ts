import { defineComponent, h, shallowRef } from 'vue';
import type { Uploader } from '@netipar/chunky-core';
import { useManager } from '../manager';

/**
 * A minimal drop target + file input that starts an upload per selected file.
 * Emits `uploading` with each started Uploader; the default slot renders below
 * and receives the uploads started here. Set `preview` to also receive an
 * object-URL image preview per upload (off by default — the object URL is only
 * created when opted in).
 */
export const ChunkDropzone = defineComponent({
    name: 'ChunkyDropzone',
    props: {
        profile: { type: String, default: undefined },
        multiple: { type: Boolean, default: false },
        preview: { type: Boolean, default: false },
    },
    emits: {
        uploading: (_uploader: Uploader) => true,
    },
    setup(props, { emit, slots }) {
        const manager = useManager();
        const started = shallowRef<readonly Uploader[]>([]);

        const startFiles = (files: FileList | null): void => {
            if (!files) {
                return;
            }

            for (const file of Array.from(files)) {
                const uploader = manager.upload(file, { profile: props.profile });
                started.value = [...started.value, uploader];
                emit('uploading', uploader);
            }
        };

        return () => h('div', {
            class: 'chunky-dropzone',
            onDragover: (event: DragEvent) => event.preventDefault(),
            onDrop: (event: DragEvent) => {
                event.preventDefault();
                startFiles(event.dataTransfer?.files ?? null);
            },
        }, [
            h('input', {
                type: 'file',
                multiple: props.multiple,
                onChange: (event: Event) => startFiles((event.target as HTMLInputElement).files),
            }),
            slots.default?.({
                uploads: started.value,
                previews: props.preview
                    ? started.value.map((uploader) => ({ uploader, url: uploader.previewUrl() }))
                    : [],
            }),
        ]);
    },
});
