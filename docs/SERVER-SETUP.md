# Configurazione del server

Static Bridge non è un reverse proxy applicativo. WordPress produce file HTML;
sono le regole del web server a scegliere quando servirli e quando passare la
richiesta a WordPress. Il backend resta sullo stesso hostname.

## Prerequisiti comuni

- Fare un backup ripristinabile del sito e della configurazione server.
- La home deve essere alla radice del dominio e i contenuti in `/wp-content/`:
  dalla 2.2.1 l'attivazione si ferma con una home in sottocartella o una
  cartella dei contenuti spostata, perché regole e inclusioni SSI usano
  `/wp-content/static-bridge/`.
- Verificare document root, permessi e supporto ai symlink.
- Lasciare il routing WordPress/PHP esistente come fallback.
- Non esporre le cartelle interne delle release tramite alias o location
  aggiuntive.
- Installare le regole prima di dichiarare il frontend statico attivo e
  verificare il risultato con Diagnostica.

Il plugin genera file sotto wp-content/static-bridge, ma la directory e i
symlink non rendono statico il frontend da soli: senza routing del server le
richieste continueranno a essere gestite dal normale stack WordPress.

## Apache

All'attivazione il plugin inserisce il blocco Static Bridge nel .htaccess del
document root, prima di BEGIN WordPress. Richiede mod_rewrite e che il server
consenta le direttive necessarie via .htaccess. Fare una copia del file prima
dell'attivazione e controllare il blocco generato dopo aver salvato impostazioni
o esclusioni.

Il routing statico accetta GET/HEAD senza query string e senza cookie di
sessione (login, commentatore, password dei contenuti protetti `wp-postpass_`,
WooCommerce), solo per percorsi ammessi, solo con il marcatore `.enabled` e
solo se la pagina esiste nella release corrente. Dalla 2.2.0, con permalink che
terminano con `/`, solo i percorsi con lo slash finale: `/x` va a WordPress, che
risponde con il suo 301 verso `/x/`. Dalla 2.2.1, con permalink senza slash
finale (`/%postname%`), il contrario: `/x/` va a WordPress; con `/%postname%.html`
le route `.html` sono pagine, non file. Restano sempre a WordPress anche i feed
nella forma breve (`/rss2/`, `/atom/`, `/category/x/rdf/`) e la ricerca
(`/search/…`). Le richieste escluse ricadono su WordPress. In strict, una
richiesta pubblica idonea non presente nella release restituisce 404 invece del
fallback PHP; dalla 2.2.0 solo per i percorsi di pagina: un file
(`/favicon.ico`, qualunque percorso con estensione) e `/.well-known/` vanno
sempre a WordPress (con la struttura `.html` tranne le route `.html`), e il 404
di Strict porta sempre `Cache-Control: no-store`
(su Apache con `mod_headers`), così un CDN non lo conserva. Un percorso dinamico
con spazi viene scritto con `\x20` nel `.htaccess` (fino alla 2.1.0 rompeva la
sintassi e portava in 500 tutto il sito). Il blocco di
protezione nega l'accesso diretto a tutta `wp-content/static-bridge/` (come
Nginx) e può restare nel file dopo la disattivazione: non rimuoverlo finché
esistono release.

**Usare almeno la 2.0.14 su Apache.** Fino alla 2.0.13 le
condizioni di guardia valevano solo per la home: le altre pagine pubblicate
venivano servite statiche anche a utenti loggati, POST, anteprime e query
string, e la cartella `wp-content/static-bridge/` era leggibile per URL diretto.
Aggiornando, il blocco viene riscritto alla prima pubblicazione o al salvataggio
delle Impostazioni; verificare il `.htaccess` risultante.

