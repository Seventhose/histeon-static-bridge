# Modello di sicurezza

## Obiettivo e confine di fiducia

Static Bridge serve solo output pubblico renderizzato in HTML. WordPress e il
web server restano componenti fidati: il server decide il routing; WordPress
genera i documenti; il plugin conserva gli artefatti sotto wp-content. Le
richieste amministrative e le funzionalità che richiedono PHP devono continuare
a passare da WordPress.

Non usare l'export per contenuti privati, personalizzati per account, protetti
da password o contenenti dati riservati. Un file statico non applica i
controlli di autorizzazione che WordPress applicherebbe a ogni visita.

Quando Apache o Nginx serve direttamente il file HTML, il bootstrap di PHP e
WordPress non viene eseguito. Di conseguenza non eseguono neppure gli hook, i
controlli di accesso o i firewall che operano solo dentro WordPress/PHP (la
copertura frontend dei plugin firewall di WordPress non va data per scontata).
Un WAF del web server, del proxy o della CDN può continuare a intervenire a
monte, ma va verificato sul server reale. Pubblicare solo contenuti davvero
pubblici e assicurarsi che le regole esterne proteggano anche le richieste
statiche.

## Controlli implementati

- Impostazioni, regole del server e azioni amministrative richiedono la
  capability `manage_options` e un nonce. Dalla 2.5.0 i filtri
  `static_bridge_export_capability` e `static_bridge_can_push_post` possono
  lasciare export e push anche ad altri ruoli; impostazioni e regole del server
  restano all'amministratore.
- Il preflight controlla PHP/WordPress dichiarati, funzioni richieste, memoria,
  disponibilità/scrivibilità dei percorsi e condizioni note prima degli effetti
  collaterali di attivazione.
- Le richieste HTTP di acquisizione restano sull'origine configurata, rifiutano
  credenziali/protocolli e destinazioni esterne, non seguono redirect
  automaticamente e limitano dimensioni e tempo di risposta.
- Le destinazioni dei redirect vengono ispezionate e accodate solo dopo
  normalizzazione same-origin. URL dinamici o non sicuri vengono saltati.
- Le risposte pagina HTTP 401/403/404/410 vengono segnalate e saltate per URL;
  possono produrre una release con quelle pagine mancanti, quindi l'elenco va
  verificato prima del go-live. Gli errori 5xx transitori e di trasporto hanno
  retry limitati; all'esaurimento la singola pagina viene saltata e segnalata
  (quando possibile resta la sua copia precedente), mentre i guasti di coda,
  disco, database o pubblicazione fermano il job senza pubblicare. La ripresa
  admin con capability e nonce convalida job, età, percorso, marker e
  corrispondenza dell'URL prima di schedulare il worker. Nessuna impostazione
  WAF viene cambiata dal plugin.
- La mappatura URL-file rifiuta traversal e percorsi non sicuri; le directory e
  i symlink vengono verificati rispetto alla radice consentita.
- I file temporanei vengono rinominati dopo la scrittura. Una release nuova non
  sostituisce current prima del completamento; il puntatore è verificato contro
  releases/.
- Le regole generate separano wp-admin, login, REST, feed, query e metodi non
  GET/HEAD dal routing statico.
- Nginx e Apache negano l'accesso diretto a tutta la directory Static Bridge.
  Ogni regola Apache che serve un file ripete tutte le condizioni di guardia
  (fino alla 2.0.13 solo la home era protetta; corretto nella 2.0.14). Le
  richieste e i risultati vanno comunque verificati sul server concreto.
- Esportazioni e diagnostica sono a batch; il cap di URL è 100.000, la pagina
  HTML 5 MiB e la risposta HTTP generica 10 MiB. Export completo e incrementale
  conservano una riserva disco predefinita di 64 MiB oltre ai byte da scrivere
  e falliscono senza pubblicare se lo spazio non è disponibile o verificabile.
  Il writer verifica i byte scritti prima di rinominare i file temporanei; il
  writer `.htaccess` rifiuta il target se è un collegamento simbolico.
- Su Apache, dalla 2.0.20, il sito viene servito da una copia della release
  fatta di hard link (`served/<id>`), che non viene mai modificata: ogni
  pubblicazione ne crea una nuova e le regole `.htaccess` la nominano
  esplicitamente. Questo chiude la corsa di Apache 2.4 che, mentre un file
  veniva sostituito, poteva servire una pagina tagliata o allungata con un
  `Content-Length` coerente (vedere [SERVER-SETUP](SERVER-SETUP.md)).
