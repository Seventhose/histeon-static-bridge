# Form e contenuti dinamici

## Regola generale

La presenza di un form non rende automaticamente dinamica tutta la pagina. Una
pagina può essere HTML statico mentre il browser invia il form a un endpoint
WordPress dinamico, per esempio AJAX o REST. Occorre mantenere dinamico l'intero
percorso quando è la risposta HTML stessa a dipendere da utente, sessione,
nonce aggiornati, personalizzazione o rendering server-side.

Un'esclusione come /iscriviti/ vale per quel percorso e tutte le sue sottopagine
(ad esempio /iscriviti/conferma/). È una scelta esplicita di routing, non un
requisito universale per i form.

## Quali richieste diventano statiche

Una richiesta è candidata al file statico quando è una GET o HEAD pubblica,
senza query string, non autenticata secondo le regole del server, non esclusa e
il file HTML corrispondente esiste nella release corrente. Il server serve quel
file prima di avviare PHP/WordPress.

Continuano normalmente a WordPress le richieste a wp-admin, login, REST,
endpoint WordPress, feed, sitemap, query string, metodi diversi da GET/HEAD e
percorsi dichiarati dinamici. Il dettaglio dei cookie controllati varia con il
server: il blocco Nginx include cookie WordPress, commentatore e WooCommerce;
le condizioni Apache generate non sono identiche in ogni dettaglio. Verificare
sempre il comportamento effettivo sul server installato.

| Esempio | Comportamento atteso |
| --- | --- |
| GET /articolo/ senza query e senza sessione | HTML dalla release, se presente |
| GET /articolo/?preview=1 | WordPress |
| GET /wp-admin/ o /wp-json/ | WordPress |
| POST a endpoint WordPress | WordPress |
| GET /contatti/ se escluso | WordPress |
| Pagina Fluent Forms senza token a scadenza, modalità statica | HTML dalla release; submit gestito dall'endpoint WordPress, da collaudare |
| Fluent Forms con Token Based Spam Protection o verifica del nonce di invio (dalla 2.0.16, rinnovo attivo) | HTML dalla release con lo script di rinnovo; nonce aggiornati al primo uso del modulo |
| Stessi moduli con markup non riconosciuto, o rinnovo disattivato | La route resta dinamica (WordPress) |
| Pagina in modalità dinamica | WordPress per la pagina e le sue sottopagine |

## Fluent Forms

La scheda Moduli cerca gli shortcode nei contenuti pubblici, il blocco Gutenberg
registrato da Fluent Forms (`fluentfom/guten-block`) e i dati Elementor/Bricks
che contengono lo shortcode. La scansione è paginata e limitata a 5.000
candidati per non caricare tutto il sito in memoria. Per ogni pagina rilevata
si può scegliere:

- **Statica:** l'HTML del form viene acquisito nella release. La pagina non viene
  esclusa se il file statico esiste; gli endpoint di invio rimangono richieste
  separate a WordPress.
- **Dinamica:** la route della pagina è esclusa e WordPress genera ogni risposta.

### Nonce che scadono: rinnovo nelle pagine statiche (dalla 2.0.16)

Le pagine con un modulo Fluent Forms contengono valori che scadono con la vita
del nonce WordPress (12–24 ore): `token_nonce` in `fluentFormVars`, usato dalla
**Token Based Spam Protection** (il token anti-spam vero non è nell'HTML: lo
chiede il JavaScript al primo utilizzo del modulo), e il nonce di invio
`_fluentform_<id>_fluentformnonce`. Una copia statica conserva i valori del
momento in cui è stata generata: quando il sito li verifica, dopo la scadenza
l'invio fallisce.

Fino alla 2.0.15 le pagine con protezione token erano forzate su **Dinamica**.
Con un modulo nel footer globale o in un popup presente su tutte le pagine questo
rendeva dinamico l'intero sito. Dalla 2.0.16, con **“Rinnova nonce e token
anti-spam nelle pagine statiche”** attivo (scheda Moduli, attivo di default):

- l'export e gli aggiornamenti incrementali o manuali aggiungono alle pagine
  con moduli Fluent Forms uno script differito
  (`assets/fluent-forms-static.js`); il resto dell'HTML, compresi i campi propri
  della pagina (`__fluent_form_embded_post_id`, `_wp_http_referer`), resta come
  generato da WordPress;
