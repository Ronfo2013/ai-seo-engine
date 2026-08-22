<?php

declare(strict_types=1);

/**
 * 🔌 INTEGRAZIONE AI-SEO ENGINE CON ARHENA SITE
 * 
 * File di integrazione specifico per questo sito
 * Collega l'engine AI-SEO con content.json e functions.php
 */

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/GeminiSEO.php';

use AISEOEngine\GeminiSEO;

class ArhenaSEOIntegration
{
    private GeminiSEO $engine;
    
    public function __construct()
    {
        $this->engine = new GeminiSEO(__DIR__ . '/config/seo-config.json');
    }
    
    /**
     * Esegue aggiornamento SEO automatico
     * Chiamato da cron o manualmente dall'admin
     */
    public function runAutoUpdate(bool $force = false): array
    {
        // Carica dati sito
        $siteData = load_content();
        
        if (!$siteData) {
            return ['success' => false, 'error' => 'Impossibile caricare content.json'];
        }
        
        // Callback per salvare
        $saveCallback = function($data) {
            return save_content($data);
        };
        
        // Esegui generazione e applicazione automatica.
        // $force scavalca l'attesa fra un run e il successivo.
        $result = $this->engine->autoGenerateAndApply($siteData, $saveCallback, $force);
        
        return $result;
    }
    
    /**
     * Test connessione Gemini
     */
    public function testConnection(): array
    {
        return $this->engine->testConnection();
    }
    
    /**
     * Aggiorna configurazione
     */
    public function updateConfig(array $updates): bool
    {
        return $this->engine->updateConfig($updates);
    }
    
    /**
     * Ottieni configurazione corrente
     */
    public function getConfig(): array
    {
        return $this->engine->getConfig();
    }
    
    /**
     * Statistiche
     */
    public function getStats(): array
    {
        return $this->engine->getStats();
    }
    
    /**
     * Storico modifiche
     */
    public function getHistory(int $limit = 20): array
    {
        return $this->engine->getHistory($limit);
    }
}

// Funzione globale per uso rapido
function ai_seo_auto_update(bool $force = false): array
{
    $integration = new ArhenaSEOIntegration();
    return $integration->runAutoUpdate($force);
}
