<?php

declare(strict_types=1);

namespace AISEOEngine\Tests\Support;

use AISEOEngine\Http\HttpResponse;
use AISEOEngine\Http\Transport;
use RuntimeException;

/** Trasporto finto: risposte preparate, nessun pacchetto sulla rete. */
final class FakeTransport implements Transport
{
    /** @var array<int, HttpResponse> */
    private array $preparate;

    private int $inviate = 0;

    public function __construct(HttpResponse ...$preparate)
    {
        $this->preparate = array_values($preparate);
    }

    /** @param array<int, HttpResponse> $risposte */
    public static function conRisposte(array $risposte): self
    {
        return new self(...$risposte);
    }

    public function send(string $url, string $body, array $headers): HttpResponse
    {
        if (!isset($this->preparate[$this->inviate])) {
            throw new RuntimeException('FakeTransport: più tentativi delle risposte preparate.');
        }

        return $this->preparate[$this->inviate++];
    }

    public function sentCount(): int
    {
        return $this->inviate;
    }
}