- lo script non fa richieste finché il visitatore non usa un modulo; al primo
  focus/tocco chiede a WordPress, con un POST a `admin-ajax.php`
  (`static_bridge_public_form_nonces`, `Cache-Control: no-store`), i nonce
  correnti delle tre azioni Fluent Forms (token, invio, rimozione file caricati)
  e li applica alle richieste AJAX di Fluent Forms (jQuery). Una richiesta
  partita prima della risposta (Fluent Forms chiede il token sullo stesso focus)
  attende il rinnovo; se il rinnovo fallisce, la richiesta parte invariata come
  senza script;
- prima di scrivere la copia, ogni modulo della pagina viene controllato: devono
  esserci `fluentFormVars` con `ajaxUrl`, il campo nonce di invio del modulo e,
  con la protezione token, `token_nonce` e il campo token vuoto. Se manca
  qualcosa su un modulo che ha bisogno di nonce freschi (per esempio dopo un
  aggiornamento di Fluent Forms che cambia il markup) **quella route non viene
  scritta**, viene registrata con il motivo e instradata a WordPress
  (`.htaccess` riscritto subito; su Nginx va reinstallato il blocco di routing,
  come per ogni nuovo percorso dinamico: finché il blocco non è aggiornato,
  Nginx continua a servire la copia della release attiva, se esiste, e con la
  modalità Strict risponde 404 se non esiste). La generazione
  successiva le ricontrolla e, se il markup torna riconosciuto, le ripubblica
  statiche. Un modulo senza nulla che scada e con markup diverso resta la
  semplice copia statica, come nella 2.0.15;
- la scelta per pagina (statica/dinamica) resta disponibile anche per le pagine
  protette. Le versioni precedenti salvavano “dinamica” per quelle pagine: la
  scheda offre di riportarle a statiche in un passaggio.

Disattivando l'opzione si torna esattamente al comportamento 2.0.15 (pagine con
protezione token sempre dinamiche, nessuno script, endpoint spento). Verificato
con Fluent Forms 6.2.15; il riconoscimento del markup è ciò che protegge da
versioni diverse. Non coperti: pagamenti, salvataggio parziale dei moduli a
passi, moduli conversazionali e altri nonce di add-on; per quei moduli scegliere
la pagina dinamica. La scelta manuale torna disponibile come prima. Altri CAPTCHA, token o personalizzazioni non
riconosciuti non possono essere dedotti in modo universale: verificarli e
mantenere dinamica la route finché il flusso non è stato provato.

Se una pagina scelta come statica non è ancora presente nella release, il
routing la lascia temporaneamente a WordPress; non si crea una pagina vuota.
Le esclusioni manuali in Impostazioni prevalgono: non duplicare lì i percorsi
già gestiti dalla scheda Moduli.

La scansione analizza anche l'HTML della release `current` per rilevare moduli
aggiunti dal tema o da template globali, fino a 2.500 file per caricamento della
scheda. Non analizza la release in costruzione: prima della prima pubblicazione
può quindi mostrare i moduli rilevati nei contenuti, ma non quelli inseriti solo
da header/footer globali. Il rilevamento serve a rendere configurabili le pagine
dopo la pubblicazione e non prova la validità dei nonce o l'esito dell'invio.

La GUI mostra al massimo 50 pagine per volta (circa 100 campi per le righe,
oltre ai campi di servizio), così il salvataggio resta sotto il comune limite
PHP `max_input_vars=1000`. Il salvataggio modifica solo le pagine mostrate e
mantiene le modalità delle altre pagine rilevate. Questa paginazione non amplia
i limiti di scansione: fino a 5.000 candidati nei contenuti e 2.500 file HTML
della release attiva per il rilevamento dei moduli nei template globali.

Un campo nonce nel markup, da solo, non implica che la pagina debba restare
dinamica: dipende da come è configurato il modulo e da quali verifiche sono
attive sul sito. Con la Token Based Spam Protection o la verifica del nonce di
invio attive, e il rinnovo acceso, la pagina resta statica e lo script rinnova
i valori; con markup non riconosciuto la route resta a WordPress. Le
compatibilità valgono per la configurazione provata, non per qualunque add-on o
filtro di terze parti.

