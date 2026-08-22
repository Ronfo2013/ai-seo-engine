<?php
declare(strict_types=1);

/**
 * 🤖 GEMINI SEO ENGINE - Componente Riutilizzabile
 * 
 * Sistema automatico di generazione e applicazione SEO tramite Google Gemini AI
 * Può essere integrato in qualsiasi sito PHP
 * 
 * @version 1.0.0
 * @author AI-SEO Engine
 * @license MIT
 */

namespace AISEOEngine;

class GeminiSEO
{
    /** Variabile d'ambiente da cui viene letta la chiave API. */
    private const API_KEY_ENV = 'GEMINI_API_KEY';

    private string $configFile;
    private array $config;
    private string $apiEndpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    public function __construct(?string $configPath = null)
    {
        $this->configFile = $configPath ?? __DIR__ . '/config/seo-config.json';
        $this->loadConfig();
    }

    /**
     * Carica configurazione
     */
    private function loadConfig(): void
    {
        if (!file_exists($this->configFile)) {
            $this->config = $this->getDefaultConfig();
            $this->saveConfig();
        } else {
            $this->config = json_decode(file_get_contents($this->configFile), true) ?? $this->getDefaultConfig();
        }

        $this->applyEnvironmentSecrets();
    }

    /**
     * I segreti vivono nell'ambiente, non nel file di configurazione: il file
     * è tracciato in git, l'ambiente no.
     */
    private function applyEnvironmentSecrets(): void
    {
        $envKey = getenv(self::API_KEY_ENV);

        if (is_string($envKey) && trim($envKey) !== '') {
            $this->config['api_key'] = trim($envKey);
            return;
        }

        // Nessuna chiave nell'ambiente: si continua con quella eventualmente
        // già presente nel file, che però saveConfig() non riscriverà mai.
        $this->config['api_key'] = is_string($this->config['api_key'] ?? null)
            ? $this->config['api_key']
            : '';
    }

    /**
     * Nome del modello ricavato dall'endpoint, così non può divergere da esso.
     */
    private function modelName(): string
    {
        return preg_match('#/models/([^:/?]+)#', $this->apiEndpoint, $matches) === 1
            ? $matches[1]
            : 'sconosciuto';
    }

    /**
     * `seo.business_type` può arrivare come stringa o come array. Normalizzarlo
     * in un solo punto evita il TypeError di in_array() su haystack stringa.
     */
    private function businessTypes(array $data): array
    {
        $types = $data['seo']['business_type'] ?? [];

        if (is_string($types)) {
            $types = explode(',', $types);
        }

        if (!is_array($types)) {
            return [];
        }

        $normalized = array_map(
            static fn ($type): string => is_scalar($type) ? trim((string) $type) : '',
            $types
        );

        return array_values(array_filter(
            $normalized,
            static fn (string $type): bool => $type !== ''
        ));
    }

    /**
     * Configurazione di default
     */
    private function getDefaultConfig(): array
    {
        return [
            'enabled' => false,
            'api_key' => '',
            'auto_apply' => true,
            'frequency_days' => 30,
            'last_run' => null,
            'last_applied' => null,
            'notification_email' => '',
            'instructions' => $this->getDefaultInstructions(),
            'context_rules' => [
                'include_location' => true,
                'include_business_type' => true,
                'include_seasonal' => true,
                'tone' => 'professional-friendly',
                'max_emojis' => 2
            ]
        ];
    }

    /**
     * Istruzioni di default per Gemini
     */
    private function getDefaultInstructions(): string
    {
        return <<<INSTRUCTIONS
Sei un esperto SEO specialist italiano specializzato in Local SEO.

OBIETTIVO:
Genera testi SEO ottimizzati che:
1. Aumentino il CTR su Google (click-through rate)
2. Posizionino per keywords locali rilevanti
3. Convertano visitatori in clienti
4. Siano autentici, non forzati o spam
5. Rispettino esattamente i limiti di caratteri

REGOLE ASSOLUTE:
- Titolo: ESATTAMENTE 50-60 caratteri (non uno di più!)
- Description: ESATTAMENTE 150-160 caratteri (non uno di più!)
- Keywords: 10-15 parole chiave realistiche (no keyword stuffing)
- Includi SEMPRE la località nel titolo
- Usa emoji SOLO nella description (max 1-2, solo se appropriate)
- Tono professionale ma accogliente
- NO false promesse, NO clickbait eccessivo
- Focus su benefici concreti per il cliente

STRATEGIA SEO:
- Keywords: Mix di brand + servizi + località + long-tail
- Title: Formula vincente = [Brand] + [Servizio Principale] + [Località] + [USP]
- Description: Formula = [Hook con emoji] + [Beneficio] + [Servizi] + [CTA]
- Considera la stagionalità (estate/inverno/eventi/festività)
INSTRUCTIONS;
    }