**Preferire la 2.0.15 su Apache.** Fino alla 2.0.14 ogni rendering interno
spostava per pochi millisecondi il file statico della route: un visitatore che
arrivava tra il controllo `-f` di mod_rewrite e l'apertura del file riceveva un
403 (`AH00132`) o un 404. Dalla 2.0.15 il file resta al suo posto e la richiesta
di rendering raggiunge WordPress grazie alla guardia su query e cookie; lo
spostamento resta solo come ripiego se le regole del server ignorano la guardia.

Per tornare a WordPress, disattivare il plugin dalla bacheca e verificare il
sito. Se il plugin non riesce a scrivere .htaccess, la GUI registra l'errore:
non assumere che le nuove regole siano attive.

### Pagine troncate nell'istante di una pubblicazione (corretto nella 2.0.20)

Apache legge la dimensione di un file durante i controlli del percorso e lo
apre subito dopo. Fino alla 2.0.19 le regole servivano `current/`: se nel
frattempo il file veniva sostituito, perché veniva pubblicata una nuova release
(scambio di `current`) o aggiornata una singola pagina (rinomina atomica),
Apache inviava il contenuto nuovo con la lunghezza vecchia, cioè la pagina
tagliata o allungata con un `Content-Length` coerente. Riprodotto con Apache
2.4.43 senza il plugin: 207 risposte errate su 138.864 con scambi di `current`,
333 su 138.400 con rinomine di un file, 0 senza scambi. Nginx apre il file
prima di leggerne la dimensione e non ha il problema.

**Dalla 2.0.20 le regole Apache servono una copia immutabile** della release
corrente, `wp-content/static-bridge/served/<id>/`, fatta di hard link (nessun
dato duplicato, solo cartelle e voci di directory). Ogni export, push o
aggiornamento incrementale crea una copia nuova e riscrive `.htaccess` per
puntarla; un file già servito non cambia mai. Le copie precedenti vengono
eliminate 60 secondi dopo che le regole hanno smesso di nominarle, mai finché
`.htaccess` le nomina.

- Senza hard link (alcuni filesystem di rete, `link()` disabilitata,
  `open_basedir`) la copia è byte per byte: costa spazio, non correttezza.
- Se la copia non si può creare (spazio, permessi di
  `wp-content/static-bridge/served`), le regole tornano a `current/` come nella
  2.0.19, con un avviso nel Registro e nella Diagnostica; il plugin riprova da
  solo.
- `define('STATIC_BRIDGE_APACHE_SERVED_COPIES', false);` in `wp-config.php`
  forza le regole su `current/` (comportamento 2.0.19), come interruttore di
  emergenza.
- Su Apache un push risulta completato, e la cache Cloudflare viene svuotata,
  solo quando `.htaccess` serve già la pagina nuova; se `.htaccess` non è
  scrivibile il push resta in coda con un messaggio esplicito.
- Le regole non attraversano più il collegamento simbolico `current`: il
  routing funziona anche con `Options -FollowSymLinks`.
- Un sito aggiornato dalla 2.0.19 passa alle copie alla prima pubblicazione, al
  salvataggio delle Impostazioni o al primo accesso di un amministratore alla
  bacheca (cron di manutenzione).

## LiteSpeed e OpenLiteSpeed (dalla 2.3.0)

LiteSpeed legge il file `.htaccess`: Static Bridge scrive lì le stesse regole
che scrive per Apache. Su LiteSpeed, sia Enterprise sia OpenLiteSpeed, le pagine
precompresse restano spente: il server comprime da solo i file statici, con gzip
e Brotli, e tiene la copia compressa.

OpenLiteSpeed ha tre differenze, provate con OpenLiteSpeed 1.9.4 (stack di prova
`litespeed`, `tests/e2e/litespeed-e2e.sh`). Static Bridge lo riconosce dalla
variabile `LSWS_EDITION` che il server passa a PHP, e lo ricorda per WP-CLI e il
cron di sistema, che non hanno un server.

