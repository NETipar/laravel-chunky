import { describe, expect, it, vi } from 'vitest';
import { EventEmitter } from './EventEmitter';

interface Events extends Record<string, (...args: never[]) => void> {
    foo: (n: number) => void;
    done: (s: string) => void;
}

describe('EventEmitter', () => {
    it('delivers events to listeners', () => {
        const emitter = new EventEmitter<Events>();
        const listener = vi.fn();
        emitter.on('foo', listener);

        emitter.emit('foo', 42);

        expect(listener).toHaveBeenCalledWith(42);
    });

    it('replays the last sticky event to late subscribers', () => {
        const emitter = new EventEmitter<Events>(['done']);
        emitter.emit('done', 'ok');

        const listener = vi.fn();
        emitter.on('done', listener);

        expect(listener).toHaveBeenCalledWith('ok');
    });

    it('does not replay non-sticky events', () => {
        const emitter = new EventEmitter<Events>(['done']);
        emitter.emit('foo', 1);

        const listener = vi.fn();
        emitter.on('foo', listener);

        expect(listener).not.toHaveBeenCalled();
    });

    it('stops delivering after unsubscribe', () => {
        const emitter = new EventEmitter<Events>();
        const listener = vi.fn();
        const off = emitter.on('foo', listener);

        off();
        emitter.emit('foo', 1);

        expect(listener).not.toHaveBeenCalled();
    });
});
