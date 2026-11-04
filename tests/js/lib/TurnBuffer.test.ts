import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { TURN_BUFFER_MAX, TURN_FLUSH_INTERVAL_MS, TurnBuffer } from '@/lib/voice/TurnBuffer';
import type { VoiceTurn } from '@/types/models';

function turn(content: string): VoiceTurn {
    return { role: 'user', content, spoken_at: '2026-11-04T10:00:00Z' };
}

describe('TurnBuffer', () => {
    it('flushes 5 s after the first pending turn', async () => {
        vi.useFakeTimers();
        const flush = vi.fn(async () => true);
        const buffer = new TurnBuffer({ flush });

        buffer.push(turn('um'));
        buffer.push(turn('dois'));
        vi.advanceTimersByTime(TURN_FLUSH_INTERVAL_MS - 1);
        expect(flush).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        await flushPromises();

        expect(flush).toHaveBeenCalledWith([turn('um'), turn('dois')]);
        expect(buffer.size).toBe(0);
    });

    it('flushes immediately when 20 turns are pending', async () => {
        vi.useFakeTimers();
        const flush = vi.fn(async () => true);
        const buffer = new TurnBuffer({ flush });

        for (let index = 0; index < TURN_BUFFER_MAX; index++) {
            buffer.push(turn(`t${index}`));
        }
        await flushPromises();

        expect(flush).toHaveBeenCalledTimes(1);
        expect((flush.mock.calls[0] as unknown as [VoiceTurn[]])[0]).toHaveLength(TURN_BUFFER_MAX);
    });

    it('close flushes the remaining turns in order', async () => {
        const flush = vi.fn(async () => true);
        const buffer = new TurnBuffer({ flush });

        buffer.push(turn('a'));
        buffer.push(turn('b'));
        await buffer.close();

        expect(flush).toHaveBeenCalledWith([turn('a'), turn('b')]);
    });

    it('keeps a failed batch for the next flush', async () => {
        const flush = vi.fn(async () => false).mockResolvedValueOnce(false).mockResolvedValueOnce(true);
        const buffer = new TurnBuffer({ flush });

        buffer.push(turn('a'));
        await buffer.flush();
        expect(buffer.size).toBe(1);

        await buffer.flush();
        expect(buffer.size).toBe(0);
        expect(flush).toHaveBeenLastCalledWith([turn('a')]);
    });

    it('ignores empty turns', async () => {
        const flush = vi.fn(async () => true);
        const buffer = new TurnBuffer({ flush });

        buffer.push(turn('  '));
        await buffer.close();

        expect(flush).not.toHaveBeenCalled();
    });
});
