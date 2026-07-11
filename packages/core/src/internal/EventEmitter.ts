/**
 * The single event primitive used by Uploader, Batch, and UploadManager.
 *
 * Sticky replay: the last payload of a "sticky" event is re-delivered to any
 * listener that subscribes after it fired. This lets a framework wrapper
 * unmount and resubscribe without missing a terminal event (completed/failed).
 *
 * The constraint keeps `keyof Events` precise (no string index signature) while
 * requiring every event value to be a listener function.
 */
export class EventEmitter<Events extends { [K in keyof Events]: (...args: never[]) => void }> {
    private listeners: { [K in keyof Events]?: Set<Events[K]> } = {};

    private sticky = new Set<keyof Events>();

    private lastPayload: { [K in keyof Events]?: Parameters<Events[K]> } = {};

    constructor(stickyEvents: (keyof Events)[] = []) {
        for (const event of stickyEvents) {
            this.sticky.add(event);
        }
    }

    on<K extends keyof Events>(event: K, listener: Events[K]): () => void {
        const set = (this.listeners[event] ??= new Set());
        set.add(listener);

        if (this.sticky.has(event) && event in this.lastPayload) {
            const payload = this.lastPayload[event];
            if (payload) {
                listener(...payload);
            }
        }

        return () => {
            set.delete(listener);
        };
    }

    emit<K extends keyof Events>(event: K, ...args: Parameters<Events[K]>): void {
        if (this.sticky.has(event)) {
            this.lastPayload[event] = args;
        }

        const set = this.listeners[event];
        if (!set) {
            return;
        }

        for (const listener of [...set]) {
            listener(...args);
        }
    }

    clear(): void {
        this.listeners = {};
        this.lastPayload = {};
    }
}
