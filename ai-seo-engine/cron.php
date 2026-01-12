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
 * 0 3 * * * cd /path/to/arhena_site && php ai-seo-engine/cron.php
 */

require_once __DIR__ . '/integration.php';

// Se eseguito da CLI, non serve autenticazione
$isCLI = php_sapi_name() === 'cli';

// Se eseguito da web, verifica secret key
if (!$isCLI) {
    $secretKey = $_GET['secret'] ?? '';
    $validSecret = hash('sha256', 'arhena_seo_cron_2025'); // Cambia questo!
    
    if ($secretKey !== $validSecret) {
        http_response_code(403);
        die(json_encode(['error' => 'Non autorizzato']));
    }
    
    header('Content-Type: application/json');
}

// Esegui aggiornamento
$integration = new ArhenaSEOIntegration();
$result = $integration->runAutoUpdate();

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
