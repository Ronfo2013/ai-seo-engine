<?php

declare(strict_types=1);

namespace AISEOEngine\Http;

use RuntimeException;

/**
 * Client finto per i test: restituisce risposte preparate e registra cosa
 * gli è stato chiesto. Nessun test del progetto deve toccare la rete.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<int, HttpResponse> */
    private array $queue = [];

    /** @var array<int, array{url: string, payload: array<string, mixed>, headers: array<int, string>}> */
    private array $requests = [];

    public function __construct(HttpResponse ...$responses)
    {
        // array_values: con gli argomenti nominati un variadico può avere
        // chiavi stringa, e array_shift() qui si aspetta una lista.
        $this->queue = array_values($responses);
    }

    public static function respondingWith(int $status, string $body): self
    {
        return new self(new HttpResponse($status, $body));
    }

    public function enqueue(HttpResponse $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function postJson(string $url, array $payload, array $headers = []): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers];

        if ($this->queue === []) {
            throw new RuntimeException(
                'FakeHttpClient: richiesta numero ' . count($this->requests)
                . ' senza risposta preparata. Il test si aspettava meno chiamate.'
            );
        }

        return array_shift($this->queue);
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /** @return array{url: string, payload: array<string, mixed>, headers: array<int, string>} */
    public function lastRequest(): array
    {
        if ($this->requests === []) {
            throw new RuntimeException('FakeHttpClient: nessuna richiesta registrata.');
        }

        return $this->requests[count($this->requests) - 1];
    }

    public function hasPendingResponses(): bool
    {
        return $this->queue !== [];
    }
}
