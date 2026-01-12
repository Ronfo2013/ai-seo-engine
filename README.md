# 🤖 AI-SEO Engine - Componente Standalone

**Data**: 12 Gennaio 2026  
**Versione**: 1.0.0  
**Stato**: In Sviluppo

---

## 📋 Descrizione

Componente PHP standalone per la generazione automatica di testi SEO tramite Google Gemini AI.

Questo componente è stato estratto dal progetto Arhena Portogruaro per essere sviluppato e testato separatamente.

---

## ⚠️ Problema Attuale

Il componente presenta un problema persistente con il parsing del JSON restituito da Gemini:

```
❌ JSON non valido: Control character error, possibly incorrectly encoded
```

### Tentativi Fatti

1. ✅ Pulizia caratteri di controllo ASCII 0-31
2. ✅ Normalizzazione newline (\\r\\n → \\n)
3. ✅ Rimozione newline dentro stringhe JSON con regex
4. ✅ Rimozione TUTTI i newline dal JSON
5. ❌ Nessuno ha risolto definitivamente

### Possibili Cause da Investigare

1. **Encoding UTF-8**: L'API Gemini potrebbe restituire caratteri in encoding diversi
2. **Emoji**: Gli emoji potrebbero causare problemi di parsing
3. **BOM (Byte Order Mark)**: Potrebbe esserci un BOM invisibile all'inizio del JSON
4. **Caratteri Unicode speciali**: Caratteri come zero-width space, non-breaking space, etc.

---

## 📁 Struttura File

```
ai-seo-component-standalone/
├── ai-seo-engine/
│   ├── GeminiSEO.php       # Classe principale
│   ├── integration.php     # File di integrazione (da adattare)
│   ├── cron.php           # Esecuzione automatica
│   └── config/
│       ├── seo-config.json # Configurazione
│       └── .htaccess       # Protezione accesso
└── README.md              # Questo file
```

---

## 🔧 Come Testare

### 1. Test Locale Base

```php
<?php
require_once 'ai-seo-engine/GeminiSEO.php';

use AISEOEngine\GeminiSEO;

$seo = new GeminiSEO();
$result = $seo->testConnection();

if ($result['success']) {
    echo "✅ Connessione OK: " . $result['message'];
} else {
    echo "❌ Errore: " . $result['error'];
}
```

### 2. Test Generazione SEO

```php
<?php
require_once 'ai-seo-engine/GeminiSEO.php';

use AISEOEngine\GeminiSEO;

$seo = new GeminiSEO();

// Configura API key
$seo->updateConfig([
    'api_key' => 'YOUR_GEMINI_API_KEY',
    'enabled' => true
]);

// Dati sito di test
$siteData = [
    'site_name' => 'Test Business',
    'seo' => [
        'title' => 'Test Title',
        'description' => 'Test Description',
        'keywords' => 'test, keywords'
    ],
    'contact' => [
        'address' => 'Via Test 1, Milano'
    ],
    'sections' => []
];

// Genera
$result = $seo->autoGenerateAndApply($siteData, function($data) {
    // Callback per salvare (non salva realmente in questo test)
    return true;
});

print_r($result);
```

---

## 🐛 Debug Approfondito

Per debuggare il problema JSON, aggiungi questo prima di `json_decode`:

```php
// Mostra hex dump del testo
echo "HEX: " . bin2hex(substr($text, 0, 100)) . "\n";

// Mostra ogni carattere con il suo codice
for ($i = 0; $i < min(50, strlen($text)); $i++) {
    $char = $text[$i];
    $ord = ord($char);
    echo "Pos $i: '$char' (ord: $ord)\n";
}
```

---

## 📝 TODO

- [ ] Investigare encoding UTF-8 della risposta Gemini
- [ ] Aggiungere rimozione BOM
- [ ] Testare con `mb_convert_encoding`
- [ ] Considerare uso di `iconv` per pulizia caratteri
- [ ] Testare con flag `JSON_INVALID_UTF8_IGNORE`
- [ ] Aggiungere unit test

---

## 🔗 Risorse

- [Google Gemini API](https://ai.google.dev/docs)
- [PHP json_decode](https://www.php.net/manual/en/function.json-decode.php)
- [JSON Control Characters](https://www.json.org/json-en.html)

---

## 📞 Note

Questo componente verrà sviluppato separatamente e reintegrato nel progetto Arhena quando sarà stabile.
