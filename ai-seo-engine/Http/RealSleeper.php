<?php

declare(strict_types=1);

namespace AISEOEngine\Http;

final class RealSleeper implements Sleeper
{
    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
