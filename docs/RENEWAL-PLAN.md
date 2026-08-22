# 🔄 Piano di Rinnovamento — AI-SEO Engine

**Versione documento**: 3.0
**Data**: 22 agosto 2026
**Stato codice analizzato**: `a428ba1` — v1.0.0 (gennaio 2026, 983 righe, 5 file)
**Target**: v2.0.0 — componente SEO multi-sito su API Claude, con approvazione da UI

---

## 1. Sintesi esecutiva

Il motore attuale fa una cosa sola: chiede a Gemini un JSON con `title`,
`description`, `keywords` per **l'intero sito**, leggendolo da un `content.json`
specifico di un cliente, e lo riscrive una volta al mese senza chiedere il
permesso a nessuno. Funziona come proof-of-concept, ma ha tre problemi
strutturali:

1. **Combatte un problema che oggi non esiste più.** Tutto il `README` è dedicato
   al parsing fragile del JSON restituito dal modello. Gli structured output lo
   rendono interamente cancellabile.
2. **Ottimizza per una SERP che non è più quella dominante.** Genera `meta
   keywords` (ignorate da Google dal 2009) e non produce nulla di quello che
   conta per AI Overviews e assistenti: JSON-LD, blocchi FAQ, gerarchia di
   heading, copertura entità.
3. **È un generatore cieco, non un ottimizzatore.** Scrive i meta tag in
   produzione senza sapere se il CTR è salito o sceso, e senza che nessuno abbia
   approvato la modifica. Il campo `confidence: 95` è autodichiarato dal
   modello: non è una misura.

### Scope definitivo (agosto 2026)

Quattro requisiti di prodotto, tutti decisi:

| # | Requisito | Conseguenza tecnica |
|---|---|---|
| R1 | **Componente integrabile in più siti e landing page** | Il multi-tenant non è una fase finale, è un requisito d'impianto: cambia storage, configurazione e UI |
| R2 | **Legge la pagina da URL** | L'input non è più una struttura dati interna. Tutti i metodi `extract*()` cablati vanno eliminati |
| R3 | **API Claude, non Gemini** | Structured output tipizzati in PHP, prompt caching per tenant, un solo provider da mantenere |
| R4 | **Le modifiche vengono approvate da una UI** | Nessun canale di scrittura viene costruito prima della UI che lo autorizza. `auto_apply` sparisce |

R4 è il requisito che riordina il piano: la UI non è la rifinitura finale, è il
**cancello** che sta fra l'analisi e la pubblicazione.

### Il quadro in una figura

```
OGGI · CICLO APERTO

  content.json ──legge──▶  estrazione  ──prompt──▶  Gemini  ──testo──▶  parseResponse  ──tronca──▶  scrive
                           hardcoded                                    pulizia stringhe            3 meta tag
        ▲                                                                                                │
        └───────────────────────  ✗  anello assente: nessuna misura di ritorno  ──────────────────────────┘


v2.0 · CICLO CHIUSO, CON CANCELLO

                                          ┌────────  rigenera · max 2 tentativi  ────────┐
                                          ▼                                              │
   URL pagina  ──▶  contesto  ──prompt──▶ Claude ──bundle──▶  validazione  ──conforme──▶  UI approva
  qualsiasi sito   + dati reali          Opus 5             deterministica                 poi pubblica
                        ▲                                                                      │
                        │                                                                      │ URL
                        │                                                                      │ modificato
                        │                                                                      ▼
                        └──  confronto 14–28 gg  ──  Search Console (CTR · posizione)  ◀────────┘
                            rollback se peggiora
```

La differenza non sta nei singoli riquadri, sta nei **due anelli che oggi
mancano più il cancello che oggi non c'è**. La validazione rimanda indietro
l'output non conforme invece di troncarlo; la UI impedisce che qualcosa
raggiunga un sito senza che una persona l'abbia visto; Search Console rimanda i
dati reali dentro il contesto della generazione successiva.

### Il tuo flusso di lavoro

Il motore **non si installa nel sito del cliente**: sta da te, e guarda il sito
da fuori come farebbe Google.