La Diagnostica controlla via GET risposta HTML, markup del modulo, hash e
endpoint AJAX. La rilevazione non certifica l'invio end-to-end: le richieste
POST continuano a WordPress, ma l'esito dipende da nonce eventualmente
verificati, CAPTCHA, sessione, upload, webhook e integrazioni server-side. Un
invio di prova può creare un record e attivare notifiche: farlo solo con un
modulo e un destinatario di prova. Se il markup contiene dati per sessione o non
è riutilizzabile dopo un intervallo, scegliere modalità dinamica.

## WooCommerce

L'interruttore **Integrazione WooCommerce** compare solo quando WooCommerce è
attivo. Dalla 2.2.13 è acceso di serie con WooCommerce attivo, finché un
amministratore non lo spegne e salva le Impostazioni. Anche spento, Carrello,
Checkout, Il mio account, `/wc-api/` e `/wc-auth/` (e ogni pagina che WooCommerce
prende per carrello, checkout o account) restano a WordPress: una copia statica
di queste pagine non è mai giusta. Spento, invece, il catalogo non si aggiorna e
i nonce non vengono rinnovati, e la Diagnostica dà un avviso. Con l'interruttore
acceso:

**Che cosa resta a WordPress.**

- Le pagine Carrello, Checkout e Il mio account con tutte le sottorotte: ordine
  ricevuto, pagamento dell'ordine, endpoint dell'account. Sono escluse
  dall'export e dalle regole del server.
- `/wc-api/` (ritorni e notifiche dei gateway di pagamento, anche in GET) e
  `/wc-auth/` (autorizzazione delle chiavi REST): escluse anche con Strict, che
  altrimenti risponderebbe 404.
- Ogni pagina che WooCommerce prende per carrello, checkout o account (anche una
  pagina con lo shortcode o il blocco del carrello) e ogni pagina per cui
  WordPress o un plugin manda `Cache-Control: private` o `no-store`. Static
  Bridge lo vede durante il rendering e tiene quella sola rotta a WordPress.
- Ogni richiesta con query string o POST: ricerca dei prodotti, filtri
  (prezzo, attributi), ordinamento (`?orderby=`), varianti scelte da link
  (`?attribute_…=`), `?add-to-cart=`, `?wc-ajax=` (aggiungi al carrello AJAX,
  frammenti del mini-carrello), il form «Aggiungi al carrello» della pagina
  prodotto.
- La Store API (`/wp-json/wc/store/`) e la REST API: mini-carrello, carrello e
  checkout a blocchi, pulsanti dei blocchi prodotto.
- Ogni visitatore con i cookie del carrello (`woocommerce_items_in_cart`,
  `woocommerce_cart_hash`, `wp_woocommerce_session_`): dal primo prodotto nel
  carrello vede solo pagine di WordPress, con prezzi e quantità veri.

**Che cosa resta statico.** Home, negozio con le sue pagine `/page/N/`,
categorie e tag dei prodotti, pagine dei prodotti semplici, variabili (i dati
delle varianti sono nella pagina) e raggruppati, pagine con blocchi o shortcode
di prodotti.

