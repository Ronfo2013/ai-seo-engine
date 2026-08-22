# 🔄 Piano di Rinnovamento — AI-SEO Engine

**Versione documento**: 1.0
**Data**: 22 agosto 2026
**Stato codice analizzato**: `a428ba1` — v1.0.0 (gennaio 2026, 983 righe, 5 file)
**Target**: v2.0.0

---

## 1. Sintesi esecutiva

Il motore attuale fa una cosa sola: chiede a Gemini un JSON con `title`,
`description`, `keywords` per **l'intero sito**, e lo scrive dentro `content.json`
una volta al mese. Funziona come proof-of-concept, ma nel 2026 ha tre problemi
strutturali:

1. **Combatte un problema che oggi non esiste più.** Tutto il `README` è dedicato
   al parsing fragile del JSON restituito dal modello. Gli structured output
   (`responseSchema` su Gemini, tool-use su Claude) rendono quel codice
   interamente cancellabile: il modello non può più restituire markdown o
   caratteri di controllo.
2. **Ottimizza per una SERP che non è più quella dominante.** Genera `meta
   keywords` (ignorate da Google dal 2009) e non produce nulla di quello che
   conta per AI Overviews, assistenti e retrieval: dati strutturati JSON-LD,
   blocchi FAQ, gerarchia di heading, copertura entità.
3. **È un generatore cieco, non un ottimizzatore.** Scrive i meta tag in
   produzione senza sapere se il CTR è salito o sceso. Il campo `confidence: 95`
   è autodichiarato dal modello: non è una misura.

Il piano porta il motore da **generatore one-shot** a **ottimizzatore
closed-loop, multi-pagina e provider-agnostico**, in 6 fasi da ~20-32 giorni-uomo
complessivi, con la Fase 0 (sicurezza + crash) da fare comunque e subito.

---

## 2. Diagnosi dello stato attuale

### 2.1 Cosa funziona e va conservato

| Elemento | Perché tenerlo |
|---|---|
| Separazione `GeminiSEO` / `integration.php` / `cron.php` | Il confine engine-vs-sito è corretto: è la base per il multi-tenant |
| Callback di salvataggio (`$saveCallback`) | Disaccoppia il motore dallo storage del sito. Ottima scelta |
| Storico con backup del valore precedente (`saveToHistory`) | È già metà di un sistema di rollback |
| Contesto stagionale (`getCurrentSeason`, `detectSpecialPeriod`) | Idea giusta: la stagionalità conta davvero nel local SEO |
| `declare(strict_types=1)` ovunque | Base sana su cui costruire tipizzazione seria |

### 2.2 Bug confermati (non teorici)

| # | File:riga | Problema | Impatto |
|---|---|---|---|
| B1 | `GeminiSEO.php:641-646`, `662-669` | `in_array('BowlingAlley', $types)` con `$types` stringa → **TypeError fatale**. `extractBusinessType` (riga 573) gestisce sia stringa che array, questi due metodi no | Crash del cron su qualsiasi sito che abbia `business_type` come stringa |
| B2 | `integration.php:29`, `91` | Il parametro `$force` è dichiarato e **mai usato** | Il pulsante "Esegui ora" dell'admin non può bypassare il blocco dei 30 giorni |
| B3 | `GeminiSEO.php:406-407` | `mb_substr($title, 0, 60)` tronca a metà parola invece di rigenerare | Meta tag pubblicati mutilati ("...a Portogruaro - Divertim") |
| B4 | `GeminiSEO.php:763` | `testConnection()` riporta `gemini-1.5-flash` mentre l'endpoint (riga 21) usa `gemini-2.5-flash` | Diagnostica che mente |
| B5 | `GeminiSEO.php:118` | `date('F')` produce `August`, iniettato in un prompt interamente in italiano | Rumore nel contesto, degrado qualità |
| B6 | `GeminiSEO.php:485`, `499`, `521` | `file_put_contents` su config/history/log **senza `LOCK_EX`**, con pattern read-modify-write | Cron + admin in concorrenza → config o storico corrotti |
| B7 | `GeminiSEO.php:511` | `activity.log` cresce senza rotazione né limite | Disco pieno sul lungo periodo |
| B8 | `GeminiSEO.php:401-404` | Nessuna validazione di tipo: se il modello restituisce `keywords` come array invece che stringa, finisce così com'è in `content.json` | Meta tag rotto in produzione |
| B9 | `GeminiSEO.php:170-179` | Se `applySEO` fallisce, non si logga nulla e non si aggiorna `last_run` | Fallimenti silenziosi, retry infiniti |
| B10 | `GeminiSEO.php:648` | `preg_match('/([A-Z][^.!?]{20,80}[.!?])/')` senza modificatore `/u` su testo UTF-8 | Estrazione USP può spezzare caratteri accentati |

