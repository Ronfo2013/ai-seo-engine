# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Cos'è questo repo

Componente PHP standalone che genera meta tag SEO (`title`, `description`,
`keywords`) chiamando Google Gemini, e li riscrive dentro il `content.json` di un
sito. È stato **estratto dal progetto Arhena Portogruaro** per essere sviluppato
in isolamento e reintegrato quando stabile.

Conseguenza pratica: **`integration.php` e `cron.php` non sono eseguibili in
questo repo**. `integration.php:11` fa `require_once __DIR__ . '/../functions.php'`
e usa `load_content()` / `save_content()`, che vivono nel sito ospite e qui non
esistono. Solo `GeminiSEO.php` è autosufficiente.

`docs/RENEWAL-PLAN.md` è il piano di evoluzione a v2.0 (audit + 6 fasi). Leggilo
prima di ristrutturare qualsiasi cosa: molti "difetti" del codice attuale sono
già censiti lì con riferimento `file:riga`, e diverse fasi prescrivono
esplicitamente cosa cancellare invece di correggere.

## Comandi

```bash
composer install                            # PHPUnit, PHPStan, PHP-CS-Fixer
composer test                               # la suite (nessun test tocca la rete)
composer analyse                            # PHPStan livello 8 (bloccante in CI)
composer lint                               # PHP-CS-Fixer in sola lettura
composer check                              # lint + analyse + test

vendor/bin/phpunit --filter testIlBackoffCresce          # un singolo test
vendor/bin/phpunit tests/Unit/RetryPolicyTest.php        # una singola classe
```

```bash
php -l ai-seo-engine/GeminiSEO.php          # syntax check (unico "lint" disponibile)
php ai-seo-engine/cron.php                  # entry point reale — richiede il sito ospite
php ai-seo-engine/cron.php --force          # ignora l'attesa fra un run e il successivo
```

Due variabili d'ambiente, entrambe fuori dal repo:

| Variabile | Serve a |
|---|---|
| `GEMINI_API_KEY` | Chiave API. Obbligatoria: senza, il motore si ferma prima di chiamare la rete |
| `AI_SEO_CRON_SECRET` | Autentica l'invocazione di `cron.php` via web (da CLI non serve). Minimo 32 caratteri, altrimenti l'endpoint risponde 503 e non esegue |

Per esercitare il motore in isolamento passa un `FakeHttpClient` al costruttore:
`new GeminiSEO($config, FakeHttpClient::respondingWith(200, $fixture))`. Le
fixture stanno in `tests/fixtures/gemini/` e riproducono i formati che hanno
rotto il parsing in passato.

**Nessun test deve toccare la rete.** Se scrivendone uno ti serve una
`GEMINI_API_KEY` vera, hai sbagliato la cucitura: passa dal `FakeHttpClient`,
oppure da `FakeTransport` se quello che stai provando è la politica di
ritentativo.

## Architettura

Un singolo flusso lineare, senza stati intermedi persistiti:

```
cron.php → ArhenaSEOIntegration::runAutoUpdate()
         → GeminiSEO::autoGenerateAndApply($siteData, $saveCallback)
             ├─ analyzeSiteContent()   estrae il contesto da $siteData
             ├─ buildPrompt()          assembla istruzioni + contesto + schema atteso
             ├─ callGeminiAPI()        delega a HttpClient (retry + backoff)
             ├─ parseResponse()        ripulisce il testo e fa json_decode
             └─ applySEO()             muta $siteData e invoca $saveCallback
```