    /**
     * Analizza automaticamente il contenuto del sito
     */
    public function analyzeSiteContent(array $siteData): array
    {
        $context = [
            'business_name' => $siteData['site_name'] ?? $siteData['seo']['business_name'] ?? 'Business',
            'business_type' => $this->extractBusinessType($siteData),
            'location' => $this->extractLocation($siteData),
            'services' => $this->extractServices($siteData),
            'unique_points' => $this->extractUSP($siteData),
            'target_audience' => $this->inferTargetAudience($siteData),
            'current_seo' => [
                'title' => $siteData['seo']['title'] ?? '',
                'description' => $siteData['seo']['description'] ?? '',
                'keywords' => $siteData['seo']['keywords'] ?? ''
            ],
            'season' => $this->getCurrentSeason(),
            'month' => date('F'),
            'special_period' => $this->detectSpecialPeriod()
        ];

        return $context;
    }

    /**
     * Genera SEO automaticamente e applica se configurato
     */
    public function autoGenerateAndApply(array $siteData, callable $saveCallback, bool $force = false): array
    {
        // Verifica se abilitato
        if (!$this->config['enabled']) {
            return ['success' => false, 'error' => 'Sistema AI-SEO disabilitato'];
        }

        // Verifica API key
        if (empty($this->config['api_key'])) {
            return [
                'success' => false,
                'error' => 'API key non configurata: imposta la variabile d\'ambiente ' . self::API_KEY_ENV
            ];
        }

        // Verifica frequenza — $force la scavalca (esecuzione manuale da admin)
        if (!$force && !$this->shouldRun()) {
            return [
                'success' => false,
                'error' => 'Troppo presto per rigenerare',
                'next_run' => $this->getNextRunDate()
            ];
        }

        // Analizza contenuto
        $context = $this->analyzeSiteContent($siteData);

        // Genera con Gemini
        $result = $this->generateSEO($context);

        if (!$result['success']) {
            $this->log('generation_failed', $result);
            return $result;
        }

        $generatedSEO = $result['data'];

        // Se auto-apply è attivo, applica immediatamente
        if ($this->config['auto_apply']) {
            $applied = $this->applySEO($siteData, $generatedSEO, $saveCallback);

            if ($applied['success']) {
                $this->config['last_applied'] = date('Y-m-d H:i:s');
                $this->updateLastRun();
                $this->log('auto_applied', $generatedSEO);
                $this->sendNotification($generatedSEO, 'applied');
            }

            return $applied;
        }

        // Altrimenti salva solo come proposta
        $this->updateLastRun();
        $this->log('generated', $generatedSEO);
        $this->sendNotification($generatedSEO, 'pending');

        return [
            'success' => true,
            'data' => $generatedSEO,
            'auto_applied' => false,
            'message' => 'SEO generato, in attesa di approvazione manuale'
        ];
    }

    /**
     * Genera testi SEO con Gemini
     */
    private function generateSEO(array $context): array
    {
        $prompt = $this->buildPrompt($context);
        $response = $this->callGeminiAPI($prompt);

        if (!$response['success']) {
            return $response;
        }

        return $this->parseResponse($response['text']);
    }

