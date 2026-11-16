# Guida utente

## Prima di iniziare

Static Bridge richiede WordPress 6.9 o successivo e PHP 8.4 o successivo. Prima
del live usare uno staging con backup ripristinabile e collaudare routing, cron e
PHP-FPM sul server destinatario, con il fallback WordPress e la modalità Strict
disattivata durante il primo collaudo. PHP deve scrivere in wp-content e creare
symlink; il dominio deve essere raggiungibile dal processo PHP per
l'esportazione. Static Bridge non può forzare un CDN configurato per ignorare
query, cookie e header.

Lo ZIP della release (`static-bridge-<versione>.zip`) installa insieme il file
principale, i moduli e la cartella assets: non installare il solo file PHP
principale. I manuali sono nel repository dei sorgenti, non nello ZIP.

## Attivazione e configurazione iniziale

1. Attivare Static Bridge da Plugin. Il preflight controlla requisiti noti e
   interrompe l'attivazione prima di modificare impostazioni o routing se
   rileva errori bloccanti. Non può prevedere ogni errore causato dal core,
   dall'hosting o da un altro plugin prima che il callback venga eseguito.
   Dalla 2.2.1 si ferma anche se la home è in una sottocartella (`/blog/`) o se
   la cartella dei contenuti non è `/wp-content/` (Bedrock, WordPress nella sua
   cartella con i contenuti spostati): le regole del server e le inclusioni SSI
   usano `/wp-content/static-bridge/` alla radice del dominio, quindi lì nessuna
   pagina sarebbe servita statica. Su un'installazione già attiva in uno di
   questi casi l'amministrazione mostra il motivo.
2. Aprire Static Bridge → Impostazioni. Confermare il dominio, controllare i
   percorsi dinamici e scegliere se attivare la modalità strict e gli
   aggiornamenti incrementali.
3. Se si usa Fluent Forms, aprire Moduli e verificare le route rilevate. Per
   i moduli senza token a scadenza si può scegliere pagina statica con submit
   dinamico oppure pagina interamente dinamica. Dalla 2.0.16, con l'opzione
   “Rinnova nonce e token anti-spam nelle pagine statiche” (attiva di default),
   anche le pagine con protezione token Fluent Forms o con verifica del nonce di
   invio restano statiche, compresi i moduli nel footer o in un popup di tutte
   le pagine: lo script aggiunto chiede nonce aggiornati al primo uso del
   modulo. Se il markup di una pagina non è riconosciuto, quella pagina resta
   dinamica con il motivo indicato nella scheda (su Nginx reinstallare poi il
   blocco di routing). Disattivando l'opzione si torna al comportamento
   precedente: pagine con protezione token sempre dinamiche.
   L'elenco è paginato; salvare una pagina mantiene le scelte delle altre.
   Collaudare invio e add-on sullo staging.