Tre gate bloccano l'esecuzione prima ancora della chiamata API: `enabled`,
`api_key` non vuota, e `shouldRun()` (default: **30 giorni** dall'ultimo run).
Il terzo si scavalca con `$force`, che arriva fino in fondo alla catena:
`cron.php --force` → `runAutoUpdate(true)` → `autoGenerateAndApply(..., true)`.

### Il contratto su `$siteData`

Non è documentato da nessuna parte se non nei metodi `extract*()` (`GeminiSEO.php:573-673`).
La forma attesa è:

```
seo.title, seo.description, seo.keywords     ← letti e riscritti
seo.business_type                            ← stringa OPPURE array (vedi sotto)
seo.serves_cuisine, seo.geo_latitude         ← fallback opzionali
contact.address                              ← da cui si ricava la località
sections[].title, sections[].content         ← servizi e USP
```

`seo.business_type` può arrivare come stringa o come array: passa sempre da
`businessTypes()`, che normalizza in un punto solo. Non leggerlo mai
direttamente — era esattamente così che `extractUSP()` e `inferTargetAudience()`
sollevavano un TypeError fatale con una stringa.

### Il motore è specifico, non generico

Nonostante l'intestazione "Componente Riutilizzabile", tre metodi sono cablati su
Arhena: `extractLocation()` ha una regex con Portogruaro/Venezia/Treviso/Udine e
restituisce `'Portogruaro'` come default, mentre `extractUSP()` e
`inferTargetAudience()` conoscono solo bowling, ristorante e locale notturno.
Su un cliente diverso l'output è sbagliato **in silenzio**. Non aggiungere altri
casi hardcoded: la Fase 3 li sostituisce con un `profile.yaml` per cliente.

### Config = stato mutabile, non impostazioni

`config/seo-config.json` viene riscritto a runtime da `saveConfig()` (`last_run`,
`last_applied`), quindi **le tue modifiche manuali possono essere sovrascritte da
un run**. Non è tracciato in git: in repo c'è solo `seo-config.example.json`, e
`config/*.json` è ignorato. Accanto al file la classe crea `history.json` e
`activity.log`, che non esistono finché il motore non gira.

La chiave API non viene mai persistita: `applyEnvironmentSecrets()` la legge da
`GEMINI_API_KEY` a ogni costruzione e `saveConfig()` scrive sempre `""` al suo
posto. Se aggiungi un campo sensibile alla config, trattalo allo stesso modo.

Le scritture di config, storico e log usano `LOCK_EX`, e `saveToHistory()` fa
read-modify-write dentro un `flock()`: cron e admin possono girare insieme.

### Il livello HTTP

`ai-seo-engine/Http/` separa due responsabilità che nella v1 erano un unico
blocco di `curl_setopt_array`:

- `Transport` — un solo tentativo. `CurlTransport` è l'unico punto del progetto
  da cui esce un pacchetto.
- `HttpClient` — cosa fare quando un tentativo va male. `RetryingHttpClient`
  riprova su rete, 429, 408 e 5xx, **mai** su altri 4xx (una chiave sbagliata
  resta sbagliata), con backoff esponenziale più jitter e rispetto di
  `Retry-After` fino a un massimo di 60 secondi.

I due doppioni per i test sono `FakeHttpClient` (salta la politica, restituisce
risposte preparate, registra le richieste) e `FakeTransport` (fa passare la
politica vera senza rete). L'attesa è iniettabile via `Sleeper`: `RecordingSleeper`
annota quanto *avrebbe* dormito, così testare il backoff non richiede di
aspettarlo.

## Convenzioni

- `declare(strict_types=1)` in testa a ogni file PHP; namespace `AISEOEngine`,
  autoload PSR-4 da `ai-seo-engine/`. In testa a `GeminiSEO.php` restano dei
  `require_once` espliciti per le classi `Http/`: servono al sito ospite, che
  carica il file a mano e non ha ancora composer. Vanno via in Fase 2.
- Prompt, messaggi di errore, commenti e documentazione sono **in italiano**.
  L'output del modello è italiano per costruzione.
- Il modello è cablato in `GeminiSEO.php:21` (`gemini-2.5-flash`);
  `testConnection()` ne riporta un altro. Se cambi l'endpoint, allinea entrambi.
  **Il provider di destinazione è però l'API Claude** (`anthropic-ai/sdk`,
  `claude-opus-5`): non investire su Gemini, vedi Fase 2 del piano.
- `parseResponse()` sostituisce **tutti** i newline e i tab con spazi prima del
  `json_decode`. È una toppa al problema storico di parsing descritto nel README:
  la Fase 2 la elimina passando agli structured output, quindi non irrobustirla —
  sostituiscila.