```
      LA TUA INSTALLAZIONE · una sola, N clienti          FUORI · non lo ospiti tu
    ┌───────────────────────────────────────────┐
    │                                           │      ┌──────────────────────┐
 ┌──┴───┐   approvi ──▶  ┌───────────────────┐  │  ┌──▶│   Search Console     │
 │  TU  │◀── ti propone  │    Dashboard      │  │  │   │  del cliente         │
 └──┬───┘                │ coda · diff · ok  │  │  │   └──────────────────────┘
    │                    └─────────┬─────────┘  │  │    il cliente ti autorizza
    │                              │            │  │       una volta sola
    │                    ┌─────────▼─────────┐  │  │
    │                    │      Motore       │──┼──┘  legge CTR e posizione
    │                    │ crawler · audit   │  │
    │                    │   generazione     │──┼─────▶┌──────────────────────┐
    │                    └─────────┬─────────┘  │      │  Sito o landing      │
    │                              │            │─ ─ ─▶│  del cliente         │
    │                    ┌─────────▼─────────┐  │       scrive solo se        │
    │                    │     Database      │  │       hai approvato         │
    │                    │ tenant · storico  │  │      └──────────────────────┘
    │                    └───────────────────┘  │
    └───────────────────────────────────────────┘─────▶┌──────────────────────┐
                                                       │  API Claude          │
                                                       └──────────────────────┘
```

Le due frecce verso il sito sono il punto: **lettura sempre, scrittura solo dopo
la tua approvazione** — e solo se per quel cliente hai scelto un canale che
scrive.

| La domanda | La risposta |
|---|---|
| Si integra nel sito o nella landing? | **Né l'uno né l'altra, per analizzare.** Il motore scarica la pagina dall'esterno come fa Google. Un adapter sul sito serve *solo* se scegli un canale che pubblica in automatico — e il canale "report" non ne richiede nessuno |
| Sito o landing: cambia qualcosa? | **No.** Una landing è 1 URL, un sito è N URL presi dalla sitemap. Cambia il budget di crawl, non il codice |
| Parla direttamente con Search Console? | **Sì, ma dalla tua installazione**, via API e in *sola lettura*. Il cliente ti autorizza una volta sulla sua property. È opzionale: senza, il motore funziona lo stesso ma perde i dati reali e la misura d'impatto |
| Una installazione per cliente? | **No, una sola per te.** I clienti sono righe nel database, isolate fra loro |

**La giornata tipo**

1. **Aggiungi un cliente** in dashboard: nome, URL di partenza, e il profilo che dalla pagina non è deducibile (brand voice, servizi prioritari, query target).
2. **Il cliente ti autorizza su Search Console** — una volta sola, ed è facoltativo.
3. **Scegli il canale di pubblicazione** per quel cliente. Puoi restare su "report" e non toccare mai il suo sito.
4. **Di notte il motore lavora**: scarica le pagine, calcola l'audit deterministico, chiama Claude solo dove serve giudizio.
5. **La mattina trovi una coda di proposte**, ognuna con diff, punteggio prima/dopo, motivo e dati Search Console.
6. **Approvi, modifichi o rifiuti.** Fino a qui nessun sito è stato toccato.
7. **Dopo 14–28 giorni** la dashboard dice se posizione e CTR si sono mossi, e propone il rollback se sono peggiorati.

---

## 2. Diagnosi dello stato attuale

### 2.1 Cosa funziona e va conservato

| Elemento | Perché tenerlo |
|---|---|
| Separazione `GeminiSEO` / `integration.php` / `cron.php` | Il confine engine-vs-sito è corretto: è la base del multi-tenant |
| Callback di salvataggio (`$saveCallback`) | Disaccoppia il motore dallo storage del sito. **Diventa l'interfaccia dei publisher, a valle della UI** |
| Storico con backup del valore precedente (`saveToHistory`) | È già metà di un sistema di rollback |
| Contesto stagionale (`getCurrentSeason`, `detectSpecialPeriod`) | La stagionalità conta davvero nel local SEO |
| `declare(strict_types=1)` ovunque | Base sana: il PHP tipizzato è ciò che rende utili gli structured output di Claude |

### 2.2 Bug confermati (non teorici)

| # | File:riga | Problema | Impatto |
|---|---|---|---|
| B1 | `GeminiSEO.php:641-646`, `662-669` | `in_array('BowlingAlley', $types)` con `$types` stringa → **TypeError fatale**. `extractBusinessType` (riga 573) gestisce sia stringa che array, questi due metodi no | Crash del cron |
| B2 | `integration.php:29`, `91` | Il parametro `$force` è dichiarato e **mai usato** | "Esegui ora" non bypassa il blocco dei 30 giorni |
| B3 | `GeminiSEO.php:406-407` | `mb_substr($title, 0, 60)` tronca a metà parola invece di rigenerare | Meta tag pubblicati mutilati |
| B4 | `GeminiSEO.php:763` | `testConnection()` riporta `gemini-1.5-flash`, l'endpoint (riga 21) usa `gemini-2.5-flash` | Diagnostica che mente |
| B5 | `GeminiSEO.php:118` | `date('F')` produce `August` dentro un prompt italiano | Rumore nel contesto |
| B6 | `GeminiSEO.php:485`, `499`, `521` | Scritture **senza `LOCK_EX`** con read-modify-write | Stato corrotto in concorrenza — **risolto per costruzione dal passaggio a database (§4.7)** |
| B7 | `GeminiSEO.php:511` | `activity.log` cresce senza rotazione | Disco pieno |
| B8 | `GeminiSEO.php:401-404` | Nessuna validazione di tipo sull'output del modello | Meta tag rotto in produzione |
| B9 | `GeminiSEO.php:170-179` | Se `applySEO` fallisce non si logga e non si aggiorna `last_run` | Fallimenti silenziosi |
| B10 | `GeminiSEO.php:648` | `preg_match` senza `/u` su testo UTF-8 | Spezza caratteri accentati |