1. **Il `.htaccess` della radice si legge solo all'avvio.** Le regole scritte
   dopo valgono al prossimo riavvio del server: Graceful Restart dal WebAdmin o
   dal pannello dell'hosting, oppure `lswsctrl restart`. Va fatto dopo il primo
   export e dopo ogni salvataggio delle Impostazioni che cambia le regole
   (esclusioni, Cache-Control, struttura dei permalink, moduli, WooCommerce, reindirizzamenti e domini delle lingue di Lingua Bridge).
   Finché il server non riparte, i visitatori ricevono le pagine di WP o le
   regole precedenti: nessuna pagina si rompe.
   - Le regole servono `current/` e non una copia per pubblicazione: le pagine
     pubblicate dopo, con push, aggiornamenti incrementali o export completi, si
     vedono subito, senza riavvii. Le esclusioni temporanee della coda
     incrementale non entrano nelle regole, come su Nginx: decide il controllo
     che la pagina esista nella release.
   - Il blocco contiene una regola di prova,
     `RewriteRule ^static-bridge-rules-<12 cifre esadecimali>$ - [R=410,L]`, che
     cambia con le regole. La Diagnostica chiede quell'indirizzo: 410 vuol dire
     che il server applica le regole attuali; altrimenti il passo «Regole del
     server web» fallisce e chiede il riavvio. Anche Attività lo segnala a ogni
     cambio delle regole.
2. **Il `.htaccess` delle sottocartelle si rilegge subito.** Static Bridge scrive
   `wp-content/static-bridge/.htaccess` con `RewriteRule ^ - [F]`. Così
   nessun file della cartella è raggiungibile per indirizzo diretto, da subito e
   anche prima del primo riavvio. Le pagine statiche ci arrivano dalle regole
   della radice, che chiudono la riscrittura prima di questo file. Su Apache il
   file non viene scritto, e se ne trova uno suo lo toglie: un hosting che non
   ammette queste direttive in una sottocartella risponderebbe 500.
3. **Le direttive `Header` del `.htaccess` vengono ignorate**, e con loro
   Cache-Control, intestazioni di sicurezza e il blocco «compressione e cache
   dei file» della scheda Server. Impostazioni → OpenLiteSpeed mostra un
   contesto da aggiungere al virtual host, con le intestazioni delle sole pagine
   statiche:

   ```
   context /wp-content/static-bridge/ {
     location                $DOC_ROOT/wp-content/static-bridge/
     allowBrowse             1
     extraHeaders            <<<END_extraHeaders
   Cache-Control: public, max-age=0, s-maxage=600
   X-Content-Type-Options: nosniff
     END_extraHeaders
   }
   ```

   Dal WebAdmin: Virtual Host → Context → Static, URI
   `/wp-content/static-bridge/`, le stesse righe in Header Operations; poi
   riavvia. Una richiesta riscritta prende le intestazioni del contesto del file
   su cui arriva: le pagine di WP non cambiano. L'accesso diretto alla cartella
   resta rifiutato dal suo `.htaccess`. Il 404 di Strict resta senza
   `no-store`. Va ricopiato quando cambiano il Cache-Control o le intestazioni
   di sicurezza (dopo un export completo).

## Nginx

Nginx non legge .htaccess e Static Bridge non modifica né ricarica la
configurazione. Copiare il blocco aggiornato mostrato in Impostazioni nella
configurazione del server:

1. Fare una copia del file/template interessato.
2. Sostituire la location / esistente con il blocco fornito dalla GUI,
   mantenendo la location PHP e la location @wperror già usate dal sito.
3. Conservare le regole specifiche dell'ambiente, ad esempio la normalizzazione
   dello slash per wp-admin.
4. Controllare il file con nginx -t e ricaricare Nginx usando il meccanismo
   dell'hosting (o di LocalWP, per un sito locale).