### 2.3 Rischi di sicurezza

| # | Dove | Problema |
|---|---|---|
| S1 | `config/seo-config.json` (**tracciato in git**) | `saveConfig()` scrive la `api_key` in chiaro in un file versionato. Oggi è vuota; al primo salvataggio reale la chiave finisce in un commit |
| S2 | `cron.php:24` | `hash('sha256', 'arhena_seo_cron_2025')` — il segreto è **calcolabile da chiunque legga il sorgente**. Non è un segreto, è una costante |
| S3 | `cron.php:26` | Confronto con `!==` invece di `hash_equals()` → vulnerabile a timing attack (secondario rispetto a S2) |
| S4 | `config/.htaccess` | Mescola sintassi Apache 2.2 (`Order Allow,Deny`) e 2.4 (`Require all denied`). Su Apache 2.4 senza `mod_access_compat` la direttiva legacy causa **errore 500**, non protezione |
| S5 | `GeminiSEO.php:559` | `@mail()` con errore soppresso: nessun modo di sapere che le notifiche non partono |

### 2.4 Debito di riusabilità

Il file si dichiara "Componente Riutilizzabile" (riga 5), ma:

- `extractLocation` (`:591`) ha **una regex hardcoded** con Portogruaro, Venezia,
  Treviso, Udine — e restituisce `'Portogruaro'` come default se ci sono
  coordinate GPS qualsiasi.
- `extractUSP` e `inferTargetAudience` hardcodano bowling, ristorante e locale
  notturno.
- `extractBusinessType` traduce a mano tre tipi Schema.org.

Su un cliente diverso da Arhena il motore produce output sbagliato in modo
silenzioso. **Non è riutilizzabile: è specifico travestito da generico.**

### 2.5 Debito infrastrutturale

- Nessun `composer.json`, nessun autoload PSR-4, `require_once` a mano.
- **Zero test** (già nel TODO del README di gennaio).
- Nessuna CI, nessun analizzatore statico, nessun linter.
- `curl` grezzo senza retry, senza backoff su 429/503, timeout fisso 30s.
- Nessun conteggio token, nessun tracciamento costi.

---

## 3. Cosa è cambiato nel frattempo

### 3.1 Capacità dei modelli oggi sfruttabili

| Capacità | Cosa risolve qui |
|---|---|
| **Structured output / JSON Schema** (`responseMimeType` + `responseSchema` su Gemini, tool-use su Claude) | Elimina **tutto** `parseResponse` (`:369-413`) e il problema storico del README. Il modello non può fisicamente restituire markdown |
| **Tool use / function calling** | Il motore può *chiedere* dati durante la generazione: leggere Search Console, controllare le SERP, contare i caratteri con un tool deterministico |
| **Extended thinking** | Da usare solo nello step di *strategia* (una volta per ciclo), non nella generazione dei singoli campi. Costo mirato |
| **Prompt caching** | Le istruzioni SEO (~1.500 token) sono identiche a ogni run: cachearle taglia il costo per pagina in modo sostanziale, decisivo appena si passa al multi-pagina |
| **Batch processing** | 200 pagine da ottimizzare non sono 200 chiamate sincrone: sono un batch notturno a costo ridotto |
| **Model routing** | Estrazione/classificazione con un modello piccolo e veloce; strategia e copy con un modello forte. Oggi tutto passa da un solo modello |
| **Embeddings** | Clustering keyword, rilevamento cannibalizzazione tra pagine, gap di copertura entità: cose che il prompt da solo non fa |
| **MCP** | Search Console, Analytics e il CMS esposti come server MCP invece che come integrazioni cablate a mano |

### 3.2 Cosa è cambiato nella SEO stessa

- **Le `meta keywords` non servono.** Google le ignora dal 2009. Il motore oggi
  spende prompt, token e validazione per generarne 10-15. Vanno mantenute solo
  come *input interno* per il clustering, non come output pubblicato.