### 2.3 Rischi di sicurezza

| # | Dove | Problema |
|---|---|---|
| S1 | `config/seo-config.json` (**tracciato in git**) | `saveConfig()` scrive la chiave API in chiaro in un file versionato |
| S2 | `cron.php:24` | `hash('sha256', 'arhena_seo_cron_2025')` — **calcolabile da chiunque legga il sorgente** |
| S3 | `cron.php:26` | Confronto con `!==` invece di `hash_equals()` |
| S4 | `config/.htaccess` | Mescola sintassi Apache 2.2 e 2.4: su 2.4 senza `mod_access_compat` è **errore 500**, non protezione |
| S5 | `GeminiSEO.php:559` | `@mail()` con errore soppresso |

Con il multi-tenant S1 cambia di gravità: non è più una chiave, sono le chiavi e
le credenziali di **N clienti**. Va risolto in Fase 0 e riprogettato in Fase 3.

### 2.4 Debito di riusabilità

`extractLocation` (`:591`) ha una regex hardcoded con Portogruaro, Venezia,
Treviso e Udine e restituisce `'Portogruaro'` come default; `extractUSP` e
`inferTargetAudience` conoscono solo bowling, ristorante e locale notturno.

Su un cliente diverso il motore produce output sbagliato **in silenzio**. È
esattamente il debito che R1 e R2 cancellano per intero.

### 2.5 Debito infrastrutturale

Nessun `composer.json`, nessun autoload PSR-4, **zero test**, nessuna CI, nessun
analizzatore statico. `curl` grezzo senza retry né backoff. Nessun conteggio
token né tracciamento costi — insostenibile quando i siti sono N invece di 1.

---

## 3. Cosa è cambiato nel frattempo

### 3.1 Capacità oggi sfruttabili con l'API Claude

| Capacità | Cosa risolve qui |
|---|---|
| **Structured output tipizzati** | Una classe PHP che implementa `StructuredOutputModel` è *insieme* lo schema del prompt e il tipo di ritorno. Elimina **tutto** `parseResponse` (`:369-413`) |
| **Tool use** | Il motore può *chiedere* dati mentre genera: leggere Search Console, scaricare una pagina concorrente, misurare i pixel con un tool deterministico |
| **Adaptive thinking** | Ragionamento profondo solo dove serve — la strategia SEO di una pagina — senza configurare budget di token |
| **Prompt caching** | Le istruzioni per tenant sono identiche a ogni run: con TTL a 1 ora una sessione di ottimizzazione su decine di pagine paga il prefisso una volta sola |
| **Batch API** | 200 pagine non sono 200 chiamate sincrone: è un batch notturno a costo ridotto |
| **Embeddings** | Clustering keyword, cannibalizzazione tra pagine, gap di copertura entità |

### 3.2 Cosa è cambiato nella SEO stessa

- **Le `meta keywords` non servono.** Ignorate da Google dal 2009: si tengono
  come *segnale interno* per il clustering, non come output pubblicato.
- **AEO/GEO.** Una quota crescente di query si esaurisce dentro AI Overviews e
  assistenti senza click: ciò che rende un contenuto *citabile* è diverso da ciò
  che lo rendeva cliccabile.
- **Dati strutturati come leva primaria.** JSON-LD `LocalBusiness`, `FAQPage`,
  `Product`, `Event` è il canale più diretto verso i rich result, e il motore
  oggi non ne genera nemmeno uno.
- **`llms.txt`** è una proposta, non uno standard adottato: esperimento a basso
  costo, non un pilastro.
- **Larghezza in pixel > numero di caratteri.** Google tronca a ~580px: il
  vincolo "50-60 caratteri" ripetuto 4 volte nel prompt è un'approssimazione.

---

## 4. Il motore v2.0

### 4.1 Le due metà

R2 sposta l'input da struttura dati interna a **URL pubblico**, e questo spacca
il motore in due metà con vincoli opposti:

**Metà A — Analisi: universale davvero.** Serve solo un URL. Funziona su
WordPress, Shopify, PrestaShop, Next.js, un HTML statico o una landing page
fatta a mano, senza accesso al CMS, senza credenziali, senza plugin.