5. Eseguire Diagnostica. Dopo modifiche a esclusioni o WooCommerce, ricopiare
   il blocco aggiornato e validarlo di nuovo. Con WooCommerce (2.2.13) il blocco
   esclude anche `/wc-api/`, `/wc-auth/` e le pagine che l'integrazione ha lasciato
   a WordPress (nonce non rinnovabili, pagine private): ricopiarlo quando la
   Diagnostica o l'Attività le segnalano, e dopo aver cambiato le pagine del
   negozio. Aggiornando alla 2.2.0 ricopiare
   il blocco: aggiunge feed brevi, ricerca, file e `/.well-known/` al fallback
   WordPress, lo slash finale, il cookie `wp-postpass_` e il `no-store` sul 404
   di Strict. Il blocco va ricopiato anche dopo un cambio di struttura dei
   permalink (con o senza slash finale, `.html`). Aggiornando alla 2.2.1
   ricopiarlo dopo il primo export completo: ripete in ogni location statica le
   intestazioni di sicurezza del sito (`add_header … always`, perché una
   location con un suo `add_header` non eredita quelli del blocco `server`), e
   va ricopiato quando Attività segnala che sono cambiate.

Il blocco include la protezione di wp-content/static-bridge/ e della cartella
predefinita wp-content/ai1wm-backups/ (archivi All-in-One WP Migration), le
esclusioni per backend, REST, login, query string, metodi diversi da GET/HEAD,
cookie riconosciuti e percorsi configurati, oltre al fallback WordPress. Su
Nginx le regole `.htaccess` create da altri plugin non proteggono queste
cartelle: se All-in-One usa un percorso di backup personalizzato, aggiungere e
validare una regola `location` equivalente per quel percorso. L'attivazione
crea il marcatore .enabled; la disattivazione lo rimuove. Il marcatore non
sostituisce la configurazione server.

## Verifica del routing

- Con release attiva, richiedere una pagina pubblica non autenticati e
  controllare in Diagnostica che la risposta coincida con il file HTML corrente.
- Il blocco Nginx aggiunge X-Static-Bridge-Route: static alle risposte statiche.
- Dalla 2.2.1 le risposte statiche inviano le intestazioni di sicurezza che la
  home renderizzata inviava all'ultimo export completo (X-Frame-Options,
  X-Content-Type-Options, Referrer-Policy, Permissions-Policy,
  Strict-Transport-Security, Content-Security-Policy senza nonce). Su Apache
  servono mod_headers e `Header always setifempty`, che non duplica quelle
  impostate con `Header always` dalla configurazione del server; con un
  `Header set` senza `always` nel virtual host la stessa intestazione può
  comparire due volte con lo stesso valore. La Diagnostica segnala quelle che
  mancano sulla home pubblica.
- Verificare che /wp-admin/, wp-login.php, REST, feed, sitemap, URL con query,
  richieste POST e percorsi esclusi continuino a raggiungere WordPress.
- Controllare che l'accesso diretto ai file delle release/manifest sia negato
  secondo le regole installate.
- Disattivare il plugin e verificare che le pagine tornino a WordPress sullo
  stesso dominio. Non cancellare i file durante il test.

## La scheda Server (dalla 2.3.0)