- **AEO/GEO.** Una quota crescente delle query si esaurisce dentro AI Overviews e
  assistenti conversazionali senza click. Ciò che rende un contenuto citabile è
  diverso da ciò che lo rendeva cliccabile: risposte autosufficienti,
  dati strutturati, entità esplicite, fonti.
- **Dati strutturati come leva primaria.** JSON-LD `LocalBusiness`, `FAQPage`,
  `Menu`, `Event`, `OpeningHoursSpecification` sono il canale più diretto verso
  rich result e knowledge panel. Il motore oggi non ne genera nemmeno uno.
- **`llms.txt`** è una proposta interessante ma **non uno standard adottato**: la
  metto in fondo alla lista, esplicitamente come esperimento a basso costo, non
  come pilastro.
- **Larghezza in pixel > numero di caratteri.** Il vincolo "50-60 caratteri"
  ripetuto 4 volte nel prompt è un'approssimazione: Google tronca a ~580px. Una
  `W` maiuscola occupa quasi il triplo di una `i`. Va sostituito con una misura
  reale in pixel, fatta in PHP, non chiesta al modello.

---

## 4. Architettura target (v2.0)

```
ai-seo-engine/
├── composer.json                 # PSR-4, PHP >= 8.3
├── src/
│   ├── Engine/
│   │   ├── SeoEngine.php         # orchestratore (ex GeminiSEO, dimagrito)
│   │   ├── OptimizationCycle.php # analizza → genera → valida → applica → misura
│   │   └── Scheduler.php         # sostituisce shouldRun/frequency_days
│   ├── Provider/
│   │   ├── LlmProvider.php       # interfaccia
│   │   ├── GeminiProvider.php
│   │   ├── ClaudeProvider.php
│   │   └── FakeProvider.php      # per i test, nessuna rete
│   ├── Schema/
│   │   ├── SeoBundleSchema.php   # JSON Schema condiviso prompt+validazione
│   │   └── JsonLd/               # LocalBusiness, FAQPage, Menu, Event
│   ├── Context/
│   │   ├── SiteProfile.php       # SOSTITUISCE gli extract*() hardcoded
│   │   ├── ProfileLoader.php     # da profile.yaml per cliente
│   │   └── SeasonalContext.php   # conservato, generalizzato
│   ├── Validation/
│   │   ├── PixelWidth.php        # larghezza SERP reale
│   │   ├── BundleValidator.php   # vincoli deterministici
│   │   └── RepairLoop.php        # rigenera invece di troncare (B3)
│   ├── Metrics/
│   │   ├── SearchConsoleClient.php
│   │   └── ImpactReport.php      # before/after su CTR e posizione
│   ├── Storage/
│   │   ├── ConfigStore.php       # con LOCK_EX (B6)
│   │   ├── HistoryStore.php      # append-only + rollback
│   │   └── SecretResolver.php    # env-first, mai su disco (S1)
│   └── Notify/
│       ├── Notifier.php          # interfaccia
│       ├── WebhookNotifier.php   # Slack/Discord
│       └── MailNotifier.php      # senza @ (S5)
├── bin/
│   ├── seo-run                   # ex cron.php, CLI-only
│   ├── seo-preview               # dry-run con diff, non scrive nulla
│   └── seo-rollback              # ripristina da history
├── config/
│   ├── profile.example.yaml
│   └── .htaccess                 # corretto (S4)
└── tests/
    ├── Unit/
    ├── Integration/
    └── fixtures/                 # risposte modello registrate
```

**Principio guida**: il modello genera, **il codice valida**. Ogni vincolo
esprimibile in modo deterministico (lunghezza, pixel, presenza località,
validità JSON-LD, unicità tra pagine) esce dal prompt ed entra in
`Validation/`. Il prompt resta per ciò che solo il modello sa fare: la scrittura.

---

## 5. Piano per fasi

### Fase 0 — Messa in sicurezza *(1-2 giorni · da fare comunque)*

Non richiede scelte architetturali: sono difetti da chiudere a prescindere dal
resto del piano.