- I parametri del rendering interno (`static_bridge_bypass`,
  `static_bridge_render`) vengono rimossi dall'HTML salvato in tutte le forme
  in cui WordPress li scrive, comprese le entità `&#038;`/`&#38;`/`&#x26;`.
  Fino alla 2.0.20 il link "Annulla risposta" dei commenti conservava il codice
  monouso, già scaduto ma non destinato al pubblico (corretto nella 2.0.21).
  Dalla 2.2.5 anche quando l'URL corrente è codificato con i percent-escape
  dentro un altro URL, una o due volte, come nei link di condivisione
  (`sharer.php?u=…%3Fstatic_bridge_bypass%3D1%26…`); il token rimasto in
  qualunque altra forma viene comunque cancellato.
  `tests/e2e/html-leak-scan.php` controlla in ogni gate di rilascio che nessuna
  pagina pubblicata contenga barra di amministrazione, classi o link da utente
  connesso, dati utente, cookie di autenticazione o parametri interni.
- Dalla 2.4.4 una pagina che mostra un errore di PHP o del database non entra
  nella copia statica: resta online la copia precedente, o la pagina resta a
  WordPress.
- La disinstallazione (dalla 2.0.18) rimuove regole, eventi, tabelle, opzioni e
  la cartella statica senza seguire collegamenti simbolici; l'attivazione su
  multisito è rifiutata prima di qualsiasi modifica.
- Il codice è controllato con Plugin Check e PHPCS (WordPress Coding Standards,
  sniff di sicurezza); i problemi reali segnalati sono stati corretti nella
  2.0.19.

## Limiti importanti

Il preflight non può simulare il bootstrap completo né impedire un fatal
provocato da un altro plugin prima che il codice di Static Bridge venga
eseguito. Non equivale a un rollback transazionale dell'attivazione.

WordPress valida i requisiti e carica il plugin prima di eseguire l'hook di
attivazione, e aggiorna `active_plugins` solo dopo che l'hook è terminato. Un
fatal durante il caricamento o l'hook normalmente interrompe quindi
l'attivazione, ma non annulla eventuali file o opzioni già scritti dall'hook.
Il controllo per `unexpected_output` avviene invece dopo l'aggiornamento di
`active_plugins`: un warning PHP non fatale che produca output può mostrare un
errore in bacheca lasciando comunque il plugin registrato come attivo. Il
preflight Static Bridge non converte genericamente warning in errori e non
promette un'attivazione transazionale. Con un limite di memoria basso il
preflight può bloccare l'attivazione perché resta troppo poca memoria: va
verificato il budget di memoria effettivo del sito.

La suite CLI (`tests/security/run.php`) usa stub WordPress e non è un
penetration test. Le prove end-to-end in `tests/e2e/` girano su stack di prova
usa-e-getta (WordPress, PHP-FPM, Nginx, Apache, OpenLiteSpeed) con i plugin
installati per la singola prova; restano fuori hosting reale, TLS pubblico,
credenziali, WAF, CDN reali, pagamenti e invio reale di email. La Diagnostica
delle pagine form usa GET e non invia dati.

Il trasporto parallelo conserva TLS, proxy e hook cURL WordPress; quando rileva
un hook terzo `http_api_curl`, usa il percorso HTTP sequenziale compatibile.
Questi controlli riducono i rischi noti ma non certificano server, WAF, cron o
hosting pubblico: la messa in produzione del singolo sito deve verificarli nel
relativo staging.

