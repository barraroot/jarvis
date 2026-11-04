import type {
    RealtimeClientEvent,
    RealtimeConnectOptions,
    RealtimeServerEvent,
    RealtimeTransport,
    RealtimeTransportHandlers,
} from '@/lib/voice/RealtimeTransport';

/** In-memory {@link RealtimeTransport}: records sent events and lets tests emit provider events. */
export class FakeRealtimeTransport implements RealtimeTransport {
    sent: RealtimeClientEvent[] = [];
    connectedWith: RealtimeConnectOptions | null = null;
    closed = false;
    micEnabled = true;
    mic = 0;
    output = 0;
    failWith: Error | null = null;
    private handlers: RealtimeTransportHandlers | null = null;

    async connect(options: RealtimeConnectOptions, handlers: RealtimeTransportHandlers): Promise<void> {
        if (this.failWith !== null) {
            throw this.failWith;
        }

        this.connectedWith = options;
        this.handlers = handlers;
        this.closed = false;
    }

    send(event: RealtimeClientEvent): void {
        this.sent.push(event);
    }

    close(): void {
        this.closed = true;
    }

    setMicEnabled(enabled: boolean): void {
        this.micEnabled = enabled;
    }

    micLevel(): number {
        return this.micEnabled ? this.mic : 0;
    }

    outputLevel(): number {
        return this.output;
    }

    emit(event: RealtimeServerEvent): void {
        this.handlers?.onEvent(event);
    }

    drop(): void {
        this.handlers?.onClose?.();
    }

    types(): string[] {
        return this.sent.map((event) => event.type);
    }
}