**Metà B — Pubblicazione: qui "qualsiasi sito" si rompe.** Scrivere i tag
richiede un canale verso il sito, diverso per ogni piattaforma. È qui che va
speso il tempo di prodotto.

Fra le due c'è il **cancello**: la UI di approvazione (§4.6). Niente attraversa
da A a B senza passare di lì.

### 4.2 Metà A — dall'URL al modello di pagina

`PageFetcher` → `PageModel`, che raccoglie: HTTP status, catena di redirect,
canonical, `robots`/`noindex`, hreflang · `<title>`, meta description, Open
Graph, Twitter card · albero degli heading H1-H6 e unicità dell'H1 · testo
principale con **rimozione del boilerplate** (menu, footer, cookie banner) ·
immagini con e senza `alt` · JSON-LD già presente e sua validità · link interni
ed esterni con anchor text · Core Web Vitals reali via API CrUX/PageSpeed.

**Decisione tecnica obbligata**: le landing page moderne sono spesso renderizzate
in JavaScript, e un fetch HTTP semplice vede una pagina vuota. Serve **rendering
headless (Chromium)** almeno come fallback — costa 1-3 s e memoria per pagina, ma
senza si è ciechi su una fetta crescente di siti. Rilevamento automatico: se il
testo estratto è sotto una soglia rispetto al peso della pagina, si ri-scarica
con rendering.

Questo modello **sostituisce** `analyzeSiteContent()` e i sei `extract*()`
cablati su Arhena. Resta un profilo per tenant, ridotto a ciò che dalla pagina
non è deducibile: brand voice, USP reali, servizi prioritari, query target.

### 4.3 L'audit non deve costare token

Perché "portare in alto" abbia un significato operativo serve un punteggio su ciò
che si controlla. La maggior parte dei controlli è **deterministica e va fatta in
PHP, non dal modello**: title assente, larghezza in pixel, duplicato tra pagine ·
description assente, troncata, duplicata · H1 mancante o multiplo · gerarchia
heading rotta · immagini senza `alt` · canonical assente o errato · JSON-LD
assente o non valido per il tipo di pagina · pagina orfana · thin content ·
redirect chain · mixed content · `noindex` accidentale.

Claude interviene **solo dove serve giudizio**: corrispondenza con l'intento di
ricerca, qualità e unicità del copy, copertura delle entità, scrittura dei testi.

> **Regola**: se un controllo si può esprimere in codice, non si chiede al
> modello. È più veloce, deterministico, gratuito e testabile.

### 4.4 Perché l'API Claude cambia la forma del codice (R3)

Non è una sostituzione di endpoint: cambia dove vive lo schema.

```php
// composer require "anthropic-ai/sdk"
use Anthropic\Client;
use Anthropic\Lib\Contracts\StructuredOutputModel;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Attributes\Constrained;

final class SeoBundle implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'Title SEO, massimo 580px di larghezza resa')]
    public string $title;

    #[Constrained(description: 'Meta description con CTA')]
    public string $description;

    /** @var Faq[] */
    public array $faq;

    public ?string $jsonLd = null;   // nullable = campo opzionale
}

$message = $client->messages->create(
    model: 'claude-opus-5',
    maxTokens: 16000,
    system: [[
        'type' => 'text',
        'text' => $istruzioniTenant,
        'cacheControl' => ['type' => 'ephemeral', 'ttl' => '1h'],
    ]],
    messages: [['role' => 'user', 'content' => $contestoPagina]],
    outputConfig: ['format' => SeoBundle::class],
);

$bundle = $message->parsedOutput();   // istanza tipizzata di SeoBundle
```

Tre conseguenze dirette:

1. **`parseResponse` non viene corretta, viene cancellata.** Non c'è più testo da
   ripulire: c'è un oggetto PHP tipizzato. Il problema che ha bloccato il
   progetto a gennaio smette di esistere per costruzione.
2. **Lo schema non è duplicato.** Nella v1 il formato atteso era descritto a
   parole nel prompt e ri-verificato a mano dopo. Qui la classe è insieme il
   contratto verso il modello e il tipo di ritorno: PHPStan la vede, i test la
   usano, il prompt la deriva.
3. **Il prompt caching è per tenant.** Le istruzioni di un cliente sono identiche
   su tutte le sue pagine: con TTL a 1 ora una sessione su decine di URL paga il
   prefisso una volta sola. Si verifica con
   `$message->usage->cacheReadInputTokens` — se resta a zero, qualcosa nel
   prefisso sta cambiando a ogni richiesta.