4. Se WooCommerce è installato e attivo, **Integrazione WooCommerce** è accesa
   di serie (dalla 2.2.13, finché non la si spegne e salva) e il negozio è
   compatibile per intero, vedi [WooCommerce](DYNAMIC-CONTENT.md#woocommerce). Restano a
   WordPress Carrello, Checkout e Il mio account con i loro endpoint (ordine
   ricevuto, pagamento), `/wc-api/` e `/wc-auth/`, e ogni pagina che WooCommerce
   o un altro plugin non vuole in cache; il catalogo resta statico e si aggiorna
   da solo con prezzi, saldi e scorte. Con WooCommerce attivo e l'interruttore
   spento la Diagnostica dà un avviso.
5. Configurare il server seguendo [Apache e Nginx](SERVER-SETUP.md). Nginx
   richiede un intervento manuale e il reload della configurazione.
   OpenLiteSpeed legge il `.htaccess` della radice solo all'avvio: dalla 2.3.0
   la Diagnostica dice quando riavviarlo (dopo il primo export e dopo ogni
   salvataggio che cambia le regole), e Impostazioni → OpenLiteSpeed mostra il
   contesto per le intestazioni delle pagine statiche, vedi
   [LiteSpeed e OpenLiteSpeed](SERVER-SETUP.md#litespeed-e-openlitespeed-dalla-230).
6. Dalla 2.3.0 aprire Static Bridge → Server (la bacheca lo propone finché il
   server non è stato controllato una volta). Il primo controllo parte da solo
   e dice, voce per voce, cosa è a posto e cosa conviene chiedere all'hosting:
   vedere [La scheda Server](SERVER-SETUP.md#la-scheda-server-dalla-230).

I valori iniziali prevedono il fallback a WordPress (strict disattivato),
aggiornamento incrementale attivo, archivi nell'export attivi, WooCommerce
disattivato e le esclusioni iniziali /iscriviti/ e /contatti/. Verificare comunque i valori visualizzati
nella GUI del sito: impostazioni preesistenti possono prevalere sui default.

## Prima esportazione

1. Eseguire Diagnostica prima della generazione e risolvere gli errori.
2. Da Genera avviare la generazione completa. L'inventario parte dalla home e
   dai contenuti pubblicati, pubblici, interrogabili e senza password; poi,
   con **Archivi nell'export** attivo (predefinito dalla 2.2.0), aggiunge gli
   archivi: categorie, tag e tassonomie pubbliche (anche vuote: WordPress le
   serve con 200), archivi dei tipi di contenuto, autori e date (anno, mese,
   giorno), ciascuno con le sue pagine `/page/N/`. Infine segue i link interni
   delle pagine renderizzate. Le sitemap non vengono lette: l'inventario viene
   dal database. Gli allegati non sono esportati come pagine.
   Il numero di pagine di un archivio si calcola dai contenuti pubblicati e da
   *Impostazioni → Lettura → Le pagine del blog visualizzano al massimo*, con un
   massimo di 100 per archivio (filtro `static_bridge_archive_max_pages`); una
   pagina oltre l'ultima a cui WordPress risponde 404 non è un errore. Un
   archivio che WordPress reindirizza (per esempio un plugin che manda gli
   autori alla home) resta dinamico. Con l'opzione disattivata entrano solo gli
   archivi raggiunti dai link e quelli già creati dall'aggiornamento
   incrementale, che rigenera solo la prima pagina degli elenchi di un
   contenuto, come fino alla 2.1.0.
   I link a una `/page/N/` oltre quel massimo non vengono seguiti (2.2.1): una
   paginazione senza fine, come la navigazione di un calendario, non porta
   l'export oltre il limite di URL.
   Gli URL seguono la struttura dei permalink (2.2.1): con `/%postname%` le
   pagine si salvano senza slash finale, con `/%postname%.html` anche le route
   `.html` sono pagine; la forma che WordPress non collega resta a WordPress,
   che risponde con il suo 301. Una route si salva nella cartella che il server
   cerca, con i caratteri dell'URL decodificati: `/tag/pdf/`, `/tag/c/`,
   `/tag/_bozza/` e `/caffè/` diventano statiche come le altre (fino alla 2.2.0
   finivano in `tag/unnamed-file.pdf/` e simili). Un segmento con caratteri che
   un nome di file non può contenere in modo sicuro (spazi, `%`, `?`, `#`,
   controlli, punto iniziale, UTF-8 non valido, più di 255 byte) resta dinamico
   e l'URL compare tra quelli saltati.
3. La GUI e WP-Cron elaborano il lavoro in batch; dalla 2.2.14 l'export va
   avanti anche chiudendo la scheda, con richieste di fondo del server a se
   stesso. Un sito grande può richiedere molto tempo; un cron di sistema rende
   più regolare l'avanzamento. Se il server (o Cloudflare) risponde 429 «troppe
   richieste», l'export rallenta, rispetta l'attesa chiesta e riprova le pagine
   rifiutate alla fine; quelle ancora rifiutate compaiono in Genera con
   «Riprova queste».
4. Controllare Attività e l'elenco URL saltati. Dopo i retry esauriti, una
   route con errore viene registrata e saltata mentre le altre continuano. Se
   esiste una copia precedente verificabile, viene conservata; senza copia,
   con Strict attivo quella URL potrebbe restituire 404, mentre col fallback
   WordPress può ancora servirla dinamicamente. I 401/403/404/410 sono skip
   ordinari. Errori di database, disco, scrittura o pubblicazione fermano
   ancora il job e lasciano attiva la release precedente. Per una coda già in
   pausa dalla versione precedente, aggiornare il plugin e premere Riprendi
   esportazione una volta per saltare la URL esaurita e proseguire.
5. Solo a job completato, ripetere Diagnostica e verificare alcune pagine
   pubbliche, pagine dinamiche, login e backend. Le richieste idonee devono
   essere servite dal server web come file HTML, non dal rendering PHP.

Il limite prudenziale corrente è 100.000 URL unici per release. Ogni pagina
HTML superiore a 5 MiB viene rifiutata. Questi limiti non rappresentano una
promessa di prestazioni su un sito di quelle dimensioni.

## Dopo la prima release

Con l'aggiornamento incrementale attivo, la pubblicazione o modifica di contenuti
pubblici accoda gli URL interessati e alcune viste che li contengono. Il
salvataggio di componenti globali tracciati — entità condivise Gutenberg,
template Bricks, elementi Kadence, dati globali Bricks, stili, fogli globali,
loop e impostazioni di Etch e impostazioni dal Customizer — accoda tutte le
route HTML già presenti, così header/footer
condivisi si aggiornano anche nelle pagine non salvate singolarmente. Non viene
ripetuta la scansione completa dell'inventario; il worker aggiorna in coda i file
della release. La copia corrente resta online durante il lavoro; una nuova
pagina non disponibile nella release continua temporaneamente a ricadere su
WordPress, salvo modalità strict. Per CSS/JS modificati direttamente, snippet
di codice e storage globali non intercettati basta «Aggiorna tutte le pagine
statiche» in Genera (2.2.14), che rigenera le pagine già statiche senza rifare la
scansione del sito. Genera mostra anche quando è stata aggiornata l'ultima
pagina; l'elenco con il motivo è in Attività, e «Pagine dinamiche» dice perché
ciascuna resta a WordPress.

Complianz (2.2.3). Il banner dei cookie, gli script bloccati e il numero della
policy stanno nell'HTML, quindi una pagina statica resta ferma all'ultima
generazione. Con l'aggiornamento incrementale attivo non c'è niente da fare: ogni
salvataggio in Complianz (impostazioni, banner, Script Center, integrazioni,
cookie e servizi) accoda tutte le route, e chi aveva già dato il consenso viene
richiesto di nuovo quando Complianz lo prevede. Il passo **Banner e policy di
Complianz** della Diagnostica dice se la homepage statica è allineata. Non serve
escludere pagine: il consenso sta nei cookie del visitatore e nelle chiamate a
`/wp-json/complianz/v1/`, che restano su WordPress. Una generazione completa serve
solo con l'incrementale spento, o se Complianz è stato cambiato con una versione
precedente alla 2.2.3 e la Diagnostica segnala la differenza (basta anche un
salvataggio in Complianz). Dettagli in [Form e contenuti dinamici](DYNAMIC-CONTENT.md#complianz).

WooCommerce (2.2.13, con l'integrazione accesa). Prezzi, saldi, scorte e
disponibilità stanno nell'HTML del catalogo. Non c'è niente da fare: ogni cambio
di un prodotto o di una variante accoda la sua pagina, anche quando arriva da un
ordine, dalla REST API, da un'importazione o da un saldo programmato. Una scorta
che scende con un ordine rigenera solo la pagina del prodotto (e del prodotto
raggruppato che lo contiene); un prezzo, un saldo, un «esaurito», una categoria o
l'ordine del catalogo rigenerano anche negozio, categorie e tag con tutte le loro
pagine, la home e le pagine con un blocco o uno shortcode di prodotti. Le
impostazioni del negozio che si vedono su ogni pagina (valuta, formato dei
prezzi, tasse mostrate, «Prossimamente», pagine del negozio) rigenerano tutto una
volta; cambiare la base dei permalink dei prodotti chiede una generazione
completa. Ricerca, filtri, ordinamento, «aggiungi al carrello», mini-carrello e
Store API restano su WordPress; chi ha un carrello riceve sempre le pagine di
WordPress. Se una pagina del catalogo contiene un nonce che Static Bridge non sa
rinnovare (per esempio un pulsante di pagamento rapido o una lista dei
desideri), quella sola pagina resta a WordPress e la Diagnostica la nomina.
Dettagli in [Form e contenuti dinamici](DYNAMIC-CONTENT.md#woocommerce).

TranslatePress (2.2.4). Le pagine in altre lingue diventano statiche come le
altre, anche quelle con lo slug tradotto dal SEO Pack (`/en/where-we-are/`):
prima della 2.2.4 restavano a WordPress con «Rendering WordPress non verificato».
Gli aggiornamenti incrementali però aggiornano solo la lingua principale: dopo
aver modificato una pagina o una traduzione serve una generazione completa perché
le versioni nelle altre lingue seguano. Dettagli in
[Form e contenuti dinamici](DYNAMIC-CONTENT.md#translatepress).

Archivi e pagine-lista (2.2.0, con **Archivi nell'export** attivo). Quando un
articolo viene pubblicato, modificato,
rimesso in bozza, cestinato o reso privato, si rigenerano tutte le pagine degli
elenchi in cui compare: home (se mostra gli ultimi articoli) e pagina articoli,
archivio del tipo di contenuto, autore, ogni termine **e i suoi genitori**,
anno, mese e giorno, ciascuno da `/page/1/` all'ultima pagina (una pagina della
release oltre la nuova ultima viene rimossa). Si rigenerano anche le pagine con
un Query Loop, Ultimi articoli, Archivi, Categorie o Calendario del core, un loop
Etch o Histeon, tra le 500 modificate più di recente e al massimo 30 (filtro
`static_bridge_query_page_limit`). Creare, rinominare o eliminare un termine
sposta o rimuove la sua route, cambiare lo slug di un autore sposta il suo
archivio. Cambiare articoli per pagina, permalink, basi di categorie e tag o la
pagina iniziale rigenera tutte le pagine statiche e chiede in Attività un export
completo, che aggiunge le route nuove. Le route create dall'aggiornamento
incrementale restano nel successivo export completo anche se nessuna pagina le
collega.

Intestazioni di sicurezza (2.2.1). A ogni export completo Static Bridge legge
dalla home renderizzata le intestazioni X-Frame-Options, X-Content-Type-Options,
Referrer-Policy, Permissions-Policy, Strict-Transport-Security e
Content-Security-Policy (solo senza `nonce-`, che cambia a ogni richiesta) e le
fa inviare anche alle pagine statiche, che prima le perdevano. Su Apache
servono mod_headers; su Nginx, quando cambiano, Attività chiede di ricopiare il
blocco. Il passo **Intestazioni di sicurezza sulle pagine statiche** della
Diagnostica controlla la home pubblica. Le intestazioni per pagina
(`X-Robots-Tag`, `Set-Cookie`) non vengono ripetute: una pagina che deve averle
va resa dinamica. Le intestazioni della home valgono per tutte le pagine
statiche: se un plugin invia su qualche pagina una Content-Security-Policy più
larga (un embed, un pagamento), rendi dinamica quella pagina oppure usa il filtro
`static_bridge_security_headers` (restituendo un array vuoto le pagine statiche
non ne ripetono nessuna).

Riattivazione (2.2.1). Disattivando il plugin le release restano sul disco e
alla riattivazione la più recente torna servita. Le modifiche fatte nel
frattempo non sono mai state accodate: se dopo l'ultima release sono cambiati
articoli o pagine, Static Bridge lo segnala in Attività e nell'amministrazione e
tiene spenta la modalità strict, che altrimenti risponderebbe 404 ai contenuti
nuovi, fino al prossimo export completo.

Per aggiornare subito un singolo articolo o una pagina senza tornare alla
dashboard di Static Bridge, usa **Static Bridge → Aggiorna copia statica**
nella sidebar dell'editor oppure **Push alla versione statica** nelle azioni
rapide dell'elenco contenuti. Il comando elabora subito la route selezionata e
accoda solo gli archivi correlati: non ripete l'export completo. È disponibile per
contenuti pubblicati, pubblici e non esclusi/dinamici, quando esiste una release
e gli aggiornamenti incrementali sono attivi. Il messaggio di successo appare
solo dopo la scrittura del file statico e del manifest. Se un worker/export è
occupato o il rendering fallisce, la copia precedente resta online e la GUI
mostra retry/errore. Dalla 2.5.0 lo stesso aggiornamento si avvia anche dalla
barra di amministrazione («Aggiorna la copia statica») o con
`wp static-bridge push <id>`.

Con un client MCP collegato alle abilità di WordPress (Abilities API),
`static-bridge/inspect-post-push` legge release,
coda, stato, mtime e hash senza modificare il sito. Per l'azione controllata
`static-bridge/push-post`, specifica `post_id` e `confirm: true`: aggiorna solo
la route del contenuto, non l'intero sito. Entrambe le abilità richiedono un
amministratore WordPress; il push controlla anche `edit_post`, rifiuta percorsi
dinamici/esclusi e riporta `completed`, `retrying` o `failed`. Non invocare il
push senza la conferma di chi gestisce il sito.

Ogni export completo crea una nuova copia intera del sito in
`wp-content/static-bridge/releases/`. Dalla 2.4.0 la scheda **Versioni** le
mostra tutte, una per blocco, con data, pagine, peso e stato (online,
precedente, export in corso o in pausa, staging fallito o annullato):

- ogni versione che non serve ha il suo pulsante **Elimina**, che chiede
  conferma; un pulsante elimina in blocco quelle che non servono, tenendo la
  versione online, una precedente e quelle protette;
- le versioni protette si vedono con il motivo e non si eliminano: quella
  online, quella registrata per il ripristino dopo un'importazione All-in-One,
  quella di un export in corso o in pausa, quelle usate dagli indici V2 o dalla
  verifica SSI.

Sui siti nuovi (dalla 2.4.0) la **pulizia automatica** è accesa e tiene una
versione precedente: cinque minuti dopo ogni pubblicazione riuscita si
eliminano le altre e gli staging falliti o annullati più vecchi di un'ora. Chi
aggiorna da una versione precedente mantiene la sua scelta: se la pulizia era
spenta resta spenta, niente viene eliminato da solo, e Versioni propone di
attivarla. Il numero di versioni da tenere (1–20) si cambia in **Impostazioni →
Conservazione automatica delle release**. Regole e protezioni sono descritte in
[Operazioni e ripristino](OPERATIONS.md#spazio-e-conservazione).

## Sito dietro Cloudflare

Se il dominio passa da Cloudflare (nuvola arancione), apri **Impostazioni →
Cloudflare e CDN**:

1. In **Indirizzo di origine per il rendering** inserisci `127.0.0.1` (o l'IP
   del server di origine) e salva: export e push non passeranno più da
   Cloudflare, quindi niente blocchi WAF e niente Rocket Loader nelle copie.
   Esegui la Diagnostica: *Rendering HTTP dal processo PHP* deve indicare la
   connessione diretta.
2. Se vuoi che Cloudflare metta in cache l'HTML, copia la **regola di cache
   consigliata** in Cloudflare, imposta un **Cache-Control** (ad esempio
   `public, max-age=0, s-maxage=600`) e attiva lo **svuotamento automatico**
   dopo aver aggiunto Zone ID e token (solo permesso Cache Purge) a
   `wp-config.php`. Senza svuotamento non mettere in cache l'HTML.

I dettagli sono in [Configurazione del server](SERVER-SETUP.md#cloudflare-e-altre-cdn-dalla-2017).

La guida per i form è in [Form e contenuti dinamici](DYNAMIC-CONTENT.md);
per annullare o disattivare senza cancellare dati, seguire
[Operazioni e ripristino](OPERATIONS.md).