Static Bridge → Server controlla in pochi secondi cosa offre il server e cosa
conviene attivare. Il controllo **legge soltanto**: non scrive nel server, nel
database (a parte il proprio esito, nell'opzione `static_bridge_server_check`)
né nel sito. Ogni voce ha un esito (A posto, Consigliato, Da sistemare, Non
verificabile), una frase e, se serve, a chi rivolgersi.

| Voce | Come si controlla |
|---|---|
| Server web | `SERVER_SOFTWARE` (Apache, LiteSpeed, Nginx); i moduli Apache solo se PHP gira come modulo di Apache |
| Cache dell'hosting | due richieste anonime alla home: `cf-cache-status`, `x-aruba-cache`, `x-litespeed-cache`, Varnish, `x-cache` e simili, `Age` |
| Plugin di cache delle pagine | plugin attivi noti (WP Rocket, LiteSpeed Cache, W3 Total Cache…) e `advanced-cache.php` con `WP_CACHE`; i plugin che svuotano soltanto la cache dell'hosting (Aruba HiSpeed Cache, Nginx Helper, Proxy Cache Purge) vanno bene |
| Compressione | home, `wp-includes/css/dashicons.min.css` e `wp-includes/js/jquery/jquery.min.js` chiesti con `Accept-Encoding: br, gzip` |
| Cache del browser | gli stessi file: CSS e JS dovrebbero restare almeno 7 giorni; le pagine al massimo 10 minuti (`max-age`; `s-maxage` vale solo per la CDN) |
| PHP | versione, OPcache, `memory_limit` |
| Object cache | `object-cache.php` e di chi è, estensioni `redis`/`memcached`, prova di connessione (0,2 s) alle costanti `WP_REDIS_*` o agli indirizzi soliti; le credenziali servono a connettersi e non vengono mai mostrate |
| HTTPS | schema dell'indirizzo, Strict-Transport-Security e intestazioni di sicurezza di base |

Le richieste partono dal processo PHP verso l'indirizzo pubblico del sito, senza
cookie e senza intestazioni che scavalcano le cache, quindi una cache o una CDN
davanti al sito rispondono come rispondono ai visitatori. Se il sito non può
chiamare sé stesso (DNS o firewall), le voci che usano HTTP risultano «Non
verificabile».

### Cosa può fare Static Bridge

Sotto i controlli, la scheda ha tre interventi. Ognuno si attiva e si toglie da
lì e si toglie anche disattivando il plugin; la disinstallazione toglie quello
che resta. Dopo ogni modifica i controlli ripartono da soli.

**Compressione e cache dei file (Apache, LiteSpeed).** Un blocco proprio nel
`.htaccess`, in cima al file, fra `# BEGIN StaticBridge-Server` e
`# END StaticBridge-Server` (marcatori che non hanno in comune un prefisso con i
blocchi di routing, quindi la riscrittura delle regole non lo tocca):

- `AddOutputFilterByType DEFLATE` per HTML, CSS, JavaScript, JSON, XML, feed e
  SVG, dentro `<IfModule mod_deflate.c>` e `<IfModule mod_filter.c>`;
- `ExpiresByType` dentro `<IfModule mod_expires.c>`: 30 giorni per CSS,
  JavaScript e immagini, un anno per i font. Nessuna regola per `text/html` e
  nessun `ExpiresDefault`: la cache delle pagine resta quella di Static Bridge
  (Impostazioni → Cache-Control delle pagine statiche).

Senza i moduli il blocco non fa nulla e non rompe nulla. Se il server non
accetta queste direttive nel `.htaccess` (per esempio `AllowOverride` senza
`Indexes`) risponde 500 su tutto il sito: subito dopo la scrittura Static Bridge
chiede la home e un CSS, e con un 500 toglie il blocco e lo dice.

**Le stesse regole per Nginx.** La scheda mostra due pezzi da copiare: `gzip`,
`gzip_types` e una `map` sul `Content-Type` nel blocco `http`, `expires
$static_bridge_expires;` nel blocco `server` del sito. Verificare con `nginx -t`
e ricaricare. Static Bridge non modifica la configurazione di Nginx.

**Object cache (Redis o Memcached).** Il pulsante compare solo se PHP ha
l'estensione e un servizio risponde (costanti `WP_REDIS_HOST`, `WP_REDIS_PORT`,
`WP_REDIS_PATH`, `WP_REDIS_PASSWORD`, `WP_REDIS_DATABASE` se l'hosting le
definisce, altrimenti i socket e le porte soliti), se non c'è già un
`object-cache.php` di un altro plugin o dell'hosting e se il sito non è
multisite. Static Bridge scrive allora il suo `wp-content/object-cache.php`:

- l'indirizzo del servizio è scritto nel file; la password no, resta in
  `wp-config.php` e il drop-in legge la costante;