Modello di riferimento: **`claude-opus-5`**, con adaptive thinking dove serve
ragionamento (la strategia della pagina) e senza dove non serve (le riscritture
meccaniche). Il `LlmProvider` come interfaccia resta — non per poter tornare a
Gemini, ma perché **senza interfaccia non esiste `FakeProvider`, e senza
`FakeProvider` i test toccano la rete**.

### 4.5 Il gap con chi sta davanti

Confrontarsi con chi è in alto ora è l'unico modo di rendere misurabile
l'obiettivo: si prendono le pagine in posizione 1-3 per la query target, si
passano **allo stesso crawler**, e si calcola cosa hanno che la pagina in esame
non ha — entità coperte, profondità, schema, struttura, freschezza. L'output è un
**gap elencabile**, non un'opinione.

Serve una fonte SERP: API a pagamento (DataForSEO, SerpAPI) oppure — gratis ma
limitata alle query dove già si compare — Search Console. **Decisione di costo
aperta** (§9).

### 4.6 Il cancello: la UI di approvazione (R4)

Nella v1 `auto_apply` scriveva in produzione senza che nessuno vedesse nulla. R4
inverte il default: **niente raggiunge un sito senza approvazione esplicita**, e
`auto_apply` viene rimosso invece che messo a `false`.

La UI deve mostrare, per ogni proposta:

- **diff affiancato** — valore attuale contro valore proposto, campo per campo
- **anteprima SERP** con la larghezza reale in pixel, non il conteggio caratteri
- **il punteggio audit** prima e dopo, per capire cosa la modifica chiude
- **il motivo** — quale regola dell'audit o quale gap SERP ha innescato la proposta
- **i dati di partenza** — impression, CTR e posizione attuali da Search Console
- azioni: **approva · modifica a mano · rifiuta**, e una modifica a mano è essa
  stessa un segnale da conservare
- lo **storico per URL** con rollback a un click

Conseguenze d'impianto, tutte non negoziabili:

- La coda di proposte è uno **stato condiviso fra cron e UI**: due processi che
  scrivono. Con file JSON e `file_put_contents` questo è il bug B6 moltiplicato
  per N tenant. Serve un database.
- Serve autenticazione, e con più clienti serve **chi vede cosa**.
- Ogni approvazione va tracciata: chi, quando, cosa. È anche ciò che rende
  difendibile il lavoro davanti al cliente.

### 4.7 Multi-tenant come impianto, non come fase (R1)

Con N siti, tre cose della v1 non reggono più:

| v1 | v2.0 | Perché |
|---|---|---|
| `config/seo-config.json` su disco | Tabella `tenants` su database | Config, coda, storico e audit sono scritti da cron e UI insieme. **Il passaggio a database elimina l'intera classe di bug B6**, non la mitiga |
| Chiave API nel file di config | Secret per tenant fuori dal repo, risolti a runtime | S1 con N clienti non è un rischio, è una violazione |
| `history.json` con ultimi 100 record | Storico per URL e per tenant | Il rollback deve essere per pagina, non globale |

SQLite basta per iniziare ed è sufficiente fino a numeri seri; Postgres o MySQL
quando i tenant crescono. La scelta non cambia il codice se lo storage è dietro
un'interfaccia.

Il crawl va inoltre **isolato per tenant**: budget di pagine, rate limit per
host, e nessuna possibilità che il lavoro di un cliente consumi la quota di un
altro.

### 4.8 Metà B — i canali di pubblicazione

| Modalità | Copertura | Verdetto |
|---|---|---|
| **Report + patch** | 100% dei siti, zero integrazione | Da avere sempre: il fallback che non fallisce mai, ed è già un prodotto vendibile da solo |
| **API pull** — il sito chiede il bundle approvato | Siti con uno sviluppatore | È ciò che `$saveCallback` fa già oggi. Server-side, quindi corretto |
| **Adapter CMS** — plugin WordPress in primis | WordPress è la fetta più grande del web | Il maggior ritorno per singolo adapter. Server-side |
| **Edge injection** — Cloudflare Worker che riscrive l'HTML in transito | Qualsiasi sito dietro un CDN configurabile | La cosa più vicina a "qualsiasi sito" che funzioni davvero: i crawler ricevono HTML già corretto |
| **Snippet JS client-side** | Apparentemente tutti | ⚠️ **Da non usare come modalità primaria.** I crawler social non eseguono JS e i meta iniettati lato client non sono affidabili per l'indicizzazione |

L'ultima riga è la trappola del progetto: la modalità che *sembra* la più
universale è quella che funziona peggio proprio sulla cosa che deve fare.

### 4.9 Sul "portarlo al numero 1"

Il motore controlla l'on-page: struttura, contenuto, dati strutturati,
corrispondenza con l'intento. **Non controlla** autorità di dominio, backlink,
età del sito né chi ha davanti. Su query long-tail locali a bassa competizione la
prima posizione è realistica; su query di testa competitive dipende da fattori
fuori dal motore.

