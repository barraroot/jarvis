import type { VoiceTurn } from '@/types/models';

/** Flush cadence of the buffered turns (D2). */
export const TURN_FLUSH_INTERVAL_MS = 5000;

/** Maximum turns per CT-10 request (D2). */
export const TURN_BUFFER_MAX = 20;

export interface TurnBufferOptions {
    /** Persists a batch (CT-10); resolving `false` keeps the batch for the next flush. */
    flush(turns: VoiceTurn[]): Promise<boolean | void>;
    intervalMs?: number;
    maxTurns?: number;
}

/**
 * Collects transcribed voice turns and persists them in order (RF-21): 5 s
 * after the first pending turn, as soon as 20 are pending, or on close.
 */
export class TurnBuffer {
    private pending: VoiceTurn[] = [];
    private timer: ReturnType<typeof setTimeout> | null = null;
    private inFlight: Promise<void> = Promise.resolve();
    private readonly intervalMs: number;
    private readonly maxTurns: number;

    constructor(private readonly options: TurnBufferOptions) {
        this.intervalMs = options.intervalMs ?? TURN_FLUSH_INTERVAL_MS;
        this.maxTurns = options.maxTurns ?? TURN_BUFFER_MAX;
    }

    get size(): number {
        return this.pending.length;
    }

    push(turn: VoiceTurn): void {
        if (turn.content.trim() === '') {
            return;
        }

        this.pending.push(turn);

        if (this.pending.length >= this.maxTurns) {
            void this.flush();

            return;
        }

        this.timer ??= setTimeout(() => {
            this.timer = null;
            void this.flush();
        }, this.intervalMs);
    }

    /** Sends every pending turn, `maxTurns` at a time, after any flush already running. */
    flush(): Promise<void> {
        this.clearTimer();

        this.inFlight = this.inFlight.then(async () => {
            while (this.pending.length > 0) {
                const batch = this.pending.splice(0, this.maxTurns);
                const result = await this.options.flush(batch).catch(() => false);

                if (result === false) {
                    this.pending.unshift(...batch);

                    return;
                }
            }
        });

        return this.inFlight;
    }

    /** Final flush when the voice session closes. */
    close(): Promise<void> {
        return this.flush();
    }

    private clearTimer(): void {
        if (this.timer !== null) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }
}
