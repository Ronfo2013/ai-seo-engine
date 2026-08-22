<?php

declare(strict_types=1);

namespace AISEOEngine\Tests\Unit;

use AISEOEngine\Http\HttpResponse;
use AISEOEngine\Http\RecordingSleeper;
use AISEOEngine\Http\RetryingHttpClient;
use AISEOEngine\Tests\Support\FakeTransport;
use AISEOEngine\Tests\TestCase;

/**
 * La v1 chiamava curl una volta sola: un 503 momentaneo faceva saltare
 * l'intero run mensile, e l'errore si scopriva un mese dopo.
 */
final class RetryPolicyTest extends TestCase
{
    public function testUnErroreDelServerVieneRiprovato(): void
    {
        $sleeper = new RecordingSleeper();
        $trasporto = new FakeTransport(
            new HttpResponse(503, '{"error":{"message":"Internal"}}'),
            new HttpResponse(503, '{"error":{"message":"Internal"}}'),
            new HttpResponse(200, '{"ok":true}'),
        );
        $client = new RetryingHttpClient(3, 500, $trasporto, $sleeper);

        $risposta = $client->postJson('https://esempio.test', ['a' => 1]);

        self::assertTrue($risposta->isSuccess());
        self::assertSame(3, $risposta->attempts);
        self::assertCount(2, $sleeper->sleptMilliseconds(), 'Deve attendere fra un tentativo e l\'altro');
    }

    public function testIlBackoffCresce(): void
    {
        $sleeper = new RecordingSleeper();
        $client = new RetryingHttpClient(
            4,
            500,
            FakeTransport::conRisposte(array_fill(0, 4, new HttpResponse(500, '{}'))),
            $sleeper
        );

        $client->postJson('https://esempio.test', []);
        $attese = $sleeper->sleptMilliseconds();

        self::assertCount(3, $attese);
        self::assertGreaterThan($attese[0], $attese[1]);
        self::assertGreaterThan($attese[1], $attese[2]);
    }

    public function testUnErroreDelClienteNonVieneRiprovato(): void
    {
        $sleeper = new RecordingSleeper();
        $trasporto = new FakeTransport(new HttpResponse(400, '{"error":{"message":"API key not valid"}}'));
        $client = new RetryingHttpClient(3, 500, $trasporto, $sleeper);

        $risposta = $client->postJson('https://esempio.test', []);

        self::assertFalse($risposta->isSuccess());
        self::assertSame(1, $trasporto->sentCount(), 'Riprovare una chiave sbagliata la fa fallire uguale');
        self::assertSame([], $sleeper->sleptMilliseconds());
    }

    public function testIlRateLimitRispettaRetryAfter(): void
    {
        $sleeper = new RecordingSleeper();
        $client = new RetryingHttpClient(3, 500, new FakeTransport(
            new HttpResponse(429, '{}', null, 7),
            new HttpResponse(200, '{"ok":true}'),
        ), $sleeper);

        $client->postJson('https://esempio.test', []);

        self::assertSame([7000], $sleeper->sleptMilliseconds(), 'Il Retry-After del server vince sul calcolo nostro');
    }

    public function testUnRetryAfterAssurdoVieneLimitato(): void
    {
        $sleeper = new RecordingSleeper();
        $client = new RetryingHttpClient(2, 500, new FakeTransport(
            new HttpResponse(429, '{}', null, 86_400),
            new HttpResponse(200, '{}'),
        ), $sleeper);

        $client->postJson('https://esempio.test', []);

        self::assertSame([60_000], $sleeper->sleptMilliseconds(), 'Un giorno di attesa non è un ritentativo');
    }

    public function testIProblemiDiReteVengonoRiprovatiEPoiRiportati(): void
    {
        $sleeper = new RecordingSleeper();
        $client = new RetryingHttpClient(
            3,
            10,
            FakeTransport::conRisposte(array_fill(0, 3, HttpResponse::networkFailure('Could not resolve host'))),
            $sleeper
        );

        $risposta = $client->postJson('https://esempio.test', []);

        self::assertTrue($risposta->isConnectionFailure());
        self::assertSame(3, $risposta->attempts);
        self::assertSame('Could not resolve host', $risposta->networkError);
    }

    public function testIlSuccessoAlPrimoColpoNonAttendeMai(): void
    {
        $sleeper = new RecordingSleeper();
        $client = new RetryingHttpClient(3, 500, new FakeTransport(new HttpResponse(200, '{}')), $sleeper);

        $risposta = $client->postJson('https://esempio.test', []);

        self::assertSame(1, $risposta->attempts);
        self::assertSame(0, $sleeper->totalMilliseconds());
    }
}