**I nonce.** Il rendering registra quali nonce la pagina stampa. Il nonce della
Store API (`wc_store_api`) e quello della REST API (`wp_rest`) restano nella
copia: la pagina riceve lo script `woocommerce-static.js`, che lascia passare
ogni richiesta e ferma solo quelle verso la REST API che portano il valore
vecchio della pagina; chiede valori freschi una volta
(`admin-ajax.php?action=static_bridge_public_woocommerce_nonces`, `no-store`) e
li mette negli header e nel corpo della richiesta. Così «aggiungi al carrello»
funziona anche giorni dopo la generazione. Una pagina con qualunque altro nonce
(un pulsante di pagamento rapido, una lista dei desideri o un confronto di un
plugin, il modulo di tracciamento dell'ordine) resta a WordPress, solo quella
rotta, con il motivo nell'Attività e nella Diagnostica; torna statica alla
generazione dopo che il nonce è sparito. Un plugin che sa che il suo nonce,
scaduto, non rompe niente lo dichiara con il filtro
`static_bridge_static_nonce_actions` (elenco di azioni). Nel tema a blocchi
predefinito di WooCommerce 11 le pagine del catalogo non hanno nonce.

**Gli aggiornamenti.** Con gli aggiornamenti incrementali accesi (necessari):

- l'immagine, il tipo di visualizzazione o l'ordine di una categoria di prodotti
  (meta del termine, cambiati anche dalla REST API) rigenerano la categoria, il
  negozio, la home e le pagine con prodotti;

- un cambio di un prodotto o di una variante da qualunque parte (schermata del
  prodotto, ordine, rimborso, REST API, importazione CSV, saldo programmato)
  accoda la pagina del prodotto e dei prodotti che lo mostrano col prezzo
  (raggruppati, up-sell), una sola scrittura della coda per richiesta;
- se cambia solo la quantità in magazzino (un ordine), basta la pagina del
  prodotto; prezzo, saldo, stato di magazzino, categorie, tag, visibilità e
  ordine del catalogo rigenerano anche negozio, categorie e tag con tutte le
  pagine, la home e fino a 30 pagine con un elenco di prodotti;
- le impostazioni del negozio che compaiono su ogni pagina (valuta e formato
  dei prezzi, tasse mostrate, formato delle scorte, prodotti esauriti nascosti,
  recensioni, colonne e righe del catalogo, «Prossimamente», pagine del
  negozio) rigenerano tutte le rotte una volta e riscrivono le regole;
- una nuova base dei permalink dei prodotti chiede una generazione completa.

**La Diagnostica** controlla: le tre pagine (escluse, nelle regole Apache, non
servite statiche da Nginx); `/wc-api/` che arriva a WooCommerce; la Store API
con il suo nonce; che i prodotti modificati più di recente abbiano una copia più
nuova della modifica o siano in coda; le pagine lasciate a WordPress e perché;
le impostazioni in contrasto con le copie statiche (modalità «Prossimamente»,
geolocalizzazione «con supporto della cache», tasse per paese del cliente).

**Limiti.**

- Le pagine di altri prodotti che mostrano questo fra i «prodotti correlati»
  (scelti a caso da WooCommerce) si aggiornano con il loro prossimo
  cambiamento o con la generazione completa.
- Prezzi per paese del cliente (geolocalizzazione con tasse), multi-valuta e
  prezzi per ruolo: la copia mostra i prezzi del visitatore anonimo nel paese
  del negozio. La geolocalizzazione «con supporto della cache» aggiunge `?v=`
  agli indirizzi e manda tutto a WordPress.
- Un plugin che cambia prodotti scrivendo direttamente nel database, senza le
  funzioni di WooCommerce, non viene visto: push del prodotto o generazione
  completa (la Diagnostica lo segnala come copia più vecchia).
- Plugin di liste dei desideri o di confronto con cookie propri: le loro pagine
  vanno messe fra i percorsi dinamici se non contengono un nonce.
- Gateway di pagamento reali, abbonamenti, prenotazioni e checkout
  personalizzati vanno provati sul proprio staging: la prova di Static Bridge
  usa il contrassegno e il bonifico.

## Strumenti di statistica

Static Bridge non ha integrazioni dedicate agli strumenti di statistica:
conserva nell'HTML renderizzato gli script e la configurazione emessi da
WordPress e lascia gli asset ai loro URL sullo stesso dominio. Instrada
staticamente solo `GET`/`HEAD`: un tracker che invia i dati con un `POST` al
sito (per esempio Koko Analytics) arriva quindi a WordPress anche dalle pagine
statiche. Endpoint personalizzati, cache, WAF e impostazioni di consenso vanno
verificati sul proprio sito; non rendere dinamica l'intera pagina solo per il
tracciamento standard.

## Complianz

Il banner, la lista degli script bloccati e la configurazione del banner finiscono nell'HTML: la copia statica li
contiene senza nonce né dati del visitatore, quindi **non serve escludere le pagine**. Ciò che dipende dal visitatore
passa da JavaScript e cookie: i cookie di consenso non escludono la pagina dalla copia statica, e le chiamate a
`/wp-json/complianz/v1/` restano su WordPress.

Il rischio è il contrario: l'HTML resta fermo a quando è stato generato. Se cambiano banner o impostazioni, la pagina
statica tiene la vecchia versione del banner e della policy, e chi aveva già dato il consenso non se lo vede chiedere di
nuovo. Dalla 2.2.3 Static Bridge accoda il refresh di tutti i percorsi statici, come per un blocco condiviso o il
Customizer, quando si salvano le impostazioni di Complianz (anche dalla procedura guidata), lo Script Center, le
integrazioni, il banner, i cookie e i servizi della Cookie Policy. Un'estensione che scrive nel banner o nelle pagine
legali può dichiarare le sue opzioni con il filtro `static_bridge_complianz_options` (2.4.3).

La decisione arriva a fine richiesta e confronta un'impronta delle impostazioni e della versione del banner con quella
salvata in `static_bridge_complianz_fingerprint`: il sito viene riaccodato solo quando qualcosa è davvero cambiato. Tutto
questo chiede gli aggiornamenti incrementali attivi: con gli incrementali spenti serve una generazione completa. Un passo
della Diagnostica confronta la versione del banner e della policy della homepage statica con quelle di Complianz.

Restano fuori, e chiedono una generazione completa o il push della pagina: la sincronizzazione automatica giornaliera di
cookie e servizi, che tocca solo la Cookie Policy, e l'attivazione o disattivazione di Complianz. Il test A/B dei banner
non è stato provato: la pagina statica porta un solo banner.

Con la geolocalizzazione la copia statica è uguale per tutti e porta la regione predefinita; il browser chiede poi la
regione del visitatore e applica banner e blocchi giusti, come su una pagina servita da WordPress (provato con indirizzi
UE, USA e messicano). Serve solo che `/wp-json/complianz/v1/` veda il vero IP del visitatore: dietro un proxy o una CDN
va controllato.

## TranslatePress

Le pagine tradotte sono pagine come le altre: l'export le trova dai link del selettore di lingua e salva l'HTML di ogni
lingua (`/en/`, `/de/dove-siamo/` o `/de/wo-wir-sind/`, con `lang` e testi tradotti).

Con gli slug tradotti, fino alla 2.2.3 ogni pagina con slug tradotto finiva in «Rendering WordPress non verificato» e
restava servita da WordPress. Dalla 2.2.4 la verifica del rendering conta anche l'indirizzo come il server lo ha passato,
letto quando Static Bridge si carica: vale per ogni plugin che riscrive `REQUEST_URI` dopo il caricamento dei plugin.

Gli aggiornamenti incrementali non conoscono gli indirizzi tradotti: salvare una pagina aggiorna la copia nella lingua
originale e le pagine che dipendono da lei, non le sue versioni in altre lingue. Static Bridge non aggiorna le copie
nemmeno quando si salvano le traduzioni dall'editor di TranslatePress. Dopo modifiche a pagine tradotte serve una
generazione completa.

## Builder, tema e risorse

Per Bricks, Kadence Theme, Kadence Blocks ed Etch viene acquisito il rendering
HTML reale prodotto da WordPress. Il documento conserva markup, stili inline e
URL verso CSS, JavaScript, font e upload. Questi asset non vengono copiati nella
release HTML: continuano a essere serviti dagli URL WordPress sullo stesso
dominio. La compatibilità con un builder va comunque collaudata sul proprio
staging, con il builder installato e attivo.

Gli aggiornamenti incrementali intercettano il salvataggio delle pagine, i
metadati Bricks delle singole pagine, i template Bricks (`bricks_template`, se
registrato) e gli elementi globali Kadence (`kadence_element`, se registrato),
oltre alle entità condivise WordPress `wp_block`, `wp_template`,
`wp_template_part`, `wp_navigation` e `wp_global_styles`. Il Customizer intercetta
anche i salvataggi delle impostazioni globali del tema. Per Bricks sono osservate
le opzioni globali documentate dal builder: classi, variabili, theme styles,
palette, breakpoint, componenti e pseudo-classi. Dalla 2.2.14 Static Bridge
rigenera le pagine quando cambiano le opzioni globali di Etch (`etch_styles`,
`etch_global_stylesheets`, `etch_loops` e il vecchio `etch_queries`,
`etch_components`, `etch_settings`): sono i dati che Etch salva nel sito. I
componenti Etch salvati come blocchi riutilizzabili sono già coperti dalle
entità condivise.

Quando uno di questi elementi globali viene aggiornato o rimosso, Static Bridge
accoda il refresh di tutti i percorsi HTML già presenti nella release: propaga,
per esempio, una modifica a header/footer riutilizzati su molte pagine. Non
ripete l'inventario né la scoperta completa del sito, ma il worker deve
renderizzare ogni route esistente; la vecchia release resta servita durante
l'elaborazione. I salvataggi delle singole pagine Bricks/Kadence continuano
invece ad aggiornare la route interessata.

Non è un aggiornamento per dipendenze selettive: su siti grandi il refresh di
tutti i percorsi può essere oneroso. Non vengono
intercettati file CSS/JS modificati direttamente, storage personalizzati o
impostazioni globali salvate al di fuori degli eventi elencati; per questi casi,
o quando cambiano le route pubblicate, eseguire un export completo e svuotare le
cache pertinenti. Un post type condiviso aggiuntivo può essere registrato tramite
`static_bridge_shared_content_post_types`. WP-Cron può ritardare l'elaborazione;
usare un cron di sistema per una finestra temporale prevedibile.

## Integrazione con altri plugin (2.0.23)

Hook pubblici, pensati per i builder (Histeon, prefisso tecnico `xb`, per primo) ma generici:

| Hook | Tipo | Effetto |
| --- | --- | --- |
| `static_bridge_dependents_for_post` | filtro `(null, WP_Post)` | Per un contenuto condiviso (blocco riutilizzabile, componente) il plugin che lo gestisce restituisce gli URL delle pagine che lo mostrano: si rigenerano solo quelle. Un URL fuori dal sito, con query, verso un file o con `..`, oppure più di 500 URL: la risposta viene ignorata e si rigenera tutto, come prima. |
| `static_bridge_post_has_query_loop` | filtro `(false, WP_Post)` | `true` se la pagina elenca contenuti in un modo che il markup non mostra (un loop dentro un componente, dati letti senza loop): viene rigenerata quando cambia un articolo. Conta solo `true` booleano. Sono riconosciuti anche senza filtro i blocchi `xb/loop`, `etch/loop`, i blocchi Kadence di post e query e (2.2.0) quelli del core come WordPress li salva, senza namespace: `wp:query`, `wp:latest-posts`, `wp:archives`, `wp:categories`, `wp:calendar`. |
| `static_bridge_queue_paths` | action `(array $percorsi_o_url, string $motivo)` | Accoda alla rigenerazione i percorsi indicati (`/chi-siamo/` o URL del sito), con le stesse regole di validazione. Al massimo 500 per chiamata. |
| `static_bridge_release_published` | action `(string $release_id, int $pagine)` | Emessa dopo l'attivazione atomica di una release completa. Un errore di chi ascolta viene registrato nel log e non annulla la pubblicazione. |
| `static_bridge_export_finished` | action `(string $status, array $summary)` (2.5.0) | Emessa una volta alla fine di ogni export completo: `complete`, `failed` o `cancelled`. `$summary` ha `job_id`, `release_id` (vuoto se la release non è stata pubblicata), `saved`, `failed`, `skipped`, `preserved`, `started_at`, `finished_at` e `message` (senza percorsi del server). Un errore di chi ascolta finisce in Attività e non cambia l'esito. |
| `static_bridge_failure_email_recipient` | filtro `(string $email)` (2.5.0) | Il destinatario dell'email «Avvisami per email se un aggiornamento fallisce» (spenta di serie), di serie l'indirizzo dell'amministratore di WP. Un valore che `is_email()` rifiuta torna all'amministratore. |
| `static_bridge_status` | filtro `(null)`, sola lettura (2.0.25) | Restituisce `version`, `serving` (copia statica servita), `incremental`, `pages` e `published_at` dell'ultima release completa, `pending` (pagine in coda), `export` (`idle`, `running`, `paused`, `failed`, `complete`, `cancelled`), `last` (messaggio e ora dell'ultima attività, senza dettagli) e `admin_url`. Legge solo opzioni: nessuna scansione di file, nessun percorso del server, impostazione o segreto. Histeon lo mostra in Release e nel builder. |
| `static_bridge_archive_urls_for_post` | filtro `(array(), WP_Post)`, solo aggiunte (2.0.25) | URL di elenchi da rigenerare in più quando cambia un contenuto, oltre a home, pagina articoli, archivio del tipo, autore e termini che Static Bridge sceglie da sé. Pensato per un plugin delle lingue (Lingua Bridge: `/en/`, `/en/category/…`). Non può togliere rotte; URL di altri siti, con query o non stringhe vengono scartati; al massimo 100. |
| `static_bridge_export_urls` | filtro `(array())`, solo aggiunte (2.2.0) | URL che l'export completo aggiunge all'inventario, dopo contenuti e archivi: per esempio gli archivi nelle altre lingue (`/en/category/…`). Stesse regole di validazione; al massimo 100.000. |
| `static_bridge_archive_max_pages` | filtro `(100)` (2.2.0) | Pagine `/page/N/` per archivio, nell'export e nell'aggiornamento incrementale; dalla 2.2.1 anche il massimo di una `/page/N/` raggiunta da un link. Minimo 1. |
| `static_bridge_security_headers` | filtro `(array $intestazioni)` (2.2.1) | Le intestazioni di sicurezza che le pagine statiche ripetono, `nome => valore`, prese dalla home all'ultimo export completo. Si possono aggiungere, cambiare o togliere solo X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, Strict-Transport-Security e Content-Security-Policy; valori con `\`, `%`, `$`, caratteri non ASCII o di controllo, più di 2048 byte o un `nonce-` nella CSP vengono scartati; le virgolette doppie (le origini di Permissions-Policy) si scrivono escapate. Vale per il `.htaccess` alla prossima scrittura e per il blocco Nginx da ricopiare. |
| `static_bridge_archive_post_types` | filtro `(array('post'), string $contesto)` (2.2.0) | Tipi di contenuto elencati da home (`front`), archivi autore (`author`) e per data (`date`); serve solo se un `pre_get_posts` del tema cambia la query principale di quegli archivi. |
| `static_bridge_query_page_limit` | filtro `(30)` (2.2.0) | Quante pagine-lista (Query Loop, loop dei builder) rigenerare per ogni modifica, tra le 500 modificate più di recente. Da 1 a 500. |
| `static_bridge_excluded_paths` | filtro `(array(), array $percorsi)`, solo aggiunte (2.2.0) | Percorsi da tenere dinamici oltre a quelli delle Impostazioni (per esempio `/en/iscriviti/` accanto a `/iscriviti/`). Valgono per l'export, il `.htaccess`, il blocco Nginx (da ricopiare) e la regola Cloudflare. Al massimo 500; `/` e percorsi non validi vengono scartati. |
| `xb_release_published` | ascoltata | Quando una release di Histeon contiene `styles` o `settings` (stili e impostazioni globali, opzioni che Static Bridge non osserva) vengono rigenerati tutti i percorsi. Le pagine cambiate arrivano già da `wp_after_insert_post`. |

