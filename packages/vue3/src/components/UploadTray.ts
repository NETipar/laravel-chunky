import { defineComponent, h } from 'vue';
import { useUploads } from '../useUploads';

/**
 * A headless tray: it renders a scoped slot per tracked upload and exposes the
 * aggregate progress. Styling is entirely the consumer's. Set `preview` to
 * also expose an object-URL image preview per upload (off by default — the
 * object URL is only created when opted in).
 */
export const UploadTray = defineComponent({
    name: 'ChunkyUploadTray',
    props: {
        preview: { type: Boolean, default: false },
    },
    setup(props, { slots }) {
        const { uploads, totalProgress } = useUploads();

        return () => h(
            'div',
            { class: 'chunky-upload-tray' },
            uploads.value.map((uploader) => slots.default?.({
                uploader,
                state: uploader.getState(),
                totalProgress: totalProgress.value,
                previewUrl: props.preview ? uploader.previewUrl() : null,
            })),
        );
    },
});
