/**
 * Audio level meters for the MIC and VOZ gauges (UI-06): one `AnalyserNode`
 * per stream, read on demand as a 0–1 RMS level.
 */

/** Gain applied to the raw RMS so normal speech fills most of the gauge. */
export const LEVEL_GAIN = 3;

const ANALYSER_FFT_SIZE = 512;

export interface LevelMeter {
    /** Current level, 0 (silence) to 1 (loud). */
    read(): number;
    dispose(): void;
}

/** RMS of 8-bit time-domain samples (128 = silence), scaled by {@link LEVEL_GAIN} and clamped to 0–1. */
export function rmsLevel(samples: ArrayLike<number>): number {
    if (samples.length === 0) {
        return 0;
    }

    let sum = 0;

    for (let index = 0; index < samples.length; index++) {
        const centered = ((samples[index] ?? 128) - 128) / 128;

        sum += centered * centered;
    }

    return Math.min(1, Math.sqrt(sum / samples.length) * LEVEL_GAIN);
}

/** A meter that always reads 0, used before a stream exists or without Web Audio. */
export const SILENT_METER: LevelMeter = {
    read: () => 0,
    dispose: () => undefined,
};

/** Taps `stream` with an analyser (not connected to the speakers, so nothing is played twice). */
export function createLevelMeter(context: AudioContext, stream: MediaStream): LevelMeter {
    const source = context.createMediaStreamSource(stream);
    const analyser = context.createAnalyser();
    const samples = new Uint8Array(ANALYSER_FFT_SIZE);

    analyser.fftSize = ANALYSER_FFT_SIZE;
    source.connect(analyser);

    return {
        read(): number {
            analyser.getByteTimeDomainData(samples);

            return rmsLevel(samples);
        },
        dispose(): void {
            source.disconnect();
            analyser.disconnect();
        },
    };
}
