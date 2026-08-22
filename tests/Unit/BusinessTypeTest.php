<?php
declare(strict_types=1);

namespace AISEOEngine\Tests\Unit;

use AISEOEngine\GeminiSEO;
use AISEOEngine\Tests\TestCase;

/**
 * B1 — `seo.business_type` arrivava come stringa e `in_array()` sollevava un
 * TypeError fatale su PHP 8, ammazzando il cron.
 */
final class BusinessTypeTest extends TestCase
{
    private function motore(): GeminiSEO
    {
        return new GeminiSEO($this->configTemporanea());
    }

    public function testUnaStringaNonFaPiuEsplodereIlMotore(): void
    {
        $contesto = $this->motore()->analyzeSiteContent(
            $this->datiSito('Restaurant, BowlingAlley')
        );

        self::assertStringContainsString('Ristorante', $contesto['business_type']);
        self::assertStringContainsString('bowling', $contesto['unique_points']);
        self::assertStringContainsString('amici', $contesto['target_audience']);
    }

    public function testUnArrayContinuaAFunzionareComePrima(): void
    {
        $contesto = $this->motore()->analyzeSiteContent(
            $this->datiSito(['Restaurant', 'BowlingAlley'])
        );

        self::assertStringContainsString('Bowling', $contesto['business_type']);
        self::assertStringContainsString('cucina di qualità', $contesto['unique_points']);
    }

    public function testStringaEArrayProduconoLoStessoRisultato(): void
    {
        $motore = $this->motore();

        self::assertSame(
            $motore->analyzeSiteContent($this->datiSito(['Restaurant', 'BowlingAlley']))['business_type'],
            $motore->analyzeSiteContent($this->datiSito('Restaurant,BowlingAlley'))['business_type']
        );
    }

    /**
     * @dataProvider valoriStrani
     */
    public function testValoriInattesiNonSollevanoEccezioni(mixed $valore): void
    {
        $contesto = $this->motore()->analyzeSiteContent($this->datiSito($valore));

        self::assertIsString($contesto['business_type']);
        self::assertIsString($contesto['target_audience']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function valoriStrani(): array
    {
        return [
            'stringa vuota' => [''],
            'array vuoto' => [[]],
            'null' => [null],
            'numero' => [42],
            'array con elementi vuoti' => [['', '  ', 'Restaurant']],
            'array annidato' => [[['Restaurant'], 'NightClub']],
            'booleano' => [true],
        ];
    }

    public function testSenzaTipoRicadeSulGenerico(): void
    {
        $dati = $this->datiSito();
        unset($dati['seo']['business_type']);

        self::assertSame('Attività locale', $this->motore()->analyzeSiteContent($dati)['business_type']);
    }
}
