<?php

declare(strict_types=1);

namespace AISEOEngine\Tests\Unit;

use AISEOEngine\GeminiSEO;
use AISEOEngine\Http\FakeHttpClient;
use AISEOEngine\Tests\TestCase;

/**
 * B6 — config, storico e log venivano scritti senza lock, con pattern
 * leggi-modifica-riscrivi: cron e admin insieme si sovrascrivevano a vicenda.
 */
final class HistoryWriteTest extends TestCase
{
    public function testOgniRunApplicatoLasciaUnaVoceNelloStorico(): void
    {
        putenv('GEMINI_API_KEY=chiave-di-test');
        $percorso = $this->configTemporanea();

        $http = new FakeHttpClient();
        for ($i = 0; $i < 3; $i++) {
            $http->enqueue(new \AISEOEngine\Http\HttpResponse(200, $this->fixture('success-pulito.json')));
        }

        $motore = new GeminiSEO($percorso, $http);
        $motore->updateConfig(['enabled' => true]);

        for ($i = 0; $i < 3; $i++) {
            $motore->autoGenerateAndApply($this->datiSito(), static fn (): bool => true, true);
        }

        self::assertCount(3, $motore->getHistory(50));
        self::assertJson((string) file_get_contents($this->cartella() . '/history.json'));
    }

    public function testScrittureConcorrentiNonCorromponoLoStorico(): void
    {
        $percorso = $this->configTemporanea();
        $storico = $this->cartella() . '/history.json';

        $script = sprintf(
            'require %s; $m = new AISEOEngine\GeminiSEO(%s);' .
            '$r = new ReflectionMethod($m, "saveToHistory"); $r->setAccessible(true);' .
            'for ($i = 0; $i < 20; $i++) { $r->invoke($m, ["title" => "vecchio"], ["title" => "nuovo"]); }',
            var_export(dirname(__DIR__, 2) . '/ai-seo-engine/GeminiSEO.php', true),
            var_export($percorso, true)
        );

        $processi = [];
        for ($i = 0; $i < 4; $i++) {
            $processi[] = proc_open(
                ['php', '-r', $script],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
        }

        foreach ($processi as $processo) {
            if (is_resource($processo)) {
                proc_close($processo);
            }
        }

        $contenuto = (string) file_get_contents($storico);
        $decodificato = json_decode($contenuto, true);

        self::assertIsArray($decodificato, 'Lo storico è JSON non valido: scrittura non atomica');
        self::assertArrayHasKey('entries', $decodificato);
        self::assertLessThanOrEqual(100, count($decodificato['entries']), 'Il tetto di 100 voci non è rispettato');
        self::assertGreaterThan(0, count($decodificato['entries']));
    }
}