Le regole Apache e Nginx non sono perfettamente identiche (per esempio Apache
risponde allo Strict 404 con la propria pagina d'errore). I cookie riconosciuti
sono gli stessi (login, commentatore, carrello e sessione WooCommerce, più
quelli scelti in «Cookie che mandano a WP»); provare comunque utenti
autenticati, commentatori, carrello e sessioni sul server effettivo.
Un cache plugin o una cache intermedia può rispondere prima delle regole
attese: il comportamento non è certificato genericamente.

## Endpoint pubblico dei nonce Fluent Forms (dalla 2.0.16)

È l'unica azione AJAX registrata anche per i visitatori non autenticati
(`wp_ajax_nopriv_static_bridge_public_form_nonces`; la suite verifica che non ce
ne siano altre). Non legge alcun input della richiesta: restituisce soltanto
`wp_create_nonce()` di tre azioni fisse di Fluent Forms
(`fluentform_generate_token_nonce`, `fluentform-submit-form`,
`fluentform_file_delete`) e la loro durata minima. Un nonce WordPress dipende da
utente, token di sessione, azione e intervallo di tempo; per un visitatore non
autenticato utente e sessione sono vuoti, quindi il valore è lo stesso che ogni
pagina dinamica con quel modulo stampa a qualunque visitatore anonimo (salvo
plugin che filtrano `nonce_user_logged_out`, caso in cui il valore resta legato
alla stessa richiesta che lo verificherà). Per un utente autenticato restituisce
i suoi nonce, come una pagina dinamica, leggibili solo dalla stessa origine.
Nessun dato nuovo è esposto. Risposta `no-store, private` (lo script la chiama in POST); risponde 404
se Fluent Forms non è attivo o se il rinnovo è disattivato nella scheda Moduli.
Il controllo anti-spam resta di Fluent Forms: il token vero è generato e
verificato da Fluent Forms come su una pagina dinamica.

## SSI (facoltativo, spento di serie)

Con SSI le pagine includono un frammento comune dell'intestazione. Il blocco
Nginx con `internal` blocca la richiesta HTTP diretta al frammento, ma non
impedirebbe a una direttiva SSI già presente nella pagina di includerlo: per
questo Static Bridge rifiuta, chiudendo in sicurezza, le direttive SSI nelle
route da pubblicare, negli header estratti e nei frammenti già esistenti, nelle
forme compatte e con spazi. Genera soltanto include con namespace, ID e hash
convalidati, dopo avere identificato un unico header sicuro. Il blocco Nginx
limita SSI alle route statiche, usa `ssi off` sul namespace privato dei
frammenti, `internal` e `try_files`, e imposta `ssi_silent_errors off`,
`etag off` e `Cache-Control: no-store`. Non viene emessa `ssi_exec off`: Nginx
non supporta una direttiva con quel nome; il controllo delle sorgenti è la
difesa applicativa. Se un frammento manca, la pagina resta servita senza
l'intestazione e senza mostrare il percorso privato: il caso richiede
diagnosi e riparazione (una nuova generazione).

## Raccomandazioni di esercizio

- Provare su staging con configurazione e plugin il più possibile simili alla
  produzione.
- Non attivare strict finché una generazione completa e il routing non sono
  verificati. Lasciare il fallback WordPress durante il collaudo.
- Usare TLS e aggiornamenti supportati di WordPress/PHP/tema/plugin; proteggere
  wp-admin e le credenziali con i controlli dell'hosting.
- Mantenere backup coerenti di database, release e configurazione server.
- Verificare l'accesso HTTP diretto ai manifest e alle release, gli endpoint
  amministrativi, i form e il comportamento dopo la disattivazione.
- Non interpretare un esito PASS della suite locale come autorizzazione al
  go-live.
- Verificare separatamente la copertura WAF per le risposte servite come file:
  i plugin WordPress/PHP non possono filtrare una richiesta che non avvia PHP.
  Login, amministrazione, moduli e REST continuano invece a passare da PHP e
  restano coperti dai plugin. Per le pagine statiche servono regole a monte:
  il WAF della CDN o del server, oppure un firewall in `.htaccess` come 8G.
  Le regole 8G vanno messe **sopra** il blocco Static Bridge: quel blocco
  serve il file e chiude l'elaborazione delle regole, quindi le regole
  successive non vedono le richieste statiche. Verificare che non blocchino il
  rendering interno (query `static_bridge_bypass=1&static_bridge_render=<48
  caratteri esadecimali>`, user agent `Static-Bridge/<versione>`).
- Usare sempre l'ultima release: le versioni fino alla 2.0.13 hanno regole
  `.htaccess` difettose su Apache, corrette nella 2.0.14.
- Cloudflare (dalla 2.0.17, opzionale): il token API sta solo in `wp-config.php`
  (`STATIC_BRIDGE_CLOUDFLARE_API_TOKEN`), non viene salvato in opzioni, non è
  mostrato in Impostazioni e viene oscurato nei messaggi di errore; usare un
  token con il solo permesso Cache Purge sulla zona del sito. L'indirizzo di
  origine per il rendering accetta solo un IP letterale (niente nomi host o
  URL, niente 0.0.0.0/8, link-local/metadati, multicast o riservati), cambia
  solo la destinazione TCP di una richiesta same-site e mantiene Host, SNI e
  verifica TLS; una risposta ottenuta senza la mappatura viene scartata.
  Il valore Cache-Control finisce nelle regole del server ed è quindi limitato
  a un elenco di direttive standard.

Le prove end-to-end e i gate di rilascio sono descritti in
[`tests/e2e/README.md`](../tests/e2e/README.md).
