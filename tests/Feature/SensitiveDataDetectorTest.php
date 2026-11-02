<?php

namespace Tests\Feature;

use App\Services\SensitiveDataDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class SensitiveDataDetectorTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
    }

    public function test_detects_cpf_card_and_password_patterns(): void
    {
        $detector = app(SensitiveDataDetector::class);

        $sensitive = [
            'O CPF é 123.456.789-01',
            'CPF 12345678901',
            'Cartão 4111 1111 1111 1111',
            'Cartão 4532-2211-0987-6543-210',
            'senha: segredo123',
            'a password=secret aparece aqui',
            'Chave sk-abcdef1234567890',
            'O diagnóstico de diabetes foi confirmado',
            'Alergia a amendoim',
        ];

        foreach ($sensitive as $text) {
            $this->assertTrue($detector->containsSensitive($text), "Expected sensitive data in: {$text}");
        }
    }

    public function test_does_not_flag_regular_content(): void
    {
        $detector = app(SensitiveDataDetector::class);

        $harmless = [
            'Gosta de café preto',
            'Está desenvolvendo o projeto JARVIS em Laravel',
            'Prefere reuniões pela manhã',
            'A senha do time é a defesa forte', // no assignment marker
            'O número do pedido é 42',
        ];

        foreach ($harmless as $text) {
            $this->assertFalse($detector->containsSensitive($text), "Expected harmless content in: {$text}");
        }
    }
}
