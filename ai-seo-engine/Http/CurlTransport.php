<?php
declare(strict_types=1);

namespace AISEOEngine\Http;

final class CurlTransport implements Transport
{
    public function __construct(private readonly int $timeoutSeconds = 30)
    {
    }

    public function send(string $url, string $body, array $headers): HttpResponse
    {
        $retryAfter = null;

        $handle = curl_init($url);
        if ($handle === false) {
            return HttpResponse::networkFailure('Impossibile inizializzare curl');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $header) use (&$retryAfter): int {
                if (stripos($header, 'retry-after:') === 0) {
                    $valore = trim(substr($header, strlen('retry-after:')));
                    if (ctype_digit($valore)) {
                        $retryAfter = (int) $valore;
                    }
                }

                return strlen($header);
            },
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $errore = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            return HttpResponse::networkFailure($errore !== '' ? $errore : 'connessione non riuscita');
        }

        return new HttpResponse($status, (string) $raw, null, $retryAfter);
    }
}