- [ ] Fix **B1** (crash `in_array` su stringa) — normalizzare `business_type` a array in un solo punto
- [ ] Fix **S1**: `api_key` letta da `getenv('GEMINI_API_KEY')`, rimossa dal JSON, aggiungere `config/*.json` a `.gitignore`
- [ ] Fix **S2/S3**: segreto cron da variabile d'ambiente + `hash_equals()`
- [ ] Fix **S4**: `.htaccess` solo sintassi Apache 2.4
- [ ] Fix **B6**: `LOCK_EX` su tutte le scritture di stato
- [ ] Fix **B2** (`$force` ignorato) e **B4** (modello riportato errato)
- [ ] Aggiungere `config/.gitignore` e ruotare la chiave API se è mai stata committata

**Deliverable**: nessun segreto in repo, nessun crash noto, endpoint cron non indovinabile.

---

### Fase 1 — Fondamenta di progetto *(3-5 giorni)*

- [ ] `composer.json`, autoload PSR-4, PHP ≥ 8.3
- [ ] PHPStan livello 8 + PHP-CS-Fixer
- [ ] PHPUnit con `FakeProvider`: **nessun test deve toccare la rete**
- [ ] Fixture: 15-20 risposte modello reali registrate, incluse quelle malformate storiche del README — diventano casi di regressione
- [ ] GitHub Actions: lint + PHPStan + test su ogni push
- [ ] Astrazione HTTP con retry e backoff esponenziale su 429/503

**Deliverable**: si può rifattorizzare senza paura. Prerequisito reale di tutto il resto.

---

### Fase 2 — Structured output e provider-agnostico *(3-4 giorni)*

- [ ] Definire `SeoBundleSchema` come JSON Schema unico, usato sia per istruire il modello sia per validare la risposta
- [ ] `GeminiProvider` con `responseMimeType: application/json` + `responseSchema`
- [ ] `ClaudeProvider` con tool-use per l'output tipizzato
- [ ] **Cancellare `parseResponse` (`:369-413`)** — le 40 righe di pulizia caratteri, la rimozione dei newline, il fix del markdown. Il problema storico del README sparisce per costruzione, non per patch
- [ ] `RepairLoop`: se la validazione fallisce, rigenerare con il messaggio d'errore in contesto (max 2 tentativi), **mai troncare** (B3)
- [ ] `PixelWidth`: misura reale della larghezza SERP, sostituisce i vincoli in caratteri
- [ ] Prompt caching sul blocco istruzioni
- [ ] Conteggio token e costo per run, salvato nello storico

**Deliverable**: zero errori di parsing. Cambio provider = una riga di config.

---

### Fase 3 — Da SEO 2025 ad AEO 2026 *(5-8 giorni)*

Il salto di valore vero.

- [ ] **`SiteProfile`**: elimina `extractLocation`, `extractUSP`,
      `inferTargetAudience`, `extractBusinessType` hardcoded (`:573-673`).
      Località, tipo attività, servizi, target e USP diventano un
      `profile.yaml` per cliente. Il motore diventa **davvero** riutilizzabile
- [ ] Estendere l'output da 3 campi al **bundle completo**:
  - `title`, `description` (validati in pixel)
  - **`json_ld`**: `LocalBusiness` + `OpeningHoursSpecification` + `FAQPage`
  - `open_graph` / `twitter_card`
  - `heading_outline` (H1 + H2 suggeriti)
  - `faq[]`: 4-6 coppie Q&A, il formato più citabile dagli assistenti
  - `image_alt[]` per le immagini principali
  - `internal_links[]`: suggerimenti di collegamento tra pagine
- [ ] **Multi-pagina**: da un titolo per sito a un bundle per URL, con
      rilevamento della cannibalizzazione tra pagine via embeddings
- [ ] Rimuovere `keywords` dall'output pubblicato; mantenerle come segnale interno
- [ ] Validatore JSON-LD contro lo schema Schema.org prima della pubblicazione

**Deliverable**: il motore produce ciò che nel 2026 muove davvero la visibilità.

---

### Fase 4 — Closed loop sui dati reali *(5-8 giorni)*

Oggi il motore genera al buio. Qui impara.

- [ ] `SearchConsoleClient`: impression, click, CTR, posizione media per query e per URL
- [ ] **Iniettare i dati reali nel prompt**: "questa pagina è in posizione 8,4 su
      *bowling portogruaro* con CTR 1,2% contro un 3,1% atteso per quella
      posizione" è un input incomparabilmente più forte di qualsiasi istruzione generica
