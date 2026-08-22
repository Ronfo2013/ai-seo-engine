<?php

declare(strict_types=1);

namespace AISEOEngine\Http;

/**
 * Non dorme: annota per quanto avrebbe dormito. Serve ai test per verificare
 * che il backoff cresca davvero, senza starlo ad aspettare.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var array<int, int> */
    private array $slept = [];

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->slept[] = $milliseconds;
    }

    /** @return array<int, int> */
    public function sleptMilliseconds(): array
    {
        return $this->slept;
    }

    public function totalMilliseconds(): int
    {
        return array_sum($this->slept);
    }
}
