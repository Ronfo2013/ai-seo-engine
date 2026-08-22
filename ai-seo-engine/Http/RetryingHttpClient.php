<?php

declare(strict_types=1);

namespace AISEOEngine\Http;

/**
 * Politica di ritentativo sopra un trasporto qualsiasi.
 *
 * La v1 chiamava curl una volta sola: un 503 momentaneo o un rate limit
 * facevano saltare l'intero run mensile, e l'errore si scopriva un mese dopo.
 */
final class RetryingHttpClient implements HttpClient
{
    private Transport $transport;
    private Sleeper $sleeper;

    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMilliseconds = 500,
        ?Transport $transport = null,
        ?Sleeper $sleeper = null,
        int $timeoutSeconds = 30
    ) {
        $this->transport = $transport ?? new CurlTransport($timeoutSeconds);
        $this->sleeper = $sleeper ?? new RealSleeper();
    }

    public function postJson(string $url, array $payload, array $headers = []): HttpResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            return HttpResponse::networkFailure(
                'Payload non serializzabile in JSON: ' . json_last_error_msg()
            );
        }

        $tentativo = 0;
        $risposta = HttpResponse::networkFailure('Nessun tentativo eseguito');

        while ($tentativo < $this->maxAttempts) {
            $tentativo++;
            $risposta = $this->transport->send($url, $body, $headers)->withAttempts($tentativo);

            if (!$risposta->isRetryable() || $tentativo >= $this->maxAttempts) {
                return $risposta;
            }

            $this->sleeper->sleepMilliseconds($this->attesaPer($tentativo, $risposta));
        }

        return $risposta;
    }

    /**
     * Backoff esponenziale con jitter. Il jitter evita che più installazioni
     * che hanno preso lo stesso 429 tornino a bussare tutte nello stesso
     * istante. Un Retry-After esplicito del server vince sul calcolo nostro,
     * ma non oltre un minuto: un giorno di attesa non è un ritentativo.
     */
    private function attesaPer(int $tentativo, HttpResponse $risposta): int
    {
        if ($risposta->retryAfterSeconds !== null && $risposta->retryAfterSeconds > 0) {
            return min($risposta->retryAfterSeconds, 60) * 1000;
        }

        $esponenziale = $this->baseDelayMilliseconds * (2 ** ($tentativo - 1));
        $jitter = random_int(0, (int) max(1, $esponenziale / 4));

        return (int) min($esponenziale + $jitter, 30_000);
    }
}
