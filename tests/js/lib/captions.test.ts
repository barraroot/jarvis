import { describe, expect, it } from 'vitest';
import { buildCaption, CaptionSync, spokenOffset, splitWords, tokenizeCaption } from '@/lib/voice/captions';

describe('captions', () => {
    it('turns emotion markup into an upper-case tag without brackets', () => {
        const tokens = tokenizeCaption('Um programador entra no bar [risos]');

        expect(tokens.map((token) => [token.kind, token.text])).toEqual([
            ['word', 'Um'],
            ['word', 'programador'],
            ['word', 'entra'],
            ['word', 'no'],
            ['word', 'bar'],
            ['tag', 'RISOS'],
        ]);
    });

    it('keeps multi-word tags together and ignores brackets that are not emotion markup', () => {
        expect(tokenizeCaption('[risos altos] veja [1] isto').map((token) => [token.kind, token.text])).toEqual([
            ['tag', 'RISOS ALTOS'],
            ['word', 'veja'],
            ['word', '[1]'],
            ['word', 'isto'],
        ]);
    });

    it('keeps markup-looking text as plain words', () => {
        expect(tokenizeCaption('<script>alert(1)</script>').map((token) => token.text)).toEqual(['<script>alert(1)</script>']);
    });

    it('marks the first N whitespace words as spoken, tags included', () => {
        const text = 'Olá [risos] tudo bem';

        expect(spokenOffset(text, 0)).toBe(0);
        expect(spokenOffset(text, 2)).toBe('Olá [risos]'.length);
        expect(buildCaption(text, 2).map((token) => token.spoken)).toEqual([true, true, false, false]);
        expect(buildCaption(text, 99).every((token) => token.spoken)).toBe(true);
    });

    it('counts words like the voice store does', () => {
        expect(splitWords('  Pode   falar. ')).toEqual(['Pode', 'falar.']);
    });

    describe('CaptionSync', () => {
        function clock() {
            let now = 0;

            return {
                now: () => now,
                advance: (ms: number) => {
                    now += ms;
                },
            };
        }

        it('reveals nothing before the audio starts', () => {
            const time = clock();
            const sync = new CaptionSync(100, time.now);

            sync.addWords(3);
            time.advance(500);

            expect(sync.spokenCount()).toBe(0);
        });

        it('spreads the words of each delta over the played audio', () => {
            const time = clock();
            const sync = new CaptionSync(100, time.now);

            sync.addWords(3);
            sync.start();
            expect(sync.spokenCount()).toBe(1);

            time.advance(100);
            expect(sync.spokenCount()).toBe(2);

            sync.addWords(2);
            time.advance(100);
            expect(sync.spokenCount()).toBe(3);

            time.advance(200);
            expect(sync.spokenCount()).toBe(5);
        });

        it('does not reveal a late delta before it arrives', () => {
            const time = clock();
            const sync = new CaptionSync(100, time.now);

            sync.addWords(1);
            sync.start();
            time.advance(1000);
            sync.addWords(2);

            expect(sync.spokenCount()).toBe(2);
            time.advance(100);
            expect(sync.spokenCount()).toBe(3);
        });

        it('reveals everything when the audio finishes and clears on reset', () => {
            const time = clock();
            const sync = new CaptionSync(100, time.now);

            sync.addWords(4);
            sync.start();
            sync.finish();
            expect(sync.spokenCount()).toBe(4);

            sync.reset();
            expect(sync.wordCount).toBe(0);
            expect(sync.spokenCount()).toBe(0);
        });
    });
});