Tradotto in prodotto: si promette **il massimo del punteggio on-page e il
movimento misurato** di posizione e CTR, non la posizione 1. È l'unica versione
difendibile davanti a un cliente — e la Fase 5 esiste per dimostrarla con i
numeri invece che raccontarla.

---

## 5. Architettura target

```
ai-seo-engine/
├── composer.json                 # PSR-4, PHP >= 8.3, anthropic-ai/sdk
├── src/
│   ├── Engine/
│   │   ├── SeoEngine.php         # orchestratore (ex GeminiSEO, dimagrito)
│   │   ├── OptimizationCycle.php # fetch → audit → genera → valida → proponi
│   │   └── Scheduler.php         # per tenant, non globale
│   ├── Crawl/                    # ── METÀ A ──
│   │   ├── PageFetcher.php       # HTTP + fallback rendering headless
│   │   ├── PageModel.php         # rappresentazione tipizzata della pagina
│   │   ├── BoilerplateStripper.php
│   │   └── SitemapWalker.php     # da un URL a tutto il sito, con budget
│   ├── Audit/
│   │   ├── Rule.php              # interfaccia di un check
│   │   ├── Rules/                # ~40-60 controlli deterministici
│   │   ├── Scorecard.php         # punteggio per categoria e gravità
│   │   └── SerpGap.php           # confronto con chi è in posizione 1-3
│   ├── Llm/
│   │   ├── LlmProvider.php       # interfaccia — esiste per FakeProvider
│   │   ├── ClaudeProvider.php    # anthropic-ai/sdk, claude-opus-5
│   │   └── FakeProvider.php      # test senza rete
│   ├── Schema/
│   │   ├── SeoBundle.php         # StructuredOutputModel: schema E tipo
│   │   └── JsonLd/               # LocalBusiness, FAQPage, Product, Event
│   ├── Validation/
│   │   ├── PixelWidth.php · BundleValidator.php · RepairLoop.php
│   ├── Review/                   # ── IL CANCELLO ──
│   │   ├── Proposal.php          # una proposta in attesa
│   │   ├── ProposalQueue.php     # coda condivisa cron ↔ UI
│   │   └── Decision.php          # approva · modifica · rifiuta, con autore
│   ├── Publish/                  # ── METÀ B ──
│   │   ├── Publisher.php         # interfaccia (ex $saveCallback)
│   │   ├── ReportPublisher.php · ApiPublisher.php
│   │   ├── WordPressPublisher.php · EdgePublisher.php
│   ├── Metrics/
│   │   ├── SearchConsoleClient.php · ImpactReport.php
│   └── Tenancy/
│       ├── Tenant.php · TenantRepository.php
│       ├── SecretResolver.php    # segreti per tenant, fuori dal repo
│       └── CrawlBudget.php
├── ui/                           # console di approvazione
├── bin/  seo-audit · seo-run · seo-rollback
└── tests/
```

**Principio guida**: il modello genera, **il codice valida e misura, la persona
approva**. Ogni vincolo esprimibile in modo deterministico esce dal prompt ed
entra in `Audit/` o `Validation/`. Il prompt resta per ciò che solo Claude sa
fare: capire l'intento e scrivere.

---

## 6. Piano per fasi

| Fase | Titolo | Effort |
|---|---|---|
| 0 | Messa in sicurezza | 1–2 gg |
| 1 | Fondamenta di progetto | 3–5 gg |
| 2 | Migrazione all'API Claude | 3–4 gg |
| 3 | Multi-tenant e storage | 4–6 gg |
| 4 | Input universale: dall'URL al modello di pagina | 6–9 gg |
| 5 | Output AEO: dal meta tag al bundle | 5–8 gg |
| 6 | **UI di approvazione — il cancello** | 6–9 gg |
| 7 | Canali di pubblicazione | 6–10 gg |
| 8 | Closed loop: Search Console e SERP gap | 6–9 gg |
| 9 | Sperimentale | opzionale |
| | **Totale** | **≈ 40–62 gg** |

**La regola d'ordine imposta da R4**: la Fase 7 non comincia prima che la Fase 6
sia finita. Costruire un canale di scrittura prima del cancello che lo autorizza
significa ricreare esattamente l'`auto_apply` che stiamo togliendo.

### Fase 0 — Messa in sicurezza *(1-2 gg · da fare comunque)*

