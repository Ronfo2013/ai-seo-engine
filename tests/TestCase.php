<?php

declare(strict_types=1);

namespace AISEOEngine\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    private ?string $cartellaTemporanea = null;

    protected function tearDown(): void
    {
        if ($this->cartellaTemporanea !== null && is_dir($this->cartellaTemporanea)) {
            foreach (glob($this->cartellaTemporanea . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->cartellaTemporanea);
        }

        putenv('GEMINI_API_KEY');
        parent::tearDown();
    }

    /** Percorso di un file di configurazione usa e getta, isolato per test. */
    protected function configTemporanea(): string
    {
        $this->cartellaTemporanea = sys_get_temp_dir() . '/aiseo_' . bin2hex(random_bytes(6));
        mkdir($this->cartellaTemporanea, 0755, true);

        return $this->cartellaTemporanea . '/seo-config.json';
    }

    protected function cartella(): string
    {
        return (string) $this->cartellaTemporanea;
    }

    protected function fixture(string $nome): string
    {
        $percorso = __DIR__ . '/fixtures/gemini/' . $nome;
        self::assertFileExists($percorso, "Fixture mancante: {$nome}");

        return (string) file_get_contents($percorso);
    }

    /** @return array<string, mixed> */
    protected function datiSito(mixed $businessType = ['Restaurant', 'BowlingAlley']): array
    {
        return [
            'site_name' => 'Arhena',
            'seo' => [
                'title' => 'Vecchio titolo',
                'description' => 'Vecchia descrizione',
                'keywords' => 'vecchie, keywords',
                'business_type' => $businessType,
            ],
            'contact' => ['address' => 'Via Test 1, Portogruaro'],
            'sections' => [
                ['title' => 'Bowling', 'content' => 'Otto piste da bowling professionali aperte tutti i giorni.'],
            ],
        ];
    }
}
