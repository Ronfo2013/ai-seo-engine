<?php

declare(strict_types=1);

namespace AISEOEngine\Http;

/**
 * Esito di una richiesta HTTP, comprensivo del caso "non sono nemmeno
 * riuscito a connettermi": distinguerlo da un 500 conta, perché è l'unico
 * modo di dire all'utente se il problema è suo o del fornitore.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $networkError = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly int $attempts = 1
    ) {
    }

    public static function networkFailure(string $error, int $attempts = 1): self
    {
        return new self(0, '', $error, null, $attempts);
    }

    public function isSuccess(): bool
    {
        return $this->networkError === null && $this->status >= 200 && $this->status < 300;
    }

    public function isConnectionFailure(): bool
    {
        return $this->networkError !== null;
    }

    /**
     * Vale la pena riprovare? Sì per i problemi di rete, per il rate limit
     * e per gli errori del server. No per 4xx diversi da 429: riprovare una
     * richiesta malformata o una chiave sbagliata la fa fallire uguale.
     */
    public function isRetryable(): bool
    {
        if ($this->networkError !== null) {
            return true;
        }

        return $this->status === 429 || $this->status === 408 || $this->status >= 500;
    }

    public function withAttempts(int $attempts): self
    {
        return new self(
            $this->status,
            $this->body,
            $this->networkError,
            $this->retryAfterSeconds,
            $attempts
        );
    }
}
