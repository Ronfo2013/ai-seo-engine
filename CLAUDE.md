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

Non esistono composer, autoload, test runner, linter o CI: la Fase 1 del piano
serve esattamente a introdurli. Nel frattempo:

```bash
php -l ai-seo-engine/GeminiSEO.php          # syntax check (unico "lint" disponibile)
php ai-seo-engine/cron.php                  # entry point reale — richiede il sito ospite
```

Per esercitare il motore in isolamento, istanzia direttamente la classe e passa
il tuo `$saveCallback`; il `README.md` contiene gli snippet di riferimento per
`testConnection()` e `autoGenerateAndApply()`. Serve una `GEMINI_API_KEY` valida:
non ci sono fixture registrate né fake provider, quindi **ogni prova tocca la
rete e consuma quota**.

## Architettura

Un singolo flusso lineare, senza stati intermedi persistiti:

```
cron.php → ArhenaSEOIntegration::runAutoUpdate()
         → GeminiSEO::autoGenerateAndApply($siteData, $saveCallback)
             ├─ analyzeSiteContent()   estrae il contesto da $siteData
             ├─ buildPrompt()          assembla istruzioni + contesto + schema atteso
             ├─ callGeminiAPI()        curl grezzo, nessun retry
             ├─ parseResponse()        ripulisce il testo e fa json_decode
             └─ applySEO()             muta $siteData e invoca $saveCallback
```

Tre gate bloccano l'esecuzione prima ancora della chiamata API: `enabled`,
`api_key` non vuota, e `shouldRun()` (default: **30 giorni** dall'ultimo run).
Quando stai testando, il terzo è quello che ti farà perdere tempo — e
`runAutoUpdate($force)` accetta un flag che **non viene mai usato**, quindi non
esiste ancora un modo pulito di bypassarlo.

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

`extractBusinessType()` accetta sia stringa che array, ma `extractUSP()` e
`inferTargetAudience()` fanno `in_array()` assumendo un array: con una stringa
sollevano un **TypeError fatale** su PHP 8. È il primo fix della Fase 0.

### Il motore è specifico, non generico

Nonostante l'intestazione "Componente Riutilizzabile", tre metodi sono cablati su
Arhena: `extractLocation()` ha una regex con Portogruaro/Venezia/Treviso/Udine e
restituisce `'Portogruaro'` come default, mentre `extractUSP()` e
`inferTargetAudience()` conoscono solo bowling, ristorante e locale notturno.
Su un cliente diverso l'output è sbagliato **in silenzio**. Non aggiungere altri
casi hardcoded: la Fase 3 li sostituisce con un `profile.yaml` per cliente.

### Config = stato mutabile, non impostazioni

`config/seo-config.json` è **tracciato in git** e viene riscritto a runtime da
`saveConfig()` (`last_run`, `last_applied`). Due conseguenze: le modifiche
manuali possono essere sovrascritte da un run, e appena qualcuno configura una
`api_key` reale questa finisce in un commit. Accanto al file la classe crea
`history.json` e `activity.log`, che non esistono finché il motore non gira.

Anche il segreto del cron è nel sorgente: `cron.php:24` calcola
`hash('sha256', 'arhena_seo_cron_2025')`, quindi chiunque legga il file può
derivarlo.

## Convenzioni

- `declare(strict_types=1)` in testa a ogni file PHP; namespace `AISEOEngine`;
  nessun autoload, i file si tirano con `require_once`.
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