- le chiavi hanno un prefisso del sito e dell'installazione: due siti sullo
  stesso Redis non si mescolano, e una nuova installazione non legge valori
  vecchi; lo svuotamento alza un numero di versione e non usa mai `FLUSHALL`
  o `flush_all`, che svuoterebbero anche gli altri siti;
- ogni chiave scade al massimo dopo un giorno, così le chiavi lasciate da uno
  svuotamento se ne vanno da sole;
- appena scritto, una richiesta senza cookie al sito controlla che il drop-in
  si carichi e sia collegato; altrimenti il file viene tolto;
- se il servizio smette di rispondere (timeout di 0,5 s), la richiesta continua
  con la cache in memoria e i transient tornano nel database come senza
  drop-in; per 60 secondi le richieste seguenti non riprovano
  (`wp-content/static-bridge/.object-cache-down`);
- se PHP perde l'estensione, su multisite, o con
  `define('STATIC_BRIDGE_OBJECT_CACHE_DISABLED', true);` in `wp-config.php`, il
  drop-in non definisce nulla e WordPress usa la sua cache di sempre.

Per toglierlo a mano basta cancellare `wp-content/object-cache.php` (se nelle
prime righe c'è `Static-Bridge-Object-Cache-Dropin`).

### Aruba e HiSpeed Cache

HiSpeed Cache di Aruba conserva l'HTML: dopo una pubblicazione i visitatori
vedrebbero la versione precedente finché la cache non si svuota. Se il plugin
**Aruba HiSpeed Cache** è attivo, Static Bridge gli chiede di svuotare la cache
negli stessi momenti in cui svuota Cloudflare: dopo un export completo tutto, dopo
l'aggiornamento di una pagina quella pagina. Senza il plugin la scheda consiglia
di attivarlo, oppure di disattivare HiSpeed Cache dal pannello Aruba. Questo
collegamento non è ancora stato provato su un hosting Aruba vero.

## Cloudflare e altre CDN (dalla 2.0.17)

Tutte le opzioni sono in **Impostazioni → Cloudflare e CDN** e sono disattivate
per impostazione predefinita: senza modifiche il plugin si comporta come la
2.0.15.

**Senza regole di cache Cloudflare non conserva l'HTML** (lo considera
dinamico) e le pagine statiche arrivano sempre dall'origine: non serve purge.
Due problemi restano anche così:

1. **Rendering interno.** Export, push e scoperta delle sitemap richiedono le
   pagine a `home_url`, quindi passano dal DNS pubblico e da Cloudflare. WAF,
   Bot Fight Mode o una verifica (challenge) possono bloccarle; Rocket Loader,
   Email Obfuscation e script iniettati finiscono nell'HTML salvato (e vengono
   rielaborati una seconda volta all'edge). Soluzione: **Indirizzo di origine
   per il rendering** — un IP letterale (`127.0.0.1` se il server web ascolta
   in loopback, oppure l'IP dell'origine; porta facoltativa; IPv6 tra
   parentesi quadre). Il plugin si collega direttamente a quell'indirizzo con
   cURL `CONNECT_TO`: URL, header Host, SNI e verifica del certificato restano
   quelli del dominio, i controlli same-site sono invariati e nomi host, URL,
   0.0.0.0/8, link-local (169.254/16, metadati cloud), multicast e riservati
   sono rifiutati. Se l'origine usa un certificato **Cloudflare Origin CA**
   (non riconosciuto dai client comuni) definire in `wp-config.php`
   `STATIC_BRIDGE_RENDER_ORIGIN_CA` con il percorso del file della CA: la
   verifica TLS resta attiva. Se cURL non è disponibile, se WordPress usa un
   proxy (`WP_PROXY_HOST`) o se la richiesta non passa dal trasporto cURL, il
   rendering viene rifiutato invece di ricadere sul DNS pubblico.
   Le verifiche della Diagnostica che descrivono ciò che riceve un visitatore
   continuano invece a passare dal percorso pubblico.
