export declare class EventEmitter<EventMap extends Record<string, unknown>> {
    private listeners;
    private sticky;
    on<K extends keyof EventMap>(event: K, callback: (payload: EventMap[K]) => void): () => void;
    emit<K extends keyof EventMap>(event: K, payload: EventMap[K]): void;
    setSticky<K extends keyof EventMap>(event: K, payload: EventMap[K]): void;
    clearSticky(event?: keyof EventMap): void;
    clear(): void;
}
