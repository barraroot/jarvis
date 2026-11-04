/** Average speaking pace used to spread a delta's words over the played audio (UI-07). */
export const CAPTION_MS_PER_WORD = 330;

/** Emotion markup such as `[risos]` (UI-08): letters and spaces, up to 20 chars. */
const EMOTION_TAG = /\[([\p{L} ]{1,20})\]/gu;

export interface CaptionToken {
    kind: 'word' | 'tag';
    /** Words verbatim; tags upper-cased and without brackets (`RISOS`). */
    text: string;
    /** Character offsets in the source text. */
    start: number;
    end: number;
}

export interface CaptionDisplayToken extends CaptionToken {
    spoken: boolean;
}

/** Whitespace-delimited words, the same unit counted by `spokenWordCount`. */
export function splitWords(text: string): string[] {
    return text.split(/\s+/).filter((word) => word !== '');
}

function pushWords(tokens: CaptionToken[], text: string, offset: number): void {
    for (const match of text.matchAll(/\S+/g)) {
        const start = offset + (match.index ?? 0);

        tokens.push({ kind: 'word', text: match[0], start, end: start + match[0].length });
    }
}

/**
 * Splits the assistant text into words and emotion tags; `[risos]` becomes
 * one `RISOS` tag token (UI-08). Output is plain text, never HTML.
 */
export function tokenizeCaption(text: string): CaptionToken[] {
    const tokens: CaptionToken[] = [];
    let cursor = 0;

    for (const match of text.matchAll(EMOTION_TAG)) {
        const start = match.index ?? 0;
        const label = (match[1] ?? '').trim();

        if (label === '') {
            continue;
        }

        pushWords(tokens, text.slice(cursor, start), cursor);
        tokens.push({ kind: 'tag', text: label.toLocaleUpperCase('pt-BR'), start, end: start + match[0].length });
        cursor = start + match[0].length;
    }

    pushWords(tokens, text.slice(cursor), cursor);

    return tokens;
}

/** Character offset where the first `count` whitespace-delimited words end. */
export function spokenOffset(text: string, count: number): number {
    if (count <= 0) {
        return 0;
    }

    let seen = 0;

    for (const match of text.matchAll(/\S+/g)) {
        seen++;

        if (seen === count) {
            return (match.index ?? 0) + match[0].length;
        }
    }

    return text.length;
}

/** Tokens with the spoken flag: anything that starts before the spoken offset is revealed (UI-07). */
export function buildCaption(text: string, spokenWordCount: number): CaptionDisplayToken[] {
    const offset = spokenOffset(text, spokenWordCount);

    return tokenizeCaption(text).map((token) => ({ ...token, spoken: token.start < offset }));
}

/**
 * Reveals the JARVIS caption word by word in sync with the audio (UI-07):
 * the words of every transcript delta are queued behind the previous ones,
 * one slot of {@link CAPTION_MS_PER_WORD} each, starting when playback
 * starts. Deltas usually arrive ahead of the audio; a late delta is revealed
 * from its arrival on.
 */
export class CaptionSync {
    private arrivals: number[] = [];
    private startedAt: number | null = null;
    private finished = false;

    constructor(
        private readonly msPerWord: number = CAPTION_MS_PER_WORD,
        private readonly now: () => number = () => performance.now(),
    ) {}

    /** A new assistant response: nothing queued, nothing played. */
    reset(): void {
        this.arrivals = [];
        this.startedAt = null;
        this.finished = false;
    }

    /** Queues `count` words that just arrived in a transcript delta. */
    addWords(count: number): void {
        const arrivedAt = this.now();

        for (let index = 0; index < count; index++) {
            this.arrivals.push(arrivedAt);
        }
    }

    /** Output audio started playing. */
    start(): void {
        if (this.startedAt === null) {
            this.startedAt = this.now();
        }
    }

    /** Output audio ended or was interrupted: every queued word counts as spoken. */
    finish(): void {
        this.finished = true;
    }

    get wordCount(): number {
        return this.arrivals.length;
    }

    /** Number of words whose slot has already been reached. */
    spokenCount(): number {
        if (this.finished) {
            return this.arrivals.length;
        }

        if (this.startedAt === null) {
            return 0;
        }

        const now = this.now();
        let slot = this.startedAt - this.msPerWord;
        let spoken = 0;

        for (const arrivedAt of this.arrivals) {
            slot = Math.max(arrivedAt, slot + this.msPerWord);

            if (slot > now) {
                break;
            }

            spoken++;
        }

        return spoken;
    }
}
