<?php
declare(strict_types=1);

namespace AISEOEngine\Tests\Unit;

use AISEOEngine\GeminiSEO;
use AISEOEngine\Http\FakeHttpClient;
use AISEOEngine\Tests\TestCase;

/**
 * B2 — `$force` veniva accettato e ignorato: il pulsante "Esegui ora"
 * aspettava comunque trenta giorni.
 */
final class RunWindowTest extends TestCase
{
    private function motorePronto(FakeHttpClient $http): GeminiSEO
    {
        putenv('GEMINI_API_KEY=chiave-di-test');
        $motore = new GeminiSEO($this->configTemporanea(), $http);
        $motore->updateConfig([
            'enabled' => true,
            'last_run' => date('Y-m-d H:i:s'),
            'frequency_days' => 30,
        ]);

        return $motore;
    }

    public function testSenzaForceLaFinestraBlocca(): void
    {
        $http = new FakeHttpClient();
        $esito = $this->motorePronto($http)->autoGenerateAndApply(
            $this->datiSito(),
            static fn (): bool => true,
            false
        );

        self::assertSame('Troppo presto per rigenerare', $esito['error']);
        self::assertSame(0, $http->requestCount(), 'Non deve nemmeno provare a chiamare la rete');
    }

    public function testConForceLaFinestraNonBlocca(): void
    {
        $http = FakeHttpClient::respondingWith(200, $this->fixture('success-pulito.json'));

        $esito = $this->motorePronto($http)->autoGenerateAndApply(
            $this->datiSito(),
            static fn (): bool => true,
            true
        );

        self::assertTrue($esito['success'], (string) json_encode($esito));
        self::assertSame(1, $http->requestCount());
    }

    public function testIlDefaultRestaProtettivo(): void
    {
        $http = new FakeHttpClient();
        $esito = $this->motorePronto($http)->autoGenerateAndApply(
            $this->datiSito(),
            static fn (): bool => true
        );

        self::assertSame('Troppo presto per rigenerare', $esito['error']);
    }
}
