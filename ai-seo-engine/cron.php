<?php
declare(strict_types=1);

/**
 * ⏰ CRON JOB - Esecuzione Automatica SEO
 * 
 * Questo file viene eseguito automaticamente da:
 * - Cron job server (consigliato)
 * - Task scheduler
 * - Webhook esterno
 * 
 * SETUP CRON:
 * 0 3 * * * cd /path/to/arhena_site && GEMINI_API_KEY=... php ai-seo-engine/cron.php
 *
 * VARIABILI D'AMBIENTE:
 * - GEMINI_API_KEY      chiave API (obbligatoria)
 * - AI_SEO_CRON_SECRET  segreto per l'invocazione via web, min 32 caratteri
 *                       (non serve da CLI). Generane uno con:
 *                       php -r "echo bin2hex(random_bytes(32));"
 *
 * FLAG:
 * - --force  ignora l'attesa fra un run e il successivo
 */

require_once __DIR__ . '/integration.php';

// Se eseguito da CLI, non serve autenticazione
$isCLI = php_sapi_name() === 'cli';

// Se eseguito da web, verifica secret key
if (!$isCLI) {
    header('Content-Type: application/json');

    $validSecret = getenv('AI_SEO_CRON_SECRET');

    // Nessun segreto nell'ambiente = endpoint chiuso.
    // Un default nel sorgente non sarebbe un segreto: chiunque legga il file
    // lo ricava.
    if (!is_string($validSecret) || strlen($validSecret) < 32) {
        http_response_code(503);
        die(json_encode(['error' => 'Endpoint non configurato: manca AI_SEO_CRON_SECRET']));
    }

    // hash_equals: confronto a tempo costante, non rivela il segreto
    // un carattere alla volta.
    if (!hash_equals($validSecret, (string) ($_GET['secret'] ?? ''))) {
        http_response_code(403);
        die(json_encode(['error' => 'Non autorizzato']));
    }
}

// --force da CLI, force=1 da web (comunque dietro al segreto):
// scavalca l'attesa fra un run e il successivo.
$force = $isCLI
    ? in_array('--force', $argv ?? [], true)
    : (($_GET['force'] ?? '') === '1');

// Esegui aggiornamento
$integration = new ArhenaSEOIntegration();
$result = $integration->runAutoUpdate($force);

// Output
if ($isCLI) {
    echo "═══════════════════════════════════════════════\n";
    echo "   🤖 AI-SEO CRON JOB\n";
    echo "═══════════════════════════════════════════════\n\n";
    
    if ($result['success']) {
        echo "✅ SEO aggiornato con successo!\n\n";
        
        if (isset($result['applied'])) {
            echo "📝 Nuovo Titolo:\n";
            echo "   " . $result['applied']['title'] . "\n\n";
            echo "📄 Nuova Description:\n";
            echo "   " . $result['applied']['description'] . "\n\n";
            echo "🔑 Nuove Keywords:\n";
            echo "   " . $result['applied']['keywords'] . "\n\n";
        }
        
        $stats = $integration->getStats();
        echo "📊 Statistiche:\n";
        echo "   Ultima esecuzione: " . ($stats['last_run'] ?? 'Mai') . "\n";
        echo "   Prossima esecuzione: " . ($stats['next_run'] ?? 'N/A') . "\n";
        echo "   Modifiche totali: " . $stats['total_changes'] . "\n";
    } else {
        echo "❌ Errore: " . $result['error'] . "\n\n";
        
        if (isset($result['next_run'])) {
            echo "⏰ Prossima esecuzione: " . $result['next_run'] . "\n";
        }
    }
    
    echo "\n═══════════════════════════════════════════════\n";
} else {
    echo json_encode($result, JSON_PRETTY_PRINT);
}

exit($result['success'] ? 0 : 1);
