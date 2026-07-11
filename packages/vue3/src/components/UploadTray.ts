import { defineComponent, h } from 'vue';
import { useUploads } from '../useUploads';

/**
 * A headless tray: it renders a scoped slot per tracked upload and exposes the
 * aggregate progress. Styling is entirely the consumer's.
 */
export const UploadTray = defineComponent({
    name: 'ChunkyUploadTray',
    setup(_, { slots }) {
        const { uploads, totalProgress } = useUploads();

        return () => h(
            'div',
            { class: 'chunky-upload-tray' },
            uploads.value.map((uploader) => slots.default?.({
                uploader,
                state: uploader.getState(),
                totalProgress: totalProgress.value,
            })),
        );
    },
});