- [ ] Fix **B1** — normalizzare `business_type` ad array in un solo punto
- [ ] Fix **S1** — chiave API da `getenv()`, rimossa dal JSON, `config/*.json` in `.gitignore`
- [ ] Fix **S2/S3** — segreto cron da variabile d'ambiente + `hash_equals()`
- [ ] Fix **S4** — `.htaccess` con sola sintassi Apache 2.4
- [ ] Fix **B6** — `LOCK_EX` come tampone finché non arriva il database (Fase 3)
- [ ] Fix **B2** e **B4**; ruotare la chiave se è mai stata committata

### Fase 1 — Fondamenta di progetto *(3-5 gg)*

- [ ] `composer.json`, autoload PSR-4, PHP ≥ 8.3
- [ ] PHPStan livello 8, PHP-CS-Fixer
- [ ] PHPUnit con `FakeProvider`: **nessun test tocca la rete**
- [ ] Fixture: risposte modello registrate + **pagine HTML reali salvate** (servono in Fase 4)
- [ ] GitHub Actions: lint + analisi statica + test
- [ ] Astrazione HTTP con retry e backoff su 429/5xx

### Fase 2 — Migrazione all'API Claude *(3-4 gg)*

- [ ] `composer require "anthropic-ai/sdk"`; `ClaudeProvider` su `claude-opus-5`
- [ ] `SeoBundle` come `StructuredOutputModel`: **lo schema diventa una classe PHP**
- [ ] **Cancellare `parseResponse`** (`:369-413`) e il file `GeminiSEO.php` nella sua forma attuale
- [ ] `RepairLoop`: rigenerare con l'errore in contesto (max 2 tentativi), mai troncare
- [ ] `PixelWidth` sostituisce i vincoli in caratteri
- [ ] Prompt caching sul blocco istruzioni, con verifica di `cacheReadInputTokens`
- [ ] Conteggio token e costo per run — prerequisito del multi-tenant

### Fase 3 — Multi-tenant e storage *(4-6 gg)*

- [ ] Schema database (SQLite per iniziare): `tenants`, `pages`, `proposals`, `audits`, `history`
- [ ] `TenantRepository`, `SecretResolver` per credenziali per cliente
- [ ] Migrazione da `seo-config.json` a database — **B6 sparisce come classe di bug**
- [ ] `CrawlBudget` e rate limit per host, isolati per tenant
- [ ] `Scheduler` per tenant al posto del `frequency_days` globale

### Fase 4 — Input universale *(6-9 gg)*

- [ ] `PageFetcher`: HTTP + **fallback rendering headless** con rilevamento automatico
- [ ] `PageModel` tipizzato (§4.2) e `BoilerplateStripper`
- [ ] `SitemapWalker`: da un URL all'intero sito
- [ ] `Audit/Rules/`: 40-60 controlli **deterministici**, uno per classe, uno per test
- [ ] `Scorecard`: punteggio per categoria e gravità
- [ ] **Eliminare** `analyzeSiteContent()` e i sei `extract*()` hardcoded
- [ ] `bin/seo-audit <url>` — primo comando che dà valore da solo

### Fase 5 — Output AEO *(5-8 gg)*

- [ ] Bundle esteso: `json_ld` (LocalBusiness + orari + FAQPage), Open Graph, `heading_outline`, 4-6 coppie FAQ, alt text, link interni suggeriti
- [ ] Multi-pagina: un bundle per URL, cannibalizzazione rilevata via embeddings
- [ ] `keywords` fuori dall'output pubblicato, mantenute come segnale interno
- [ ] Validazione JSON-LD contro Schema.org **prima** di proporre

### Fase 6 — UI di approvazione *(6-9 gg)* ⟵ **il cancello**

- [ ] `Review/`: `Proposal`, `ProposalQueue`, `Decision` con autore e timestamp
- [ ] Diff affiancato per campo, anteprima SERP in pixel, punteggio prima/dopo
- [ ] Motivazione della proposta: quale regola o quale gap l'ha innescata
- [ ] Azioni approva · modifica · rifiuta; la modifica a mano è un segnale da conservare
- [ ] Autenticazione e visibilità per tenant
- [ ] Storico per URL con rollback a un click
- [ ] **Rimuovere `auto_apply`** dal codice e dalla configurazione

### Fase 7 — Canali di pubblicazione *(6-10 gg)* — **solo dopo la Fase 6**

- [ ] `Publisher` come interfaccia (evoluzione di `$saveCallback`), a valle della coda
- [ ] `ReportPublisher` — patch + report, zero integrazione. **Da fare per primo**
- [ ] `ApiPublisher` — endpoint da cui il sito fa pull del bundle **approvato**
- [ ] `WordPressPublisher` — plugin, il singolo adapter col ritorno più alto
- [ ] `EdgePublisher` — Cloudflare Worker
- [ ] Nessuno snippet JS client-side come modalità primaria (§4.8)

### Fase 8 — Closed loop *(6-9 gg)*

