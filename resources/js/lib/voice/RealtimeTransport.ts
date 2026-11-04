import { createLevelMeter, type LevelMeter, SILENT_METER } from '@/lib/voice/levels';

/**
 * Transport of the OpenAI Realtime session (T22). The session logic only
 * talks to this interface, so tests drive it with a fake and the WebRTC
 * details stay in {@link WebRtcTransport}.
 */

/** JSON event received on the `oai-events` data channel. */
export interface RealtimeServerEvent {
    type: string;
    [key: string]: unknown;
}

/** JSON event sent to the provider (`response.create`, `conversation.item.create`, ...). */
export type RealtimeClientEvent = { type: string } & Record<string, unknown>;

export type RealtimeTransportErrorCode = 'unsupported' | 'mic_denied' | 'connection';

export class RealtimeTransportError extends Error {
    constructor(
        public readonly code: RealtimeTransportErrorCode,
        message: string = code,
    ) {
        super(message);
        this.name = 'RealtimeTransportError';
    }
}

export interface RealtimeTransportHandlers {
    onEvent(event: RealtimeServerEvent): void;
    /** The connection dropped after being established. */
    onClose?(): void;
}

export interface RealtimeConnectOptions {
    /** Ephemeral credential from CT-01; the provider key never reaches the browser (RF-09). */
    clientSecret: string;
}

export interface RealtimeTransport {
    connect(options: RealtimeConnectOptions, handlers: RealtimeTransportHandlers): Promise<void>;
    send(event: RealtimeClientEvent): void;
    close(): void;
    /** Enables/disables the microphone track; output keeps playing (RF-06). */
    setMicEnabled(enabled: boolean): void;
    micLevel(): number;
    outputLevel(): number;
}

/** Default Realtime API origin; must match the CSP `connect-src` entry (RNF-08). */
export const OPENAI_REALTIME_ORIGIN = 'https://api.openai.com';

/** Data channel name required by the Realtime API. */
export const REALTIME_DATA_CHANNEL = 'oai-events';

/** True when the browser has the media and WebRTC APIs voice needs (RF-08a). */
export function isWebRtcSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.RTCPeerConnection === 'function' &&
        typeof navigator !== 'undefined' &&
        typeof navigator.mediaDevices?.getUserMedia === 'function'
    );
}

function isPermissionError(error: unknown): boolean {
    return error instanceof DOMException && (error.name === 'NotAllowedError' || error.name === 'SecurityError');
}

/**
 * WebRTC transport: microphone via `getUserMedia`, one `RTCPeerConnection`
 * with the `oai-events` data channel, SDP exchanged with
 * `<origin>/v1/realtime/calls` using the ephemeral credential, remote audio
 * played by a detached `<audio>` element and separate analysers for the MIC
 * (input) and VOZ (output) gauges.
 */
export class WebRtcTransport implements RealtimeTransport {
    private peer: RTCPeerConnection | null = null;
    private channel: RTCDataChannel | null = null;
    private microphone: MediaStream | null = null;
    private audio: HTMLAudioElement | null = null;
    private context: AudioContext | null = null;
    private inputMeter: LevelMeter = SILENT_METER;
    private outputMeter: LevelMeter = SILENT_METER;
    private closed = false;

    constructor(private readonly origin: string = OPENAI_REALTIME_ORIGIN) {}

    async connect(options: RealtimeConnectOptions, handlers: RealtimeTransportHandlers): Promise<void> {
        if (!isWebRtcSupported()) {
            throw new RealtimeTransportError('unsupported');
        }

        this.closed = false;

        try {
            this.microphone = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (error) {
            throw new RealtimeTransportError(isPermissionError(error) ? 'mic_denied' : 'connection');
        }

        try {
            await this.negotiate(options, handlers);
        } catch (error) {
            this.close();

            throw error instanceof RealtimeTransportError ? error : new RealtimeTransportError('connection');
        }
    }

    private async negotiate(options: RealtimeConnectOptions, handlers: RealtimeTransportHandlers): Promise<void> {
        const peer = new RTCPeerConnection();
        const microphone = this.microphone as MediaStream;

        this.peer = peer;
        this.context = typeof AudioContext === 'function' ? new AudioContext() : null;

        if (this.context !== null) {
            this.inputMeter = createLevelMeter(this.context, microphone);
        }

        this.audio = document.createElement('audio');
        this.audio.autoplay = true;

        peer.ontrack = (event: RTCTrackEvent) => {
            const [stream] = event.streams;

            if (stream === undefined || this.audio === null) {
                return;
            }

            this.audio.srcObject = stream;

            if (this.context !== null) {
                this.outputMeter.dispose();
                this.outputMeter = createLevelMeter(this.context, stream);
            }
        };

        peer.onconnectionstatechange = () => {
            if (!this.closed && (peer.connectionState === 'failed' || peer.connectionState === 'disconnected')) {
                this.close();
                handlers.onClose?.();
            }
        };

        microphone.getAudioTracks().forEach((track) => peer.addTrack(track, microphone));

        const channel = peer.createDataChannel(REALTIME_DATA_CHANNEL);

        this.channel = channel;
        channel.onmessage = (message: MessageEvent) => {
            try {
                const event = JSON.parse(String(message.data)) as RealtimeServerEvent;

                if (typeof event.type === 'string') {
                    handlers.onEvent(event);
                }
            } catch {
                // Malformed provider frames are ignored.
            }
        };

        const opened = new Promise<void>((resolve, reject) => {
            channel.onopen = () => resolve();
            channel.onerror = () => reject(new RealtimeTransportError('connection'));
        });

        const offer = await peer.createOffer();

        await peer.setLocalDescription(offer);

        const response = await fetch(`${this.origin}/v1/realtime/calls`, {
            method: 'POST',
            body: offer.sdp,
            headers: {
                Authorization: `Bearer ${options.clientSecret}`,
                'Content-Type': 'application/sdp',
            },
        });

        if (!response.ok) {
            throw new RealtimeTransportError('connection', `HTTP ${response.status}`);
        }

        await peer.setRemoteDescription({ type: 'answer', sdp: await response.text() });
        await opened;
    }

    send(event: RealtimeClientEvent): void {
        if (this.channel?.readyState === 'open') {
            this.channel.send(JSON.stringify(event));
        }
    }

    setMicEnabled(enabled: boolean): void {
        this.microphone?.getAudioTracks().forEach((track) => {
            track.enabled = enabled;
        });
    }

    micLevel(): number {
        const enabled = this.microphone?.getAudioTracks().some((track) => track.enabled) ?? false;

        return enabled ? this.inputMeter.read() : 0;
    }

    outputLevel(): number {
        return this.outputMeter.read();
    }

    close(): void {
        this.closed = true;
        this.channel?.close();
        this.peer?.close();
        this.microphone?.getTracks().forEach((track) => track.stop());
        this.inputMeter.dispose();
        this.outputMeter.dispose();
        void this.context?.close().catch(() => undefined);

        if (this.audio !== null) {
            this.audio.srcObject = null;
        }

        this.channel = null;
        this.peer = null;
        this.microphone = null;
        this.audio = null;
        this.context = null;
        this.inputMeter = SILENT_METER;
        this.outputMeter = SILENT_METER;
    }
}
