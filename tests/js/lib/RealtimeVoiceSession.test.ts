import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { RealtimeVoiceSession, type RealtimeVoiceSessionHooks } from '@/lib/voice/RealtimeVoiceSession';
import { rmsLevel } from '@/lib/voice/levels';
import type { TaskSummary } from '@/types/models';
import { FakeRealtimeTransport } from '../helpers/realtime';

async function started(hooks: RealtimeVoiceSessionHooks = {}) {
    const transport = new FakeRealtimeTransport();
    const session = new RealtimeVoiceSession(transport, hooks, { now: () => new Date('2026-11-04T10:00:00Z') });

    await session.start('ek_test');

    return { transport, session };
}

describe('RealtimeVoiceSession', () => {
    it('connects with the ephemeral client secret', async () => {
        const { transport, session } = await started();

        expect(transport.connectedWith).toEqual({ clientSecret: 'ek_test' });
        expect(session.isOpen).toBe(true);
        expect(session.currentPhase).toBe('listening');
    });

    it('speech_started while speaking interrupts the output and switches to user_speaking', async () => {
        const onPhase = vi.fn();
        const { transport, session } = await started({ onPhase });

        transport.emit({ type: 'response.created' });
        transport.emit({ type: 'output_audio_buffer.started' });
        expect(session.currentPhase).toBe('speaking');

        transport.emit({ type: 'input_audio_buffer.speech_started' });

        expect(transport.types()).toEqual(['response.cancel', 'output_audio_buffer.clear']);
        expect(session.currentPhase).toBe('user_speaking');
        expect(onPhase).toHaveBeenLastCalledWith('user_speaking');
    });

    it('feeds captions and turns from the transcriptions', async () => {
        const onUserCaption = vi.fn();
        const onAssistantDelta = vi.fn();
        const onTurn = vi.fn();
        const { transport } = await started({ onUserCaption, onAssistantDelta, onTurn });

        transport.emit({ type: 'conversation.item.input_audio_transcription.delta', item_id: 'i1', delta: 'conta uma' });
        transport.emit({ type: 'conversation.item.input_audio_transcription.delta', item_id: 'i1', delta: ' piada' });
        expect(onUserCaption).toHaveBeenLastCalledWith('conta uma piada', false);

        transport.emit({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'i1', transcript: 'Conta uma piada.' });
        expect(onUserCaption).toHaveBeenLastCalledWith('Conta uma piada.', true);

        transport.emit({ type: 'response.created' });
        transport.emit({ type: 'response.output_audio_transcript.delta', delta: 'Um programador ' });
        transport.emit({ type: 'response.output_audio_transcript.delta', delta: 'entra no bar [risos]' });
        expect(onAssistantDelta).toHaveBeenCalledTimes(2);

        transport.emit({ type: 'response.done', response: { id: 'resp_1' } });

        expect(onTurn.mock.calls.map(([turn]) => turn)).toEqual([
            { role: 'user', content: 'Conta uma piada.', spoken_at: '2026-11-04T10:00:00.000Z' },
            { role: 'assistant', content: 'Um programador entra no bar [risos]', spoken_at: '2026-11-04T10:00:00.000Z' },
        ]);
    });

    it('response.done reports the usage', async () => {
        const onUsage = vi.fn();
        const { transport } = await started({ onUsage });

        transport.emit({
            type: 'response.done',
            response: { id: 'resp_9', usage: { input_tokens: 120, output_tokens: 40, input_token_details: { audio_tokens: 100 } } },
        });

        expect(onUsage).toHaveBeenCalledWith('resp_9', { input_tokens: 120, output_tokens: 40, input_token_details: { audio_tokens: 100 } });
    });

    it('a start_task function call creates the task and answers the model', async () => {
        const task = { id: '5', title: 'Busca', status: 'queued', progress: 0 } as TaskSummary;
        const onStartTask = vi.fn(async () => task);
        const { transport } = await started({ onStartTask });

        transport.emit({ type: 'response.created' });
        transport.emit({
            type: 'response.function_call_arguments.done',
            name: 'start_task',
            call_id: 'call_1',
            arguments: JSON.stringify({ request: 'procure a aula de inglês', follow_up: true }),
        });
        await flushPromises();

        expect(onStartTask).toHaveBeenCalledWith({ request: 'procure a aula de inglês', followUp: true });
        // The reply waits for the in-flight response to finish.
        expect(transport.sent).toEqual([]);

        transport.emit({ type: 'response.done', response: { id: 'resp_1' } });

        expect(transport.types()).toEqual(['conversation.item.create', 'response.create']);
        const item = transport.sent[0]?.item as { type: string; call_id: string; output: string };
        expect(item.type).toBe('function_call_output');
        expect(item.call_id).toBe('call_1');
        expect(JSON.parse(item.output)).toMatchObject({ status: 'queued', task_id: '5' });
    });

    it('tells the model when the task could not start', async () => {
        const { transport } = await started({ onStartTask: async () => null });

        transport.emit({ type: 'response.function_call_arguments.done', name: 'start_task', call_id: 'call_2', arguments: '{"request":"x"}' });
        await flushPromises();

        const item = transport.sent[0]?.item as { output: string };
        expect(JSON.parse(item.output)).toMatchObject({ status: 'failed' });
    });

    it('announce during speech is only sent after the speech ends', async () => {
        const { transport, session } = await started();

        transport.emit({ type: 'response.created' });
        transport.emit({ type: 'output_audio_buffer.started' });

        session.announce('<tool_result trusted="false">Aula 12</tool_result>');
        expect(transport.sent).toEqual([]);

        transport.emit({ type: 'response.done', response: { id: 'resp_1' } });
        expect(transport.sent).toEqual([]);

        transport.emit({ type: 'output_audio_buffer.stopped' });

        expect(transport.types()).toEqual(['conversation.item.create', 'response.create']);
        const item = transport.sent[0]?.item as { content: Array<{ text: string }> };
        expect(item.content[0]?.text).toContain('<tool_result trusted="false">Aula 12</tool_result>');
    });

    it('announce waits while the operator is speaking', async () => {
        const { transport, session } = await started();

        transport.emit({ type: 'input_audio_buffer.speech_started' });
        session.announce('resumo');
        expect(transport.sent).toEqual([]);

        transport.emit({ type: 'input_audio_buffer.speech_stopped' });
        expect(transport.types()).toEqual(['conversation.item.create', 'response.create']);
    });

    it('say sends a response with the exact phrase when idle', async () => {
        const { transport, session } = await started();

        session.say('Pode falar.');

        expect(transport.types()).toEqual(['response.create']);
        expect(JSON.stringify(transport.sent[0])).toContain('Pode falar.');
    });

    it('turn boundaries signal activity for the countdown', async () => {
        const onActivity = vi.fn();
        const { transport } = await started({ onActivity });

        transport.emit({ type: 'input_audio_buffer.speech_started' });
        transport.emit({ type: 'output_audio_buffer.stopped' });

        expect(onActivity).toHaveBeenCalledTimes(2);
    });

    it('mute disables the mic track and zeroes its level while output keeps playing', async () => {
        const { transport, session } = await started();
        transport.mic = 0.7;
        transport.output = 0.5;

        session.setMicEnabled(false);

        expect(session.levels()).toEqual({ mic: 0, output: 0.5 });
    });

    it('close stops the transport and drops queued utterances', async () => {
        const onClose = vi.fn();
        const { transport, session } = await started({ onClose });

        session.close();
        session.say('ninguém ouve');

        expect(transport.closed).toBe(true);
        expect(transport.sent).toEqual([]);

        transport.drop();
        expect(onClose).toHaveBeenCalled();
    });
});

describe('rmsLevel', () => {
    it('maps silence to 0 and a full-scale signal to 1', () => {
        expect(rmsLevel(new Uint8Array(64).fill(128))).toBe(0);
        expect(rmsLevel(Uint8Array.from({ length: 64 }, (_, index) => (index % 2 === 0 ? 0 : 255)))).toBe(1);
    });
});