    /**
     * Costruisce prompt intelligente per Gemini
     */
    private function buildPrompt(array $ctx): string
    {
        $instructions = $this->config['instructions'];
        $rules = $this->config['context_rules'];

        $prompt = $instructions . "\n\n";
        $prompt .= "═══════════════════════════════════════════════\n";
        $prompt .= "ANALISI AUTOMATICA DEL SITO\n";
        $prompt .= "═══════════════════════════════════════════════\n\n";

        $prompt .= "🏢 BUSINESS:\n";
        $prompt .= "- Nome: {$ctx['business_name']}\n";
        $prompt .= "- Tipo: {$ctx['business_type']}\n";

        if ($rules['include_location']) {
            $prompt .= "- Località: {$ctx['location']}\n";
        }

        $prompt .= "- Servizi offerti: {$ctx['services']}\n";
        $prompt .= "- Punti di forza: {$ctx['unique_points']}\n";
        $prompt .= "- Target: {$ctx['target_audience']}\n\n";

        if ($rules['include_seasonal']) {
            $prompt .= "🌍 CONTESTO TEMPORALE:\n";
            $prompt .= "- Stagione: {$ctx['season']}\n";
            $prompt .= "- Mese: {$ctx['month']}\n";
            if ($ctx['special_period']) {
                $prompt .= "- Periodo speciale: {$ctx['special_period']}\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "📊 SEO CORRENTE (da migliorare):\n";
        $prompt .= "- Title: {$ctx['current_seo']['title']}\n";
        $prompt .= "- Description: {$ctx['current_seo']['description']}\n";
        $prompt .= "- Keywords: {$ctx['current_seo']['keywords']}\n\n";

        $prompt .= "═══════════════════════════════════════════════\n";
        $prompt .= "GENERA JSON VALIDO (NO MARKDOWN, NO BACKTICKS)\n";
        $prompt .= "═══════════════════════════════════════════════\n\n";

        $prompt .= <<<JSON
{
  "title": "Titolo SEO di 50-60 caratteri ESATTI",
  "description": "Meta description di 150-160 caratteri ESATTI con CTA",
  "keywords": "keyword1, keyword2, keyword3, ... (10-15 keywords)",
  "reasoning": "Strategia SEO usata in 1 frase",
  "confidence": 95
}
JSON;

        $prompt .= "\n\nVERIFICA FINALE:\n";
        $prompt .= "- ✅ Titolo tra 52-58 caratteri (non oltre 65)\n";
        $prompt .= "- ✅ Description tra 150-155 caratteri (non oltre 165)\n";
        $prompt .= "- ✅ Località '{$ctx['location']}' presente nel titolo\n";
        $prompt .= "- ✅ JSON valido senza markdown\n";
        $prompt .= "- ✅ Emoji solo in description (max {$rules['max_emojis']})\n";

        return $prompt;
    }

    /**
     * Chiama API Gemini
     */
    private function callGeminiAPI(string $prompt): array
    {
        $url = $this->apiEndpoint . '?key=' . $this->config['api_key'];

        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.65,
                'topK' => 32,
                'topP' => 0.9,
                'maxOutputTokens' => 2048,
            ],
            'safetySettings' => [
                [
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => 'Errore di rete: ' . ($curlError !== '' ? $curlError : 'connessione non riuscita')];
        }

        if ($httpCode !== 200) {
            $error = json_decode($response, true)['error']['message'] ?? 'Errore API';
            return ['success' => false, 'error' => "Gemini API Error ({$httpCode}): {$error}"];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return ['success' => false, 'error' => 'Risposta API non valida: ' . json_last_error_msg()];
        }

        if (!empty($data['promptFeedback']['blockReason'])) {
            $reason = $data['promptFeedback']['blockReason'];
            $details = $data['promptFeedback']['safetyRatings'][0]['category'] ?? '';
            $message = 'Richiesta bloccata dalla policy di sicurezza' . ($details ? " ({$details})" : '');
            return ['success' => false, 'error' => $message . " [{$reason}]"];
        }

        if (empty($data['candidates'])) {
            return ['success' => false, 'error' => 'Risposta API senza candidati disponibili'];
        }

        $text = '';
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (!is_array($part)) {
                    continue;
                }
                if (!empty($part['text'])) {
                    $textCandidate = trim((string) $part['text']);
                    if ($textCandidate !== '') {
                        $text = $textCandidate;
                        break;
                    }
                }
            }
        }

