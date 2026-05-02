/**
 * Tiny typed event emitter shared between ChunkUploader and
 * BatchUploader. The two used to duplicate the same `listeners` Map
 * + sticky-replay + late-subscribe-guard logic; this module is the
 * single source of truth.
 *
 * The emitter is generic over an event-map. Each event name is a key
 * of the map and the payload type is the corresponding value, mirroring
 * the on/emit overloads the public Uploader classes expose.
 *
 * Sticky replay: when `setSticky(event, payload)` has been called and a
 * new listener subscribes to that event, the cached payload is
 * delivered in a microtask. The microtask first checks that the
 * listener is still registered, so a synchronous unsubscribe before
 * the microtask drains (typical `useEffect` cleanup) does NOT fire
 * the callback after `unsub()` returns.
 *
 * @internal — not part of the public package API.
 */
type AnyCallback = (payload: unknown) => void;

export class EventEmitter<EventMap extends Record<string, unknown>> {
    private listeners = new Map<keyof EventMap, Set<AnyCallback>>();
    private sticky = new Map<keyof EventMap, unknown>();

    on<K extends keyof EventMap>(
        event: K,
        callback: (payload: EventMap[K]) => void,
    ): () => void {
        if (!this.listeners.has(event)) {
            this.listeners.set(event, new Set());
        }

        const set = this.listeners.get(event)!;
        const stored = callback as AnyCallback;
        set.add(stored);

        if (this.sticky.has(event)) {
            const payload = this.sticky.get(event) as EventMap[K];

            queueMicrotask(() => {
                if (set.has(stored)) {
                    callback(payload);
                }
            });
        }

        return () => {
            set.delete(stored);
        };
    }

    emit<K extends keyof EventMap>(event: K, payload: EventMap[K]): void {
        this.listeners.get(event)?.forEach((cb) => cb(payload));
    }

    setSticky<K extends keyof EventMap>(event: K, payload: EventMap[K]): void {
        this.sticky.set(event, payload);
    }

    clearSticky(event?: keyof EventMap): void {
        if (event === undefined) {
            this.sticky.clear();
            return;
        }
        this.sticky.delete(event);
    }

    clear(): void {
        this.listeners.clear();
        this.sticky.clear();
    }
}
