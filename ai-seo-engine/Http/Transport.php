<?php
declare(strict_types=1);

namespace AISEOEngine\Http;

/**
 * Un singolo tentativo di richiesta: è l'unico punto del progetto in cui un
 * pacchetto lascia davvero la macchina.
 *
 * È separato dalla politica di ritentativo perché sono due responsabilità
 * diverse — e perché così i test possono sostituire il trasporto senza
 * ereditarietà e senza toccare la rete.
 */
interface Transport
{
    /** @param array<int, string> $headers */
    public function send(string $url, string $body, array $headers): HttpResponse;
}
