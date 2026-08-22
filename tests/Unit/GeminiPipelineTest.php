<?php
declare(strict_types=1);

namespace AISEOEngine\Tests\Unit;

use AISEOEngine\GeminiSEO;
use AISEOEngine\Http\FakeHttpClient;
use AISEOEngine\Http\HttpResponse;
use AISEOEngine\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Il percorso completo, dalla risposta del modello ai meta tag scritti,
 * guidato dalle fixture. Ogni caso malformato qui dentro ha rotto il motore
 * almeno una volta: vedi tests/fixtures/gemini/LEGGIMI.md.
 */
final class GeminiPipelineTest extends TestCase
{
    /** @return array{0: GeminiSEO, 1: FakeHttpClient} */
    private function motoreCon(string $fixture, int $status = 200): array
    {
        putenv('GEMINI_API_KEY=chiave-di-test');
        $http = new FakeHttpClient(new HttpResponse($status, $this->fixture($fixture)));
        $motore = new GeminiSEO($this->configTemporanea(), $http);
        $motore->updateConfig(['enabled' => true]);

        return [$motore, $http];
    }

    /** @return array{0: array<string, mixed>, 1: ?array<string, mixed>} */
    private function esegui(GeminiSEO $motore): array
    {
        $salvato = null;
        $esito = $motore->autoGenerateAndApply(
            $this->datiSito(),
            function (array $dati) use (&$salvato): bool {
                $salvato = $dati;

                return true;
            },
            true
        );

        return [$esito, $salvato];
    }

    #[DataProvider('rispostePulite')]
    public function testIlParsingSopravviveAiFormatiStorici(string $fixture): void
    {
        [$motore] = $this->motoreCon($fixture);
        [$esito, $salvato] = $this->esegui($motore);

        self::assertTrue($esito['success'], (string) ($esito['error'] ?? ''));
        self::assertIsArray($salvato, 'Il callback di salvataggio non è stato invocato');
        self::assertSame('Bowling e Ristorante a Portogruaro | Arhena', $salvato['seo']['title']);
        self::assertStringContainsString('Portogruaro', $salvato['seo']['description']);
    }

    /** @return array<string, array{0: string}> */
    public static function rispostePulite(): array
    {
        return [
            'JSON pulito' => ['success-pulito.json'],
            'dentro un recinto markdown' => ['success-recinto-markdown.json'],
            'con a capo dentro le stringhe' => ['success-a-capo-nelle-stringhe.json'],
            'con caratteri di controllo' => ['success-caratteri-di-controllo.json'],
        ];
    }

    public function testIlVecchioValoreFinisceNelBackup(): void
    {
        [$motore] = $this->motoreCon('success-pulito.json');
        $this->esegui($motore);

        $storico = $motore->getHistory(1);

        self::assertCount(1, $storico);
        self::assertSame('Vecchio titolo', $storico[0]['old']['title']);
        self::assertSame('Bowling e Ristorante a Portogruaro | Arhena', $storico[0]['new']['title']);
    }

    public function testUnSalvataggioFallitoNonVieneDichiaratoRiuscito(): void
    {
        [$motore] = $this->motoreCon('success-pulito.json');

        $esito = $motore->autoGenerateAndApply(
            $this->datiSito(),
            static fn (): bool => false,   // il sito ospite rifiuta la scrittura
            true
        );

        self::assertFalse($esito['success']);
        self::assertSame([], $motore->getHistory(10), 'Niente storico se non si è salvato niente');
    }

    #[DataProvider('rispostiDaRifiutare')]
    public function testLeRisposteInutilizzabiliNonToccanoIlSito(
        string $fixture,
        int $status,
        string $frammentoErrore
    ): void {
        [$motore] = $this->motoreCon($fixture, $status);
        [$esito, $salvato] = $this->esegui($motore);

        self::assertFalse($esito['success']);
        self::assertStringContainsString($frammentoErrore, (string) $esito['error']);
        self::assertNull($salvato, 'Il callback di salvataggio non deve essere invocato');
    }

    /** @return array<string, array{0: string, 1: int, 2: string}> */
    public static function rispostiDaRifiutare(): array
    {
        return [
            'campi obbligatori mancanti' => ['success-campi-mancanti.json', 200, 'Campi obbligatori mancanti'],
            'rate limit' => ['errore-429.json', 429, 'Gemini API Error (429)'],
            'chiave non valida' => ['errore-400-chiave.json', 400, 'Gemini API Error (400)'],
            'errore del server' => ['errore-500.json', 500, 'Gemini API Error (500)'],
            'bloccato per sicurezza' => ['bloccato-sicurezza.json', 200, 'bloccata dalla policy'],
            'nessun candidato' => ['senza-candidati.json', 200, 'senza candidati'],
            'testo vuoto' => ['risposta-vuota.json', 200, 'Risposta API vuota'],
        ];
    }

    public function testUnErroreDiReteVieneRiportatoConIlNumeroDiTentativi(): void
    {
        putenv('GEMINI_API_KEY=chiave-di-test');
        $http = new FakeHttpClient(
            HttpResponse::networkFailure('Could not resolve host', 3)
        );
        $motore = new GeminiSEO($this->configTemporanea(), $http);
        $motore->updateConfig(['enabled' => true]);

        [$esito] = $this->esegui($motore);

        self::assertFalse($esito['success']);
        self::assertStringContainsString('3 tentativi', (string) $esito['error']);
        self::assertStringContainsString('Could not resolve host', (string) $esito['error']);
    }

    /**
     * B3, ancora aperto. Questo test **fotografa il difetto**, non lo approva:
     * un titolo troppo lungo viene tagliato a metà parola invece che
     * rigenerato. Quando la Fase 2 introdurrà il RepairLoop, questo test
     * diventerà rosso — ed è esattamente il segnale che serve.
     */
    public function testB3IlTitoloTroppoLungoVieneAncoraTagliatoAMetaParola(): void
    {
        [$motore] = $this->motoreCon('success-title-troppo-lungo.json');
        [$esito, $salvato] = $this->esegui($motore);

        self::assertTrue($esito['success']);
        self::assertIsArray($salvato);
        self::assertSame(60, mb_strlen($salvato['seo']['title']), 'Tagliato al 60esimo carattere, punto');
        self::assertStringEndsWith(
            'Eventi e',
            $salvato['seo']['title'],
            'Il titolo pubblicato finisce con una congiunzione appesa nel vuoto: è B3'
        );
        self::assertStringNotContainsString(
            'Feste Private',
            $salvato['seo']['title'],
            'Il taglio butta via contenuto senza che nessuno se ne accorga'
        );
    }

    public function testIlPromptContieneIlContestoDelSito(): void
    {
        [$motore, $http] = $this->motoreCon('success-pulito.json');
        $this->esegui($motore);

        $prompt = $http->lastRequest()['payload']['contents'][0]['parts'][0]['text'];

        self::assertStringContainsString('Portogruaro', $prompt);
        self::assertStringContainsString('Bowling', $prompt);
        self::assertStringContainsString('Vecchio titolo', $prompt, 'Il SEO attuale serve al modello come punto di partenza');
    }

    public function testLaChiaveViaggiaNellaUrlMaNonNelCorpo(): void
    {
        [$motore, $http] = $this->motoreCon('success-pulito.json');
        $this->esegui($motore);

        $richiesta = $http->lastRequest();

        self::assertStringContainsString('key=chiave-di-test', $richiesta['url']);
        self::assertStringNotContainsString('chiave-di-test', (string) json_encode($richiesta['payload']));
    }
}
