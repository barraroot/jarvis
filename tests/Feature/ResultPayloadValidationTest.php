<?php

namespace Tests\Feature;

use App\Exceptions\ResultValidationException;
use App\Results\Payloads\DataResult;
use App\Results\Payloads\DocumentResult;
use App\Results\Payloads\ExecutionStatusResult;
use App\Results\Payloads\ListResult;
use App\Results\Payloads\ReportResult;
use App\Results\Payloads\SearchResultsResult;
use App\Results\Payloads\TableResult;
use App\Results\Payloads\TaskCardResult;
use App\Results\Payloads\TextResult;
use App\Results\ResultNormalizer;
use App\Results\ResultPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResultPayloadValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{payload: array<string, mixed>, invalid: bool}>
     */
    public static function payloads(): array
    {
        return [
            'valid text' => ['payload' => ['type' => 'text', 'text' => 'Olá, operador.'], 'invalid' => false],
            'invalid text' => ['payload' => ['type' => 'text'], 'invalid' => true],
            'valid list' => ['payload' => ['type' => 'list', 'title' => 'Tarefas', 'items' => [['label' => 'Revisar PR'], ['label' => 'Pagar contas', 'detail' => 'vence amanhã']]], 'invalid' => false],
            'invalid list' => ['payload' => ['type' => 'list', 'items' => [['detail' => 'sem rótulo']]], 'invalid' => true],
            'valid table' => ['payload' => ['type' => 'table', 'columns' => ['Cliente', 'Atraso'], 'rows' => [['ACME', 12], ['Beta Ltda', 3]], 'caption' => 'Clientes em atraso'], 'invalid' => false],
            'invalid table' => ['payload' => ['type' => 'table', 'columns' => ['A'], 'rows' => ['not-an-array']], 'invalid' => true],
            'invalid table unknown property' => ['payload' => ['type' => 'table', 'columns' => ['A'], 'rows' => [], 'evil' => true], 'invalid' => true],
            'valid report' => ['payload' => ['type' => 'report', 'title' => 'Relatório', 'sections' => [['heading' => 'Resumo', 'body' => "**Concluído**\n\n- Item A"]]], 'invalid' => false],
            'invalid report' => ['payload' => ['type' => 'report', 'title' => 'Só título'], 'invalid' => true],
            'valid search_results' => ['payload' => ['type' => 'search_results', 'query' => 'laravel ai', 'results' => [['title' => 'Laravel AI', 'url' => 'https://laravel.com/docs/ai-sdk', 'snippet' => 'SDK oficial', 'source' => 'laravel.com']]], 'invalid' => false],
            'invalid search_results' => ['payload' => ['type' => 'search_results', 'query' => 'x', 'results' => [['title' => 'Sem fonte', 'snippet' => 's']]], 'invalid' => true],
            'valid task_card' => ['payload' => ['type' => 'task_card', 'task_id' => 7, 'title' => 'Auditoria ERP', 'status' => 'running', 'progress' => 42], 'invalid' => false],
            'invalid task_card' => ['payload' => ['type' => 'task_card', 'task_id' => 1, 'title' => 'T', 'status' => 'running', 'progress' => 101], 'invalid' => true],
            'valid data' => ['payload' => ['type' => 'data', 'label' => 'Uso de CPU', 'value' => 63.5, 'unit' => '%', 'series' => ['values' => [10, 20, 15]]], 'invalid' => false],
            'invalid data' => ['payload' => ['type' => 'data', 'label' => 'Sem valor'], 'invalid' => true],
            'valid execution_status' => ['payload' => ['type' => 'execution_status', 'entity' => 'tool', 'name' => 'erp.list_clients', 'status' => 'completed', 'message' => '3 clientes encontrados.'], 'invalid' => false],
            'invalid execution_status' => ['payload' => ['type' => 'execution_status', 'entity' => 'banana', 'status' => 'ok', 'message' => 'm'], 'invalid' => true],
            'valid document' => ['payload' => ['type' => 'document', 'title' => 'Proposta', 'format' => 'markdown', 'body' => "# Proposta\n\nTexto **negrito**."], 'invalid' => false],
            'invalid document' => ['payload' => ['type' => 'document', 'title' => 'D', 'format' => 'html', 'body' => 'b'], 'invalid' => true],
            'unknown type' => ['payload' => ['type' => 'hologram'], 'invalid' => true],
            'missing type' => ['payload' => ['text' => 'sem type'], 'invalid' => true],
        ];
    }

    #[DataProvider('payloads')]
    public function test_each_of_nine_types_accepts_valid_and_rejects_invalid_payloads(array $payload, bool $invalid): void
    {
        if ($invalid) {
            $this->expectException(ResultValidationException::class);
        }

        $dto = ResultPayload::fromArray($payload);

        $this->assertSame($payload['type'], $dto::type());
        $this->assertSame($payload['type'], $dto->toArray()['type']);
    }

    public function test_valid_payload_carries_speech_and_suggested_actions(): void
    {
        $payload = ResultPayload::fromArray([
            'type' => 'table',
            'columns' => ['Cliente'],
            'rows' => [['ACME']],
            'speech' => 'Um cliente em atraso.',
            'suggested_actions' => [
                ['label' => 'Enviar lembrete', 'request' => 'Envie um lembrete de pagamento à ACME'],
            ],
        ]);

        $this->assertInstanceOf(TableResult::class, $payload);
        $this->assertSame('Um cliente em atraso.', $payload->speech);
        $this->assertSame('Enviar lembrete', $payload->suggestedActions[0]['label']);
        $this->assertSame('Um cliente em atraso.', $payload->toArray()['speech']);
    }

    public function test_speech_longer_than_300_characters_is_rejected(): void
    {
        $this->expectException(ResultValidationException::class);

        ResultPayload::fromArray(['type' => 'text', 'text' => 'Olá', 'speech' => str_repeat('a', 301)]);
    }

    public function test_more_than_four_suggested_actions_is_rejected(): void
    {
        $this->expectException(ResultValidationException::class);

        ResultPayload::fromArray([
            'type' => 'text',
            'text' => 'Olá',
            'suggested_actions' => array_fill(0, 5, ['label' => 'Ação', 'request' => 'Faça algo']),
        ]);
    }

    public function test_action_label_longer_than_40_characters_is_rejected(): void
    {
        $this->expectException(ResultValidationException::class);

        ResultPayload::fromArray([
            'type' => 'text',
            'text' => 'Olá',
            'suggested_actions' => [['label' => str_repeat('a', 41), 'request' => 'Faça algo']],
        ]);
    }

    public function test_action_request_longer_than_500_characters_is_rejected(): void
    {
        $this->expectException(ResultValidationException::class);

        ResultPayload::fromArray([
            'type' => 'text',
            'text' => 'Olá',
            'suggested_actions' => [['label' => 'Ação', 'request' => str_repeat('a', 501)]],
        ]);
    }

    public function test_invalid_payload_falls_back_to_text_with_flag(): void
    {
        $normalizer = app(ResultNormalizer::class);

        $result = $normalizer->normalize(['type' => 'table', 'columns' => ['A']]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('text', $result['payload']['type']);
        $this->assertSame('', $result['payload']['text']);
        $this->assertTrue($result['payload']['meta']['result_invalid']);
    }

    public function test_fallback_preserves_raw_text_when_present(): void
    {
        $result = app(ResultNormalizer::class)->normalize(['type' => 'table', 'text' => 'tabela quebrada']);

        $this->assertSame('text', $result['type']);
        $this->assertSame('tabela quebrada', $result['payload']['text']);
        $this->assertTrue($result['payload']['meta']['result_invalid']);
    }

    public function test_valid_payload_normalizes_untouched(): void
    {
        $result = app(ResultNormalizer::class)->normalize([
            'type' => 'data',
            'label' => 'Memória livre',
            'value' => 8.2,
            'unit' => 'GB',
        ]);

        $this->assertSame('data', $result['type']);
        $this->assertInstanceOf(DataResult::class, ResultPayload::fromArray($result['payload']));
        $this->assertSame('data', $result['payload']['type']);
        $this->assertSame(8.2, $result['payload']['value']);
    }

    public function test_null_payload_normalizes_to_empty_text(): void
    {
        $result = app(ResultNormalizer::class)->normalize(null);

        $this->assertSame('text', $result['type']);
        $this->assertSame('', $result['payload']['text']);
    }

    public function test_each_dto_exposes_its_type_and_round_trips(): void
    {
        $cases = [
            TextResult::class => ['type' => 'text', 'text' => 'Olá'],
            ListResult::class => ['type' => 'list', 'items' => [['label' => 'A']]],
            TableResult::class => ['type' => 'table', 'columns' => ['A'], 'rows' => []],
            ReportResult::class => ['type' => 'report', 'title' => 'T', 'sections' => [['heading' => 'H', 'body' => 'B']]],
            SearchResultsResult::class => ['type' => 'search_results', 'query' => 'q', 'results' => [['title' => 't', 'snippet' => 's', 'source' => 'x']]],
            TaskCardResult::class => ['type' => 'task_card', 'task_id' => 1, 'title' => 'T', 'status' => 'running', 'progress' => 0],
            DataResult::class => ['type' => 'data', 'label' => 'L', 'value' => 1],
            ExecutionStatusResult::class => ['type' => 'execution_status', 'entity' => 'agent', 'status' => 'completed', 'message' => 'm'],
            DocumentResult::class => ['type' => 'document', 'title' => 'T', 'format' => 'plain', 'body' => 'b'],
        ];

        foreach ($cases as $class => $payload) {
            $dto = ResultPayload::fromArray($payload);

            $this->assertInstanceOf($class, $dto);
            $this->assertSame($dto::type(), $dto::fromArray($dto->toArray())::type());
        }
    }
}
