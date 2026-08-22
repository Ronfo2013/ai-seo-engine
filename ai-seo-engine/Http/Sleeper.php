<?php
declare(strict_types=1);

namespace AISEOEngine\Http;

/**
 * L'attesa fra un tentativo e l'altro è iniettabile perché altrimenti
 * testare il backoff richiederebbe di aspettarlo davvero.
 */
interface Sleeper
{
    public function sleepMilliseconds(int $milliseconds): void;
}