2. **Verifiche Cloudflare.** Una risposta con `cf-mitigated` (tipicamente un
   403 di challenge) non è più trattata come un 403 atteso: viene ritentata e,
   se persiste, la route conserva la copia precedente invece di sparire dalla
   release (con Strict avrebbe risposto 404).

**Se l'HTML viene messo in cache all'edge** (Cache Rules, APO, “Cache
Everything”) il purge diventa obbligatorio:

- **Regola di cache consigliata.** Impostazioni mostra un'espressione pronta
  per *Caching → Cache Rules* che riproduce la guardia del routing: `http.host`
  del sito, solo GET/HEAD, query string vuota, percorso che termina con `/`,
  nessun cookie `wordpress_logged_in_`, `comment_author_`, `wp-postpass_` o
  WooCommerce, nessun `/wp-admin/`, `/wp-json/`, `/wp-content/`,
  `/wp-includes/`, `/feed/` né percorso dinamico configurato (confronto su
  `lower()`). Azione: *Eligible for cache*; Edge TTL *Use cache-control header
  if present, bypass cache if not*; Browser TTL *Respect origin*. Va
  rigenerata e reincollata dopo ogni modifica ai percorsi dinamici o ai moduli.
- **Cache-Control delle pagine statiche** (facoltativo, es.
  `public, max-age=0, s-maxage=600`): il blocco Nginx e il `.htaccess` inviano
  l'header solo sulle risposte servite dalla release; i 404 Strict hanno
  sempre `no-store, max-age=0`, anche senza questa impostazione (2.2.0). Il valore accetta solo direttive standard (`public`,
  `no-cache`, `no-store`, `no-transform`, `must-revalidate`,
  `proxy-revalidate`, `immutable`, `max-age`, `s-maxage`,
  `stale-while-revalidate`, `stale-if-error`). Su Apache serve `mod_headers`
  (le regole sono in `<IfModule mod_headers.c>`); su Nginx ricopiare il blocco
  e ricaricare. Con il frammento header SSI le pagine restano `no-store`.
- **Svuotamento automatico.** Credenziali solo in `wp-config.php`, mai nel
  database:

      define('STATIC_BRIDGE_CLOUDFLARE_ZONE_ID', '<zone id di 32 caratteri>');
      define('STATIC_BRIDGE_CLOUDFLARE_API_TOKEN', '<token>');

  Il token deve avere il solo permesso *Zone → Cache Purge* sulla zona del
  sito. Con l'opzione attiva: dopo push, aggiornamenti incrementali, rimozioni
  e redirect vengono svuotate le URL cambiate (gruppi da 30); dopo un export
  completo, un header SSI aggiornato, un cambio di routing o Cache-Control e la
  disattivazione l'intera zona. Lo svuotamento gira in una richiesta cron
  separata (al massimo una chiamata in coda al worker o al push), viene
  ritentato fino a 4 volte e ogni errore è un avviso in Attività: la
  pubblicazione non viene mai bloccata. Il pulsante *Svuota ora la cache
  Cloudflare* svuota l'intera zona a richiesta.

La Diagnostica aggiunge due controlli: *Cloudflare davanti al sito* (cf-ray,
cf-cache-status, challenge con `cf-mitigated`, origine e purge configurati,
ultimo purge fallito) e *Markup Cloudflare nelle copie salvate* (`/cdn-cgi/`,
Rocket Loader, Email Obfuscation, beacon nell'HTML della release attiva).

Verificato solo con un edge e un'API Cloudflare simulati in locale: parser delle espressioni, APO, Cache
Reserve, Origin CA e comportamento reale di WAF/Bot Fight Mode non sono stati
provati su una zona vera.

## Nota LocalWP

LocalWP può rigenerare parti della configurazione Nginx. Seguire il percorso e
le istruzioni della specifica installazione, fare backup del template/file
prima di modificarlo e controllare che le modifiche sopravvivano a stop/start
del sito. Non copiare un blocco di un altro progetto: usare quello corrente
generato dalla GUI del sito di prova.
