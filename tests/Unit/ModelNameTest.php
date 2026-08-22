<?php

declare(strict_types=1);

namespace AISEOEngine\Tests\Unit;

use AISEOEngine\GeminiSEO;
use AISEOEngine\Http\FakeHttpClient;
use AISEOEngine\Tests\TestCase;

/**
 * B4 — `testConnection()` dichiarava un modello diverso da quello chiamato.
 */
final class ModelNameTest extends TestCase
{
    public function testIlModelloRiportatoEQuelloDellEndpoint(): void
    {
        putenv('GEMINI_API_KEY=chiave-di-test');
        $http = FakeHttpClient::respondingWith(200, $this->fixture('success-pulito.json'));

        $esito = (new GeminiSEO($this->configTemporanea(), $http))->testConnection();

        self::assertTrue($esito['success']);
        self::assertSame('gemini-2.5-flash', $esito['model']);
        self::assertStringContainsString(
            $esito['model'],
            $http->lastRequest()['url'],
            'Il modello dichiarato deve comparire nella URL davvero chiamata'
        );
    }

    public function testSenzaChiaveNonDichiaraNulla(): void
    {
        putenv('GEMINI_API_KEY');

        $esito = (new GeminiSEO($this->configTemporanea()))->testConnection();

        self::assertFalse($esito['success']);
        self::assertArrayNotHasKey('model', $esito);
    }
}