Aprire un contenuto condiviso nell'editor (`_edit_lock`, `_edit_last`) non accoda più alcuna rigenerazione.

Limite attuale: quando cambia un articolo vengono cercate le pagine di elenco tra i 500 contenuti modificati più di
recente e se ne rigenerano al massimo 30 (filtro `static_bridge_query_page_limit`, 2.2.0), oltre all'articolo e a tutte
le pagine dei suoi archivi. Quando il limite viene raggiunto Attività lo segnala (al massimo una volta al giorno); con più
pagine di elenco serve un export completo dopo le modifiche. Le liste che dipendono dalla data senza che cambi un
contenuto (eventi futuri e passati) si aggiornano solo con un export completo.

Una pagina accodata che WordPress reindirizza (per esempio l'archivio autore che un plugin SEO manda alla homepage)
perde la copia statica, così vale il redirect, e la destinazione viene accodata (2.0.24).

## Altre funzioni interattive e cache

Ricerca, login, personalizzazione per utente, commenti e logiche server-side non
diventano statici solo perché la pagina è stata esportata. Gli endpoint devono
restare dinamici e raggiungibili, oppure il percorso va escluso. Cache plugin,
optimizer, plugin di sicurezza, CDN e regole WAF non hanno una compatibilità
universale certificata: controllare che non servano HTML obsoleto, non blocchino
le richieste interne dell'export e non alterino nonce o routing.
