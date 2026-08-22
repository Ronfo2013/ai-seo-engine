<?php
declare(strict_types=1);

namespace AISEOEngine\Tests\Unit;

use AISEOEngine\GeminiSEO;
use AISEOEngine\Tests\TestCase;

/**
 * S1 — la chiave finiva in chiaro dentro un file tracciato da git.
 */
final class SecretsTest extends TestCase
{
    public function testLaChiaveArrivaDallAmbiente(): void
    {
        putenv('GEMINI_API_KEY=chiave-di-test');

        self::assertSame('chiave-di-test', (new GeminiSEO($this->configTemporanea()))->getConfig()['api_key']);
    }

    public function testLaChiaveNonVieneMaiScrittaSuDisco(): void
    {
        putenv('GEMINI_API_KEY=chiave-da-non-salvare');
        $percorso = $this->configTemporanea();

        $motore = new GeminiSEO($percorso);
        $motore->updateConfig(['enabled' => true]);   // provoca una saveConfig()

        $suDisco = json_decode((string) file_get_contents($percorso), true);

        self::assertIsArray($suDisco);
        self::assertSame('', $suDisco['api_key'], 'La chiave è stata persistita su disco');
        self::assertTrue($suDisco['enabled'], 'Le impostazioni non sensibili devono invece persistere');
        self::assertStringNotContainsString(
            'chiave-da-non-salvare',
            (string) file_get_contents($percorso)
        );
    }

    public function testSenzaVariabileDAmbienteLaChiaveRestaVuota(): void
    {
        putenv('GEMINI_API_KEY');

        self::assertSame('', (new GeminiSEO($this->configTemporanea()))->getConfig()['api_key']);
    }

    public function testSenzaChiaveIlMotoreSiFermaPrimaDiChiamareLaRete(): void
    {
        putenv('GEMINI_API_KEY');
        $motore = new GeminiSEO($this->configTemporanea());
        $motore->updateConfig(['enabled' => true]);

        $esito = $motore->autoGenerateAndApply($this->datiSito(), static fn (): bool => true, true);

        self::assertFalse($esito['success']);
        self::assertStringContainsString('GEMINI_API_KEY', $esito['error']);
    }
}
