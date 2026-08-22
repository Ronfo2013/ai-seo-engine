<?php

declare(strict_types=1);

namespace AISEOEngine\Http;

/**
 * L'unico punto da cui il motore tocca la rete.
 *
 * Esiste per una ragione sola: senza questa interfaccia non si può scrivere
 * un test che non consumi quota e non dipenda dalla connessione.
 */
interface HttpClient
{
    /**
     * @param array<string, mixed> $payload  corpo della richiesta, serializzato in JSON
     * @param array<int, string>   $headers  header aggiuntivi, formato "Nome: valore"
     */
    public function postJson(string $url, array $payload, array $headers = []): HttpResponse;
}