- [ ] `ImpactReport`: confronto a 14/28 giorni tra il periodo pre e post modifica
- [ ] **Rollback automatico** se il CTR peggiora oltre soglia oltre il rumore statistico
- [ ] Sostituire il `confidence` autodichiarato dal modello con l'impatto misurato
- [ ] Priorità di intervento: agire prima sulle pagine ad alte impression e CTR basso, non su tutte allo stesso modo

**Deliverable**: il ciclo si chiude. Ogni modifica ha un esito misurato, non un'opinione.

---

### Fase 5 — Governance e operatività *(3-5 giorni)*

- [ ] `bin/seo-preview`: dry-run con **diff leggibile**, non scrive nulla
- [ ] Workflow di approvazione: `auto_apply` passa a `false` di default per i siti in produzione
- [ ] `bin/seo-rollback`: ripristino da storico (i backup ci sono già dalla v1, manca il comando)
- [ ] Notifiche via webhook Slack/Discord con il diff, oltre alla mail
- [ ] Rotazione log (B7) e storico append-only
- [ ] Dashboard minimale: ultimo run, impatto, prossima esecuzione, costo mensile

**Deliverable**: gestibile da chi non ha scritto il codice.

---

### Fase 6 — Sperimentale *(opzionale, da valutare dopo la Fase 4)*

- [ ] Generazione `llms.txt` — costo bassissimo, adozione incerta: da fare *solo* come esperimento dichiarato
- [ ] Batch API per ottimizzazioni massive notturne
- [ ] Multi-tenant: un'installazione, N siti clienti
- [ ] Server MCP che espone il motore come tool

---

## 6. Metriche di successo

| Metrica | Oggi | Target v2.0 |
|---|---|---|
| Errori di parsing JSON | ricorrenti (tema centrale del README) | **0** — impossibili per costruzione |
| Copertura test | 0% | ≥ 70% sulla logica core |
| Campi SEO prodotti | 3 | 8+ incluso JSON-LD |
| Granularità | 1 sito | 1 bundle per URL |
| Segreti in repo | rischio attivo | 0 |
| Riusabilità su nuovo cliente | riscrittura di 4 metodi | un `profile.yaml` |
| Misura d'impatto | nessuna | CTR before/after da Search Console |
| Costo per run | non tracciato | tracciato, con caching |

---

## 7. Sequenza consigliata e rischi

**Ordine**: 0 → 1 → 2 → 3 → 4 → 5. Le Fasi 0-2 sono prerequisiti tecnici; la
Fase 3 è dove sta il valore percepito dal cliente; la Fase 4 è dove il motore
smette di essere un generatore e diventa un ottimizzatore.

Se il tempo è poco, **il minimo utile è 0 + 2 + 3**: sicurezza, fine del
problema JSON, e output che ha senso nel 2026. La Fase 4 senza la Fase 1 (test)
è sconsigliata: un rollback automatico non testato è più pericoloso dell'assenza
di rollback.

**Rischi da tenere d'occhio**

| Rischio | Mitigazione |
|---|---|
| Search Console API ha ~2-3 giorni di latenza sui dati | Finestre di valutazione a 14/28 giorni, mai giornaliere |
| Il traffico local è troppo basso per la significatività statistica | Soglie minime di impression prima di dichiarare un impatto; sotto soglia, nessun rollback automatico |
| L'output esteso (Fase 3) moltiplica i token | Prompt caching (Fase 2) prima della Fase 3, non dopo |
| JSON-LD errato può causare penalizzazioni | Validazione contro Schema.org obbligatoria pre-pubblicazione |
| Riscrittura totale = mesi persi | Il piano è incrementale: `GeminiSEO` resta funzionante fino alla Fase 2 |

---

## 8. Decisioni aperte

1. **Provider**: si resta su Gemini, si passa a Claude, o si tiene l'astrazione con entrambi? *(L'astrazione costa poco e vale come assicurazione.)*
2. **Multi-tenant ora o dopo?** Cambia la forma di `profile.yaml` in Fase 3: meglio deciderlo prima di scriverlo.
3. **Chi approva le modifiche SEO?** Determina se la Fase 5 è un CLI o una UI web.
4. **Il motore torna dentro Arhena o resta pacchetto standalone Composer?** Se standalone, la Fase 1 va estesa con versionamento semantico e pubblicazione.