- [ ] `SearchConsoleClient`: impression, click, CTR, posizione per query e URL
- [ ] Dati reali dentro il prompt e dentro la UI, accanto a ogni proposta
- [ ] `SerpGap`: pagine in posizione 1-3 passate allo stesso crawler
- [ ] `ImpactReport`: confronto a 14/28 giorni pre/post
- [ ] Rollback suggerito in UI se il CTR peggiora oltre il rumore statistico
- [ ] Il `confidence` autodichiarato sostituito dall'impatto misurato

### Fase 9 — Sperimentale *(opzionale)*

- [ ] `llms.txt` — costo bassissimo, adozione incerta
- [ ] Batch API per ottimizzazioni massive notturne
- [ ] Model routing: modello più economico per l'estrazione, `claude-opus-5` per strategia e copy — **da valutare a costi misurati, non a priori**
- [ ] Server MCP che espone il motore come tool

---

## 7. Metriche di successo

| Metrica | Oggi | Target v2.0 |
|---|---|---|
| Errori di parsing JSON | ricorrenti | **0** — impossibili per costruzione |
| Copertura test | 0% | ≥ 70% sulla logica core |
| Siti serviti da una installazione | 1 | **N, isolati** |
| Siti su cui gira senza modifiche al codice | 1 | **qualsiasi, da URL** |
| Modifiche pubblicate senza revisione umana | tutte | **0** |
| Campi SEO prodotti | 3 | 8+ incluso JSON-LD |
| Granularità | 1 sito | 1 bundle per URL |
| Controlli deterministici | 0 | 40-60, senza costo token |
| Segreti in repo | rischio attivo | 0 |
| Misura d'impatto | nessuna | CTR e posizione before/after |
| Costo | non tracciato | per tenant, con caching verificato |

---

## 8. Sequenza consigliata e rischi

**Ordine**: 0 → 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8, con il vincolo che la 7 non
precede la 6.

Se il tempo è poco, **il minimo vendibile è 0 + 1 + 2 + 4 + `ReportPublisher`**:
un `seo-audit <url>` che produce un report con punteggio su qualsiasi sito è già
un prodotto, non richiede la UI perché non scrive niente da nessuna parte, e non
tocca la produzione di nessuno.

| Rischio | Mitigazione |
|---|---|
| Rendering headless costoso e fragile | Fallback, non default; rilevamento automatico; timeout aggressivo |
| Crawl percepito come abusivo | Rispettare `robots.txt`, User-Agent identificabile, rate limit per host |
| API SERP a consumo: costo fuori controllo | Budget per tenant; Search Console dove basta; cache dei risultati |
| Costo LLM che cresce con i tenant | Prompt caching per tenant verificato via `cacheReadInputTokens`; batch notturno |
| La UI diventa un progetto a sé | Prima versione volutamente minimale: coda, diff, tre bottoni. Niente altro |
| Search Console ha 2-3 gg di latenza | Finestre di valutazione a 14/28 giorni, mai giornaliere |
| Traffico local troppo basso per la significatività | Soglie minime di impression; sotto soglia nessun rollback suggerito |
| JSON-LD errato può penalizzare | Validazione Schema.org obbligatoria prima di proporre |
| Riscrittura totale = mesi persi | Piano incrementale: `GeminiSEO` resta funzionante fino alla Fase 2 |

---

## 9. Decisioni

### Chiuse

| # | Decisione | Esito |
|---|---|---|
| D1 | Provider LLM | **API Claude** (`claude-opus-5`). Interfaccia mantenuta solo per `FakeProvider` |
| D2 | Multi-tenant | **Requisito d'impianto** (Fase 3), non fase finale |
| D3 | Approvazione delle modifiche | **UI**, e nessun canale di scrittura prima che esista |
| D4 | `auto_apply` | **Rimosso**, non messo a `false` |

### Aperte

1. **Fonte SERP**: API a pagamento (DataForSEO/SerpAPI) o solo Search Console? Determina se la Fase 8 include il gap competitivo o si limita alle query già presidiate. È la principale voce di costo ricorrente.
2. **Rendering headless**: sempre, mai, o fallback automatico? Cambia i requisiti di hosting — con Chromium serve una macchina, non un hosting condiviso.
3. **Quale canale di pubblicazione per primo?** Il report a zero integrazione dà valore subito; il plugin WordPress apre il mercato più grande. Non sono la stessa scommessa.
4. **La UI sta dentro il repo o è un'applicazione separata?** Se il motore resta un componente Composer riusabile, la console di approvazione è probabilmente un'app a parte che lo consuma.
5. **Chi sono gli utenti della UI**: solo l'agenzia, o anche i clienti finali? Cambia autenticazione, permessi e quanto va spiegato a schermo.