        if ($text === '') {
            $finishReason = $data['candidates'][0]['finishReason'] ?? 'UNKNOWN';
            return ['success' => false, 'error' => 'Risposta API vuota (finishReason: ' . $finishReason . ')'];
        }

        return ['success' => true, 'text' => $text];
    }

    /**
     * Parse risposta JSON da Gemini
     */
    private function parseResponse(string $text): array
    {
        // Pulisci da markdown
        $text = preg_replace('/```json\s*|\s*```/', '', $text);
        $text = trim($text);

        // Rimuovi caratteri di controllo non validi
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        // APPROCCIO AGGRESSIVO: Rimuovi TUTTI i newline, tab e carriage return
        // Il JSON SEO non dovrebbe mai contenere questi caratteri
        $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);

        // Rimuovi spazi multipli
        $text = preg_replace('/\s+/', ' ', $text);

        $data = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMsg = json_last_error_msg();
            $preview = mb_substr($text, 0, 200);
            $this->log('json_parse_error', [
                'error' => $errorMsg,
                'text_preview' => $preview,
                'text_length' => strlen($text)
            ]);
            return [
                'success' => false,
                'error' => 'JSON non valido: ' . $errorMsg . ' (Preview: ' . $preview . '...)'
            ];
        }

        // Valida campi richiesti
        if (empty($data['title']) || empty($data['description']) || empty($data['keywords'])) {
            return ['success' => false, 'error' => 'Campi obbligatori mancanti'];
        }

        // Tronca se troppo lunghi
        $data['title'] = mb_substr($data['title'], 0, 60);
        $data['description'] = mb_substr($data['description'], 0, 160);

        return [
            'success' => true,
            'data' => $data,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Applica SEO generato ai dati del sito
     */
    private function applySEO(array &$siteData, array $generatedSEO, callable $saveCallback): array
    {
        // Backup vecchi valori
        $backup = [
            'title' => $siteData['seo']['title'] ?? '',
            'description' => $siteData['seo']['description'] ?? '',
            'keywords' => $siteData['seo']['keywords'] ?? '',
            'replaced_at' => date('Y-m-d H:i:s')
        ];

        // Applica nuovi valori
        $siteData['seo']['title'] = $generatedSEO['title'];
        $siteData['seo']['description'] = $generatedSEO['description'];
        $siteData['seo']['keywords'] = $generatedSEO['keywords'];

        // Salva tramite callback
        $saved = $saveCallback($siteData);

        if (!$saved) {
            return ['success' => false, 'error' => 'Impossibile salvare dati'];
        }

        // Salva nello storico
        $this->saveToHistory($backup, $generatedSEO);

        return [
            'success' => true,
            'message' => 'SEO applicato automaticamente con successo!',
            'applied' => $generatedSEO,
            'backup' => $backup
        ];
    }

    /**
     * Verifica se deve eseguire
     */
    private function shouldRun(): bool
    {
        if (empty($this->config['last_run'])) {
            return true;
        }

        $lastRun = strtotime($this->config['last_run']);
        $daysSince = (time() - $lastRun) / 86400;

        return $daysSince >= $this->config['frequency_days'];
    }

    /**
     * Aggiorna timestamp ultima esecuzione
     */
    private function updateLastRun(): void
    {
        $this->config['last_run'] = date('Y-m-d H:i:s');
        $this->saveConfig();
    }

    /**
     * Salva configurazione
     */
    private function saveConfig(): void
    {
        $dir = dirname($this->configFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // La chiave API non viene mai persistita: vive solo nell'ambiente.
        $persisted = $this->config;
        $persisted['api_key'] = '';

        file_put_contents(
            $this->configFile,
            json_encode($persisted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * Salva nello storico
     */
    private function saveToHistory(array $old, array $new): void
    {
        $historyFile = dirname($this->configFile) . '/history.json';

        // Lettura e riscrittura devono stare dentro lo stesso lock: cron e
        // admin possono girare insieme e l'ultimo che scrive vincerebbe.
        $handle = fopen($historyFile, 'c+');
        if ($handle === false) {
            $this->log('history_open_failed', ['file' => $historyFile]);
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                $this->log('history_lock_failed', ['file' => $historyFile]);
                return;
            }

            $raw = stream_get_contents($handle);
            $history = is_string($raw) && trim($raw) !== ''
                ? json_decode($raw, true)
                : null;

            if (!is_array($history) || !isset($history['entries']) || !is_array($history['entries'])) {
                $history = ['entries' => []];
            }

            $history['entries'][] = [
                'timestamp' => date('Y-m-d H:i:s'),
                'old' => $old,
                'new' => $new,
                'confidence' => $new['confidence'] ?? null
            ];

            // Mantieni solo ultimi 100
            if (count($history['entries']) > 100) {
                $history['entries'] = array_slice($history['entries'], -100);
            }

            $encoded = json_encode(
                $history,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($encoded !== false) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $encoded);
                fflush($handle);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }


    /**
     * Log attività
     */
    private function log(string $action, array $data): void
    {
        $logFile = dirname($this->configFile) . '/activity.log';

        // Usa flag per gestire meglio caratteri speciali
        $jsonData = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        // Se json_encode fallisce, usa una versione semplificata
        if ($jsonData === false) {
            $jsonData = json_encode([
                'error' => 'Failed to encode data',
                'json_error' => json_last_error_msg()
            ]);
        }

        $entry = sprintf(
            "[%s] %s: %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($action),
            $jsonData
        );

        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }


    /**
     * Invia notifica email
     */
    private function sendNotification(array $seo, string $status): void
    {
        if (empty($this->config['notification_email'])) {
            return;
        }

        $subject = $status === 'applied'
            ? '✅ SEO Aggiornato Automaticamente dall\'AI'
            : '🤖 Nuova Proposta SEO Generata';

        $message = sprintf(
            "SEO %s il %s\n\n" .
            "📝 Titolo: %s\n" .
            "📄 Description: %s\n" .
            "🔑 Keywords: %s\n" .
            "⭐ Confidence: %d%%\n" .
            "🎯 Strategia: %s\n",
            $status === 'applied' ? 'applicato' : 'generato',
            date('d/m/Y H:i'),
            $seo['title'],
            $seo['description'],
            $seo['keywords'],
            $seo['confidence'] ?? 0,
            $seo['reasoning'] ?? ''
        );

        @mail($this->config['notification_email'], $subject, $message, "From: noreply@seo-ai.local\r\nContent-Type: text/plain; charset=UTF-8");
    }

    // ═══════════════════════════════════════════════
    // METODI DI ESTRAZIONE INTELLIGENTE
    // ═══════════════════════════════════════════════

    private function extractBusinessType(array $data): string
    {
        $types = $this->businessTypes($data);

        if ($types === []) {
            return 'Attività locale';
        }

        return str_replace(
            ['Restaurant', 'BowlingAlley', 'NightClub'],
            ['Ristorante/Pizzeria', 'Bowling', 'Locale Notturno'],
            implode(' + ', $types)
        );
    }

    private function extractLocation(array $data): string
    {
        $address = $data['contact']['address'] ?? '';

        // Estrai città dall'indirizzo
        if (preg_match('/\b(Portogruaro|Venezia|Treviso|Udine)\b/i', $address, $matches)) {
            return $matches[1];
        }

        // Coordinate GPS
        if (!empty($data['seo']['geo_latitude'])) {
            return 'Portogruaro'; // Default se ha coordinate
        }

        return 'Italia';
    }

    private function extractServices(array $data): string
    {
        $services = [];

        foreach ($data['sections'] ?? [] as $section) {
            if (!empty($section['title'])) {
                $services[] = $section['title'];
            }
        }

        if (empty($services) && !empty($data['seo']['serves_cuisine'])) {
            $services = $data['seo']['serves_cuisine'];
        }

        return implode(', ', array_slice($services, 0, 5));
    }

    private function extractUSP(array $data): string
    {
        $usp = [];

        // Da tipo business
        $types = $this->businessTypes($data);
        if (in_array('BowlingAlley', $types))
            $usp[] = 'piste da bowling professionali';
        if (in_array('Restaurant', $types))
            $usp[] = 'cucina di qualità';
        if (in_array('NightClub', $types))
            $usp[] = 'intrattenimento serale';

        // Da sezioni
        foreach ($data['sections'] ?? [] as $section) {
            if (!empty($section['content']) && strlen($section['content']) > 50) {
                // Estrai prima frase significativa
                if (preg_match('/([A-Z][^.!?]{20,80}[.!?])/', $section['content'], $match)) {
                    $usp[] = strtolower(trim($match[1], '.!? '));
                }
            }
        }

        return implode(', ', array_slice($usp, 0, 3)) ?: 'esperienza unica';
    }

    private function inferTargetAudience(array $data): string
    {
        $types = $this->businessTypes($data);
        $audience = [];

        if (in_array('BowlingAlley', $types))
            $audience[] = 'gruppi di amici';
        if (in_array('Restaurant', $types))
            $audience[] = 'famiglie';
        if (in_array('NightClub', $types))
            $audience[] = 'giovani 18-35';

        $audience[] = 'eventi aziendali';

        return implode(', ', $audience);
    }

    private function getCurrentSeason(): string
    {
        $month = (int) date('n');

        if ($month >= 3 && $month <= 5)
            return 'primavera';
        if ($month >= 6 && $month <= 8)
            return 'estate';
        if ($month >= 9 && $month <= 11)
            return 'autunno';
        return 'inverno';
    }

    private function detectSpecialPeriod(): ?string
    {
        $month = (int) date('n');
        $day = (int) date('j');

        // Periodo natalizio
        if ($month === 12 || ($month === 1 && $day <= 6)) {
            return 'Natale e Capodanno';
        }

        // Estate
        if ($month >= 6 && $month <= 8) {
            return 'Stagione estiva';
        }

        // Carnevale (febbraio)
        if ($month === 2) {
            return 'Carnevale';
        }

        // Pasqua (variabile, approssimazione)
        if ($month === 3 || $month === 4) {
            return 'Pasqua';
        }

        return null;
    }

    private function getNextRunDate(): string
    {
        if (empty($this->config['last_run'])) {
            return 'Mai eseguito - Esegui ora';
        }

        $lastRun = strtotime($this->config['last_run']);
        $nextRun = $lastRun + ($this->config['frequency_days'] * 86400);

        return date('d/m/Y H:i', $nextRun);
    }

    // ═══════════════════════════════════════════════
    // METODI PUBBLICI PER ADMIN
    // ═══════════════════════════════════════════════

    public function updateConfig(array $updates): bool
    {
        foreach ($updates as $key => $value) {
            if (array_key_exists($key, $this->config)) {
                $this->config[$key] = $value;
            }
        }

        $this->saveConfig();
        return true;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function testConnection(): array
    {
        if (empty($this->config['api_key'])) {
            return [
                'success' => false,
                'error' => 'API key mancante: imposta la variabile d\'ambiente ' . self::API_KEY_ENV
            ];
        }

        $testPrompt = 'Rispondi SOLO con questo JSON: {"status":"ok","message":"Test riuscito"}';
        $result = $this->callGeminiAPI($testPrompt);

        return $result['success']
            ? ['success' => true, 'message' => 'Connessione a Gemini OK!', 'model' => $this->modelName()]
            : $result;
    }

    public function getStats(): array
    {
        $historyFile = dirname($this->configFile) . '/history.json';
        $history = file_exists($historyFile)
            ? json_decode(file_get_contents($historyFile), true)
            : ['entries' => []];

        return [
            'enabled' => $this->config['enabled'],
            'auto_apply' => $this->config['auto_apply'],
            'frequency_days' => $this->config['frequency_days'],
            'last_run' => $this->config['last_run'],
            'last_applied' => $this->config['last_applied'] ?? null,
            'next_run' => $this->getNextRunDate(),
            'total_changes' => count($history['entries']),
            'can_run_now' => $this->shouldRun()
        ];
    }

    public function getHistory(int $limit = 20): array
    {
        $historyFile = dirname($this->configFile) . '/history.json';

        if (!file_exists($historyFile)) {
            return [];
        }

        $history = json_decode(file_get_contents($historyFile), true);
        $entries = array_reverse($history['entries'] ?? []);

        return array_slice($entries, 0, $limit);
    }
}
