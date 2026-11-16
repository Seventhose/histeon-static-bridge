=== Static Bridge ===
Tags: static, html, performance, cache, cloudflare
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 2.5.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Pagine pubbliche WordPress servite come HTML statico sullo stesso dominio; amministrazione, login e moduli restano a WordPress.

== Description ==

Static Bridge genera una copia HTML delle pagine pubbliche e la fa consegnare direttamente dal web server ai visitatori anonimi, senza avviare PHP né interrogare il database. Tutto ciò che è dinamico continua ad andare a WordPress sullo stesso dominio: `wp-admin`, login, REST, AJAX, anteprime, URL con parametri, richieste POST, utenti autenticati, feed, sitemap e percorsi esclusi.

* **Release atomiche.** Ogni export completo viene scritto in una nuova cartella; il sito passa alla nuova versione con un solo scambio di puntatore. I visitatori vedono la versione precedente o quella nuova, mai una via di mezzo. Su Apache le regole puntano a una copia immutabile della release (hard link), rinnovata a ogni pubblicazione: un file servito non cambia mai mentre Apache lo legge.
* **Prova di freschezza.** Una pagina entra nella copia solo se la risposta contiene un codice monouso che soltanto WordPress può produrre: una cache o un CDN che restituisce una versione vecchia viene rifiutato.
* **Errori circoscritti.** Una pagina che non si riesce a generare viene riprovata, poi saltata e segnalata; quando possibile resta online la sua copia precedente. Guasti di disco, database o pubblicazione fermano invece il job e lasciano attiva la release in uso.
* **Aggiornamenti mirati.** Il pulsante nell'editor rigenera subito la singola pagina; salvando un contenuto vengono accodate la pagina e le route collegate (home, archivi), elaborate da WP-Cron.
* **Fluent Forms.** Le pagine con moduli possono restare statiche: nonce e token anti-spam vengono rinnovati nel browser al caricamento.
* **WooCommerce.** Catalogo statico che si aggiorna da solo con prezzi, saldi e scorte (anche da ordini, REST, importazioni e saldi programmati); carrello, checkout, account, pagamenti e Store API restano a WordPress; il nonce della Store API delle pagine statiche viene rinnovato nel browser.
* **Cloudflare (facoltativo).** Rendering diretto all'origine, svuotamento automatico della cache dopo ogni pubblicazione e regola di cache consigliata.
* **Conservazione delle release (facoltativa).** Elimina le release precedenti oltre il numero scelto, senza toccare quella in uso.

Il routing statico dipende dal web server: su Apache e LiteSpeed il plugin scrive le regole in `.htaccess` (OpenLiteSpeed le legge al riavvio, e la Diagnostica dice quando serve); su Nginx va copiato nella configurazione del sito il blocco mostrato in Impostazioni.

== Installation ==

1. Fai un backup completo del sito e della configurazione del server.
2. Carica lo ZIP da *Plugin → Aggiungi nuovo → Carica plugin* e attivalo. L'attivazione non cambia il frontend: finché non esiste una release, il sito è servito da WordPress come prima.
3. **Nginx:** apri *Static Bridge → Impostazioni*, copia il blocco Nginx al posto della `location /` del sito (mantieni la location PHP e `@wperror`), verifica con `nginx -t` e ricarica Nginx. **Apache:** serve `mod_rewrite` e un `.htaccess` scrivibile; le regole vengono gestite dal plugin.
4. Da *Genera* avvia il primo export completo e controlla l'esito in *Attività* e *Diagnostica*.
5. Configura un cron di sistema che richiami `wp-cron.php`: con le pagine servite in statico i visitatori non avviano più WP-Cron.
6. Lascia **Strict** disattivato finché non hai verificato il sito.

Per Cloudflare definisci in `wp-config.php` le costanti `STATIC_BRIDGE_CLOUDFLARE_ZONE_ID` e `STATIC_BRIDGE_CLOUDFLARE_API_TOKEN` (token con il solo permesso *Cache Purge*), poi attiva le opzioni in *Impostazioni → Cloudflare e CDN*.

Per un sito di prova protetto da Basic Auth del web server (Apache `AuthType Basic`, Nginx `auth_basic`) definisci in `wp-config.php` il nome utente e la password, per esempio `define( 'STATIC_BRIDGE_BASIC_AUTH_USER', 'utente-di-esempio' );` e `define( 'STATIC_BRIDGE_BASIC_AUTH_PASSWORD', 'password-di-esempio' );`: Static Bridge li manda solo nelle richieste al sito stesso e la *Diagnostica* dice se funzionano. La copia statica resta protetta dalla stessa Basic Auth.

== Frequently Asked Questions ==

= Cosa succede a una pagina che non è nella copia statica? =

Con l'impostazione predefinita la serve WordPress, come senza il plugin. Con **Strict** attivo risponde il server con un 404, senza avviare WordPress: i contenuti nuovi diventano visibili solo dopo un export o un push. Strict non nasconde nulla: una richiesta con parametri nell'URL o con il cookie di login va comunque a WordPress, che risponde come sempre.

= Disattivare il plugin è sicuro? =

Sì. La disattivazione spegne il routing statico (il sito torna subito a WordPress) e conserva impostazioni e release, così la riattivazione riprende da dove eri. Se mentre era spento un contenuto è diventato bozza, privato, protetto da password o è finito nel cestino, alla riattivazione la sua copia statica esce dalla release prima che torni online. Se un contenuto pubblico è stato eliminato o ha cambiato indirizzo, la sua copia non si può ritrovare: il sito resta servito da WordPress finché non avvii un export completo.

= Cosa fa l'eliminazione del plugin? =

Eliminando il plugin da *Plugin → Elimina* vengono rimossi impostazioni, code, tabelle, eventi pianificati, i blocchi Static Bridge in `.htaccess` e la cartella `wp-content/static-bridge` con tutte le release. La cartella viene eliminata solo se è esattamente `wp-content/static-bridge` e non un collegamento simbolico; i collegamenti al suo interno vengono rimossi senza toccare i file a cui puntano. Su Nginx rimuovi a mano il blocco dalla configurazione del server: senza la cartella del plugin le richieste ricadono comunque su WordPress.

= Funziona su WordPress multisito? =

No. I siti di una rete condividono document root, regole del server e cartella delle release: l'attivazione su multisito viene rifiutata prima di modificare qualsiasi cosa.

= Il plugin è pronto per qualunque hosting? =

È stato verificato su WordPress 6.9 e 7.1 con PHP 8.4, Nginx 1.26 e Apache 2.4 con PHP-FPM e OpenLiteSpeed 1.9, in ambienti di prova isolati. Ogni hosting, cache intermedia, CDN e insieme di plugin va provato su uno staging del sito prima della produzione.

== Changelog ==

= 2.5.0 =
* Nuovo: pulsante «Aggiorna la copia statica» nella barra di amministrazione e casella «Non salvare questa pagina in statico» nell'editor. I filtri `static_bridge_export_capability` e `static_bridge_can_push_post` lasciano export e push anche a ruoli diversi dall'amministratore; impostazioni e regole del server restano all'amministratore.
* Nuovo: «Attesa dopo l'ultima modifica» (0–120 minuti, 0 di serie) prima degli aggiornamenti automatici, con un tetto al doppio dell'attesa. Il push manuale resta immediato.
* Nuovo: email all'amministratore quando un export o un aggiornamento automatico fallisce, una sola volta finché non torna a funzionare (spenta di serie). Azione pubblica `static_bridge_export_finished` a fine export.
* Nuovo: export completo pianificato, giornaliero o settimanale (spento di serie). Un export in corso o in pausa fa saltare il giro.
* Nuovo: comandi WP-CLI `wp static-bridge status`, `wp static-bridge export` e `wp static-bridge push <id>`.
* Nuovo: con Strict acceso, dopo ogni pubblicazione un controllo dei link elenca le rotte interne linkate che risponderebbero 404. È solo un rapporto: non cambia la copia né le regole. Legge anche i link dei frammenti dell'intestazione inclusi con SSI.
* Nuovo: «Date relative aggiornate nel browser» (spenta di serie): un testo come «3 ore fa» resta giusto anche nella copia statica.
* Nuovo: sito di prova dietro Basic Auth. Nome utente e password vanno in due costanti di `wp-config.php`; Static Bridge li manda solo nelle richieste al sito stesso, anche a WP-Cron, e la Diagnostica dice se funzionano.
* Nuovo: «Versione Markdown delle pagine» (spenta di serie): la pagina pubblica con `?format=markdown` risponde in Markdown, generato da WP a richiesta. Nella copia statica restano solo file HTML.
* Nuovo: «Ignora i parametri di tracciamento» (spenta di serie): la copia si serve anche ai link con `utm_*`, `fbclid`, `gclid` e simili, come quelli dei social e delle newsletter. L'elenco si cambia con il filtro `static_bridge_tracking_params`.
* Nuovo: «Cookie che mandano a WP» (vuoto di serie): chi ha uno dei cookie elencati riceve la pagina da WP, come chi ha fatto l'accesso.
* Nuovo: un commento approvato aggiorna la copia del suo articolo, e la modifica di un contenuto aggiorna anche il precedente e il successivo.
* Nuovo: i menu di Aspetto → Menu e i widget aggiornano tutte le pagine, come già i menu a blocchi e il Customizer.
* Nuovo: dopo l'aggiornamento di WP, del tema o di un plugin, o un cambio di tema o di plugin, un avviso propone «Rigenera» o «Ignora». «Rigenera dopo gli aggiornamenti» (spenta di serie) fa partire da sola l'export completo.
* Nuovo: la scheda Server e «Salute del sito» dicono se WP-Cron non parte o se un lavoro di Static Bridge è in ritardo.
* Nuovo: «Pagine da aggiornare a ogni pubblicazione» e «Pagine da aggiornare ogni notte» (vuoti di serie), per le pagine con elenchi che Static Bridge non riconosce da solo o che dipendono dalla data.
* Nuovo: azione pubblica `static_bridge_route_updated` per ogni pagina aggiornata, per svuotare altre cache (Varnish, la cache dell'hosting).
* Nuovo: le regole Apache mandano `X-Static-Bridge-Route` come il blocco Nginx: dal browser si vede se una pagina viene dalla copia.
* Nuovo: la Diagnostica chiede la home come computer e come telefono e avvisa se l'HTML è diverso, perché la copia è una sola.
* Nuovo: una pagina salvata che chiede di non essere messa in cache o che contiene un nonce che scade si segnala in Attività. Che cosa si salva non cambia.
* Nuovo: con Strict, «Importa i redirect di…» porta nelle regole del server i redirect semplici di Redirection, Rank Math e Yoast SEO Premium (nessuno scelto di serie).
* Nuovo: «Non riscrivere le pagine identiche» (spenta di serie) negli aggiornamenti incrementali.
* Nuovo: «Rallenta l'export se il server è lento» (spenta di serie).
* Nuovo: su Nginx la Diagnostica dice se il blocco in uso sul server è ancora quello delle Impostazioni di adesso, redirect compresi. Il blocco manda l'intestazione `X-Static-Bridge-Rules` con la sua impronta: va ricopiato una volta.
* Corretto: un contenuto salvato mentre girava l'aggiornamento incrementale poteva non arrivare mai alla copia statica.
* Corretto: le date di wp-admin (scheda Server, Diagnostica, Versioni) sono nel fuso del sito, non in UTC.
* Corretto: attivare il plugin con il modulo Static Bridge di Histeon acceso non dà più un errore fatale: un messaggio chiede di spegnere prima l'altra copia.
* Corretto: il modello del drop-in della cache degli oggetti non compare più fra i plugin del sito.
* Corretto: su Nginx i redirect mandano come `Location` il percorso, con la query: dietro un proxy non portano più a un indirizzo con la porta interna. Chi ha un blocco con dei redirect lo deve ricopiare.
* Corretto: su Apache senza `mod_headers` le copie compresse `.gz` non arrivano più al browser senza `Content-Encoding`.
* Corretto: un `%` nella destinazione di un redirect su Apache non rompe più la regola.
* Corretto: Attività dice «Redirect importati» solo quando ne entra almeno uno.

= 2.4.4 =
* Sicurezza: una pagina che mostra un errore di PHP o del database (un avviso «Warning», «Notice» o «Deprecated» con il percorso di un file del server, un errore fatale, il riquadro di Xdebug, una query fallita con «WordPress database error») non entra più nella copia statica. Prima restava pubblicata a tutti i visitatori finché la pagina non si rigenerava. Ora resta online la copia precedente, o la pagina resta a WordPress, e l'Attività consiglia di spegnere la visualizzazione degli errori (WP_DEBUG_DISPLAY o display_errors). Un articolo che cita un errore dentro un blocco di codice non viene scambiato per un errore vero.

= 2.4.3 =
* Pagine statiche: un indirizzo del sito scritto nel testo della pagina resta completo, con il dominio. Prima la copia statica lo accorciava al solo percorso: per esempio «Indirizzo: https://example.test/articolo/» diventava «Indirizzo: /articolo/», e così anche il testo di una citazione da copiare. Restano completi anche il titolo della pagina, i campi di testo, i testi alternativi e i suggerimenti delle immagini, la meta description e i microdati. I collegamenti, le immagini, i fogli di stile e gli script continuano a usare il solo percorso, come prima.
* Complianz: un'estensione che scrive nel banner dei cookie o nelle pagine legali può dichiarare le sue opzioni con il filtro `static_bridge_complianz_options`; quando una di quelle cambia, le pagine statiche si rigenerano come per le opzioni di Complianz.

= 2.4.2 =
* Interfaccia: le icone dei pulsanti e delle schede sono centrate in verticale sul testo. Prima stavano circa un pixel più in basso, fino a tre sui telefoni nei pulsanti principali. Nessun'altra modifica.

= 2.4.1 =
* WooCommerce con un modulo Fluent Forms in ogni pagina (per esempio nel piè di pagina): le pagine non restano più dinamiche con «contiene nonce che Static Bridge non sa rinnovare (-1)». Quel nonce è `fluentFormVars.nonce`, che Fluent Forms stampa senza azione e non controlla all'invio; ora Static Bridge lo riconosce e lo rinnova nel browser con gli altri nonce di Fluent Forms. Lo stesso valore stampato altrove nella pagina tiene ancora la pagina a WordPress. Prima, con tutte le pagine escluse, l'export completo finiva con «Nessuna pagina HTML è stata salvata».
* Export: se nessuna pagina diventa statica, il messaggio finale dice quante pagine sono state elaborate e il motivo più frequente, perché la pagina Genera mostra solo gli ultimi avvisi.
* Export: un indirizzo che WordPress reindirizza a un'altra pagina del sito (un plugin o uno snippet che manda un indirizzo vecchio a quello nuovo) non è più contato fra le pagine escluse né elencato fra i problemi: la destinazione viene esportata e il manifest conta i reindirizzamenti seguiti.
* Push immediato e aggiornamenti incrementali: il lock del worker incrementale è atomico, come quello dell'export dalla 2.4.0. Prima, un push (anche via MCP) e il worker potevano lavorare insieme, e il push poteva fallire con «Impossibile salvare la transazione di sicurezza del rendering».
* Rendering: la transazione di sicurezza si rilegge sempre dal database sotto lock. Una lettura rimasta nella cache della richiesta, fatta mentre un altro rendering era in corso, impediva di salvare quella nuova.

= 2.4.0 =
* Nuova scheda **Versioni**: le versioni del sito statico salvate in `wp-content/static-bridge/releases`, una per blocco, con data, pagine, peso e stato (online, precedente, export in corso o in pausa, staging fallito o annullato). Ognuna si elimina con il suo pulsante, dopo una conferma; un pulsante elimina in blocco quelle che non servono. Quelle protette si vedono con il motivo e non si eliminano: la versione online, quella per il ripristino dopo un'importazione All-in-One, quella di un export in corso o in pausa, quelle usate dagli indici V2 o dalla verifica SSI.
* Sui siti nuovi la pulizia automatica è accesa di serie e tiene **una versione precedente**: dopo ogni pubblicazione riuscita si eliminano le altre e gli staging falliti o annullati. Chi aggiorna mantiene la sua scelta: con la pulizia spenta niente viene eliminato da solo, e Versioni propone di attivarla.
* Gli staging di un export fallito o annullato si eliminano dopo un'ora, non più dopo 24 ore: un export così non riprende mai.
* La conservazione delle release passa da Attività a Versioni.
* Export: il lock che impedisce a due richieste di lavorare insieme sullo stesso export ora è atomico. Prima, una richiesta della pagina e una richiesta di fondo arrivate nello stesso istante potevano prenderlo entrambe: l'export poteva fermarsi con «non è stato possibile aggiornare lo stato della coda» o saltare pagine sane.

= 2.3.0 =
* Nuova scheda **Server**: controlla server web, cache dell'hosting davanti al sito (Cloudflare, HiSpeed Cache di Aruba, LiteSpeed, Varnish e simili), plugin di cache delle pagine in conflitto, compressione, cache del browser, PHP e OPcache, Redis e Memcached, HTTPS e intestazioni di sicurezza, e dice per ogni voce cosa è a posto e cosa chiedere all'hosting. Il controllo legge soltanto. Finché il server non è stato controllato una volta, la bacheca e la pagina dei plugin lo propongono con un avviso.
* Dalla stessa scheda, a un clic: **compressione e cache dei file** in un blocco proprio del `.htaccess` (Apache, LiteSpeed; le pagine HTML restano fuori), tolto da solo se il server non lo accetta; gli stessi blocchi pronti da copiare per Nginx.
* **Object cache** su Redis o Memcached, se il server li offre: Static Bridge installa il suo `object-cache.php` solo se non ce n'è già un altro, lo prova con una richiesta vera e lo toglie se non funziona. Se il servizio smette di rispondere il sito continua senza cache, con i transient nel database. La password resta in `wp-config.php`.
* **HiSpeed Cache di Aruba**: con il plugin Aruba HiSpeed Cache attivo, Static Bridge svuota la cache di Aruba dopo ogni pubblicazione e ogni pagina aggiornata, come già fa con Cloudflare.
* Disattivando Static Bridge si tolgono anche il blocco del `.htaccess` e l'object cache di Static Bridge.
* **Cloudflare consigliato**: un pulsante in Impostazioni salva Cache-Control `public, max-age=0, s-maxage=600` e lo svuotamento automatico, e avvisa se mancano le credenziali in `wp-config.php`.
* **Pagine precompresse** (facoltative, spente di serie): accanto a ogni pagina statica un `index.html.gz` che Apache e Nginx consegnano già compresso; il `.gz` non è mai più vecchio della sua pagina. Su LiteSpeed restano spente: il server comprime da solo.
* **Export più robusto**: se un altro rendering è in corso (un push, un aggiornamento incrementale) l'export aspetta e riprova, invece di saltare le pagine e pubblicarne la copia vecchia con un errore.
* **WooCommerce**: un prezzo o un saldo che cambia rigenera anche i prodotti correlati (stesse categorie o tag, al massimo 50; filtro `static_bridge_woocommerce_related_limit`).
* **Object cache** anche su socket Unix di Redis e Memcached.
* **OpenLiteSpeed**, provato su un server vero: legge il `.htaccess` della radice solo all'avvio. Le regole servono la release corrente, così le pagine pubblicate si vedono subito; dopo il primo export e dopo un salvataggio che cambia le regole la Diagnostica e Attività dicono di riavviare il server. La cartella `wp-content/static-bridge` rifiuta gli accessi diretti da subito, con un suo `.htaccess`. Impostazioni mostra il contesto da aggiungere al virtual host per Cache-Control e intestazioni di sicurezza, che OpenLiteSpeed non legge dal `.htaccess`.
* Contiene anche gli aggiornamenti firmati della 2.2.8 e le correzioni dalla 2.2.9 alla 2.2.15.

= 2.2.15 =
* Lingua Bridge e altri plugin multilingua: i reindirizzamenti che WordPress fa per loro (il vecchio indirizzo di una pagina tradotta, una categoria dell'originale sotto la cartella di una lingua, la home di una lingua non ancora tradotta) arrivano con il filtro `static_bridge_redirects` e diventano regole del server: un 301 o 302 vero anche in modalità Strict, dove prima il visitatore trovava un 404. Su Apache entrano da soli nel `.htaccess`; su Nginx sono nel blocco da copiare. Al massimo 500, solo indirizzi del sito.
* I domini delle lingue (Lingua Bridge 1.7.1 e successive, filtro `static_bridge_hosts`): la copia statica è quella del sito principale e non viene più servita su quei domini, dove risponde WordPress nella lingua giusta. Prima `fr.example.com/` poteva ricevere la home statica italiana.


= 2.2.14 =
* Canonical, `hreflang` e le altre `link rel="alternate"`, shortlink, Open Graph (`og:url`, `og:image`…), Twitter e i dati strutturati JSON-LD restano con l'indirizzo completo nella copia statica. Prima diventavano relativi come i link del contenuto: WhatsApp, Facebook e Telegram non mostravano l'anteprima e Google riceveva canonical e `@id` relativi. Una release esportata su un altro dominio e importata con All-in-One WP Migration non viene attivata: WordPress serve il sito finché un export completo su quel dominio non la sostituisce.
* Etch: salvare selettori, fogli di stile globali, loop o impostazioni di Etch rigenera tutte le pagine statiche, come per Bricks. Prima il CSS nuovo restava invisibile ai visitatori fino all'export completo. Le impostazioni dell'assistente AI non rigenerano nulla.
* Errori 429 (e 503) durante l'export, per esempio da Cloudflare: Static Bridge rispetta `Retry-After`, aspetta di più a ogni tentativo e rallenta finché il server non si riprende, mette da parte la pagina e la riprova lentamente alla fine dell'export. Prima la pagina veniva omessa per sempre. Le pagine ancora rifiutate sono elencate in Genera con il pulsante «Riprova queste» e il consiglio di far passare l'export direttamente dall'origine.
* L'export completo va avanti anche se la scheda di wp-admin si chiude: richieste di fondo verso il sito stesso, autenticate da un codice casuale che cambia a ogni richiesta, con una sola catena alla volta (filtro `static_bridge_export_background_requests` per spegnerle). Un export fermo da più di cinque minuti è segnalato in bacheca.
* Genera: «Aggiorna tutte le pagine statiche» per i cambi che Static Bridge non vede (snippet, tema, plugin); la data dell'ultima pagina aggiornata; «Pagine dinamiche» dice il motivo (esclusione, WooCommerce, Fluent Forms, errori 429, parametri). In Attività ogni pagina aggiornata dopo un cambio condiviso dice perché.
* Endpoint `POST /wp-json/static-bridge/v1/refresh` (amministratori, per esempio con una password per le applicazioni) per gli script di deploy: `{"scope":"all"}` oppure `{"scope":"paths","paths":["/a/"]}`.
* Diagnostica: le altre cache di pagina (WP Super Cache, LiteSpeed, W3 Total Cache, WP Rocket, WP Fastest Cache, Cloudflare APO) con chi serve cosa; Static Bridge svuota la copia di WP Super Cache e LiteSpeed di ogni pagina che rigenera. Confronto di canonical, `og:image`, `twitter:image`, `hreflang` e JSON-LD fra la homepage statica e WordPress.
* I link `/cdn-cgi/` di Cloudflare (protezione delle email) non entrano più nel crawl e nelle URL con errore.

= 2.2.13 =
* Con WooCommerce attivo l'integrazione è accesa di serie, finché un amministratore non la spegne e salva. Anche spenta, Carrello, Checkout, Il mio account, `/wc-api/` e `/wc-auth/` restano sempre a WordPress: prima, con l'interruttore spento (il valore iniziale), finivano nella copia statica con nonce destinati a scadere.
* Cambiare l'immagine, il tipo di visualizzazione o l'ordine di una categoria di prodotti rigenera la categoria, il negozio e la home.
* WooCommerce pienamente compatibile, con l'integrazione accesa. Il catalogo statico si aggiorna da solo: ogni cambio di un prodotto o di una variante (schermata del prodotto, ordine, rimborso, REST API, importazione, saldo programmato) rigenera la sua pagina e quelle dei prodotti che lo mostrano; prezzo, saldo, stato di magazzino, categorie e ordine del catalogo rigenerano anche negozio, categorie e tag con tutte le pagine, la home e le pagine con blocchi o shortcode di prodotti. Le impostazioni del negozio che compaiono su ogni pagina rigenerano tutte le rotte una volta.
* Restano a WordPress anche `/wc-api/` e `/wc-auth/` (ritorni dei pagamenti, anche con Strict) e ogni pagina che WooCommerce prende per carrello, checkout o account o che è segnata `Cache-Control: private`. Una pagina con un nonce che Static Bridge non sa rinnovare resta a WordPress, solo quella, con il motivo nell'Attività e nella Diagnostica (filtro `static_bridge_static_nonce_actions` per dichiararne uno innocuo).
* I nonce della Store API e della REST API delle pagine statiche vengono rinnovati nel browser (`woocommerce-static.js`): «aggiungi al carrello» dai blocchi funziona anche dopo la scadenza.
* Il modulo di ordinamento del catalogo non porta più nella copia statica i parametri del rendering interno.
* Diagnostica: nuovi controlli per `/wc-api/`, la Store API, l'aggiornamento del catalogo, le pagine lasciate a WordPress e le impostazioni del negozio in contrasto con le copie statiche; WooCommerce attivo con l'integrazione spenta è un avviso.
* Le pagine dei negozi e delle categorie dei prodotti si contano con il numero di prodotti per pagina di WooCommerce.

= 2.2.12 =
* Aggiornamenti incrementali con le traduzioni: una pagina diventata un redirect verso una pagina tradotta (per esempio verso la home in cinque lingue con TranslatePress) si completa. Dalla 2.2.9 alla 2.2.11 veniva riprovata all'infinito, riscrivendo a ogni giro le copie della destinazione, e la sua copia statica non veniva mai tolta. Provato su una copia vera di un sito con TranslatePress.

= 2.2.11 =
* Riattivazione: le copie statiche dei contenuti diventati bozza, privati, protetti da password, programmati o finiti nel cestino mentre il plugin era spento escono dalla release, anche nelle altre lingue, prima che le regole del server tornino a servirla; le pagine che li elencavano si aggiornano. Prima la release li rimetteva online.
* Se un contenuto pubblico alla disattivazione è stato eliminato o ha cambiato indirizzo mentre il plugin era spento, la sua copia non si può ritrovare: il routing statico resta spento, con un avviso, finché un export completo non sostituisce la release.
* Pagine tradotte: l'aggiornamento incrementale riscrive le traduzioni di una pagina invece di toglierle. Dalla 2.2.9 il percorso tradotto (TranslatePress o filtro `static_bridge_translated_urls`) veniva confrontato con il permalink della pagina originale e, diverso, la sua copia statica veniva rimossa fino all'export completo.

= 2.2.10 =
* Su Apache una pagina tolta (contenuto rimosso o non più pubblico, che WordPress chiude con 404, 410, 401 o 403 o che reindirizza altrove) risulta rimossa solo quando le regole in `.htaccess` non la servono più: se non si possono aggiornare, l'aggiornamento resta in coda e viene riprovato, e la cache di Cloudflare si svuota dopo. Prima la pagina poteva restare visibile fino alla manutenzione successiva mentre l'Attività la dava per rimossa.
* Un contenuto protetto da password non viene più rigenerato dall'aggiornamento incrementale: la sua copia statica si toglie, come già facevano il push manuale e l'export completo.
* Un export avviato da una versione molto vecchia del plugin, prima della coda di export, non viene più ripreso: si chiude senza scrivere pagine e chiede un nuovo export completo.
* Su Apache le copie statiche non passano da mod_include (SSI) anche dove il server lo applica ai file `.html`. Il controllo delle direttive SSI riconosce anche il comando `perl` di Nginx.
* Documentazione: Strict non nasconde nulla, una richiesta con parametri o con il cookie di login va comunque a WordPress.
* Rafforzamenti dall'audit di sicurezza di Histeon del 3 novembre 2026 (note 28, 29, 34, 35, 36, 38).

= 2.2.9 =
* Pagine tradotte: un aggiornamento incrementale rigenera ogni percorso anche nelle altre lingue. TranslatePress è letto direttamente (con gli slug tradotti); un altro plugin di traduzione può indicare i suoi percorsi con il filtro `static_bridge_translated_urls`. Prima le traduzioni restavano vecchie fino all'export completo.
* Diagnostica: avvisa quando Complianz è in modalità sicura, che spegne il blocco di cookie e script anche nelle pagine statiche.

= 2.2.8 =
* Aggiornamenti firmati: il plugin si aggiorna dalle release pubbliche su GitHub, come ogni plugin dall'amministrazione, e installa solo pacchetti firmati con la chiave di rilascio (docs/UPDATES.md). Acceso di serie per il plugin autonomo; `STATIC_BRIDGE_UPDATES` a false in wp-config.php lo spegne. Una sola richiesta anonima a GitHub al massimo ogni 12 ore.

= 2.2.7 =
* Il builder visuale Histos si chiama ora Histeon: messaggi e documentazione usano il nuovo nome. Il funzionamento non cambia.

= 2.2.6 =
* Sicurezza: una pagina che WordPress ora chiude ai visitatori (risposta 401 o 403, per esempio con un plugin di accesso) perde subito la sua copia statica, come già succedeva con 404 e 410. Prima l'aggiornamento incrementale riprovava per circa tre quarti d'ora e poi lasciava pubblicata la copia precedente fino al prossimo export completo (audit di sicurezza di Histos del 3 novembre 2026).

= 2.2.5 =
* Il token di rendering non resta più nei link di condivisione. Elementi come Post Sharing di Bricks scrivono l'indirizzo della pagina codificato (`%3Fstatic_bridge_bypass%3D1%26…`) nei link a Facebook, WhatsApp e X, e la pulizia dell'HTML non riconosceva quella forma: chi condivideva una pagina statica diffondeva un URL con `static_bridge_bypass=1`. Ora vengono tolti anche in quella forma, anche codificati due volte, e il resto del link resta com'era. Per correggere le pagine già pubblicate serve un nuovo export.
* `static_bridge_bypass=1` fra due parametri (`?lang=en&static_bridge_bypass=1&page=2`) ora viene tolto anche nella forma normale.

= 2.2.4 =
* TranslatePress con il SEO Pack: le pagine con slug tradotto (`/en/where-we-are/`, `/de/wo-wir-sind/`) diventano statiche. Prima restavano servite da WordPress con «Rendering WordPress non verificato», perché il SEO Pack riscrive l'indirizzo della richiesta nello slug italiano prima che Static Bridge controlli il rendering.

= 2.2.3 =
* Complianz: ogni salvataggio (impostazioni, banner, Script Center, integrazioni, cookie e servizi) rigenera tutte le pagine statiche. Prima `banner_version`, script bloccati e soprattutto il numero della policy restavano quelli dell'ultimo export, e chi aveva già dato il consenso non riceveva la nuova richiesta. Serve l'aggiornamento incrementale attivo.
* Nuovo passo della Diagnostica: banner e policy di Complianz nella homepage statica confrontati con quelli correnti.

= 2.2.2 =
* Interfaccia di amministrazione rimessa a posto, senza cambiare funzioni: stessi colori, testi leggibili (contrasto AA), focus visibile su ogni controllo da tastiera, nessuno scorrimento orizzontale fino a 320 pixel.
* Impostazioni divise in sezioni con un indice e il pulsante Salva sempre a portata; Cloudflare e le note su Bricks e Kadence restano chiuse finché non servono.
* Genera mostra prima lo stato e il pulsante, poi la spiegazione. La Diagnostica mette in cima errori e avvisi.
* Gli avvisi di WordPress non finiscono più dentro l'intestazione della pagina. Messaggi di pausa e URL lunghe non vengono più tagliati.

= 2.2.1 =
* Slug uguali a un'estensione (`/tag/pdf/`, `/tag/c/`, una pagina `class`) o con `_` iniziale: la copia si salva nella cartella che Apache e Nginx cercano, quindi viene servita (prima finiva in `tag/unnamed-file.pdf/` e con Strict dava 404). I segmenti che un nome di file non può contenere in modo sicuro restano dinamici. Le vecchie cartelle `unnamed-file.*` spariscono al prossimo export completo.
* Le pagine statiche inviano le intestazioni di sicurezza del sito: X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, Strict-Transport-Security e Content-Security-Policy senza nonce, prese dalla home a ogni export completo (filtro `static_bridge_security_headers`). Su Apache servono mod_headers; su Nginx ricopia il blocco quando cambiano. Nuovo passo della Diagnostica.
* Permalink senza barra finale (`/%postname%`) o con `.html`: l'export segue la struttura invece di salvare solo la home, e la forma che WordPress non collega va al suo 301.
* Riattivazione dopo modifiche fatte a plugin spento: la release precedente resta servita, ma Strict resta spento fino al prossimo export completo, con un avviso.
* I link a `/page/N/` oltre il limite di pagine per archivio non vengono seguiti, così una paginazione senza fine non porta l'export oltre i 100.000 URL.
* Home in una sottocartella o cartella dei contenuti diversa da `/wp-content/`: l'attivazione si ferma e spiega il motivo (prima il plugin si attivava ma non serviva mai nulla di statico).

= 2.2.0 =
* Gli archivi entrano nell'export completo anche se nessuna pagina li collega: categorie, tag e tassonomie pubbliche (anche vuote), archivi dei tipi di contenuto, autori e date, ciascuno con le sue pagine /page/N/ (al massimo 100 per archivio, filtro `static_bridge_archive_max_pages`). Nuova impostazione «Archivi nell'export», attiva per default. Prima un archivio entrava solo se una pagina lo collegava, e con Strict attivo gli altri rispondevano 404.
* Le route create dall'aggiornamento incrementale restano nel successivo export completo: prima l'export le cancellava e la pagina 1 di un archivio compariva e spariva a ogni salvataggio.
* L'aggiornamento incrementale rigenera tutte le pagine degli elenchi in cui compare un articolo (home, autore, termini e loro genitori, anno, mese, giorno) e rimuove quelle oltre la nuova ultima; segue anche creazione, rinomina ed eliminazione dei termini, lo slug degli autori e le impostazioni di lettura e dei permalink.
* Riconosciute come pagine-lista anche quelle con il Query Loop del core (salvato come `wp:query`), Ultimi articoli, Archivi, Categorie, Calendario ed Etch; rimettere in bozza, cestinare o rendere privato un articolo le rigenera. Limite delle pagine-lista nel filtro `static_bridge_query_page_limit`, segnalato in Attività.
* Regole del server: con Strict attivo feed in forma breve (`/rss2/`, `/atom/`), ricerca, file come `/favicon.ico` e `/.well-known/` vanno a WordPress invece di un 404; `/x` senza slash va al 301 di WordPress invece di una copia duplicata; il cookie `wp-postpass_` porta a WordPress; il 404 di Strict ha sempre `no-store`; un percorso dinamico con spazi non rompe più il `.htaccess`. Su Nginx ricopia il blocco.
* Link interni: un `#frammento` non fa più saltare il link; `<link rel="next|prev">` seguito; stessi link con e senza l'estensione DOM di PHP.
* Una pagina in coda che continua a fallire viene abbandonata dopo 8 tentativi con un avviso, se la release ne ha già una copia; senza copia resta in coda e quindi servita da WordPress. Un 410 rimuove la copia come un 404.
* Una modifica accoda tutte le pagine coinvolte con una sola scrittura della coda e un solo aggiornamento del `.htaccess`.
* Filtri `static_bridge_export_urls` (URL in più nell'export, per esempio archivi in altre lingue), `static_bridge_excluded_paths` e `static_bridge_archive_post_types`.
* Tolto il codice morto di lettura delle sitemap: l'inventario viene dal database. Strict è spento se l'impostazione manca, come all'attivazione.

= 2.1.0 =
* Interfaccia anche in inglese: Static Bridge resta scritto in italiano e, per ogni altra lingua senza traduzione propria, carica l'inglese (languages/static-bridge-en_US, .mo e JSON di admin.js). Tutti i testi visibili sono traducibili, con i commenti per i traduttori.
* La riga di avanzamento della generazione usa il singolare: «1 pagina salvata», «1 copia precedente mantenuta», «1 URL non aggiornata».
* Nessun cambiamento al funzionamento della copia statica, della coda o della pubblicazione.

= 2.0.25 =
* Filtro pubblico di sola lettura `static_bridge_status`: un builder (Histeon) può mostrare ai suoi amministratori se la copia statica è servita, quante pagine ha, quando è stata generata, quante pagine sono in coda e l'ultima attività. Niente percorsi del server, impostazioni o segreti.
* Il builder XB si chiama ora Histeon nei messaggi visibili; gli hook tecnici (`xb_release_published`, `xb/loop`) non cambiano.
* Filtro pubblico `static_bridge_archive_urls_for_post`: un plugin delle lingue aggiunge gli elenchi nella lingua del contenuto cambiato (`/en/`, `/en/category/…`), che ora vengono rigenerati. Può solo aggiungere; URL esterni o con query scartati, al massimo 100.

= 2.0.24 =
* Corretto il blocco degli aggiornamenti incrementali: una pagina collegata a un articolo che WordPress reindirizza (ad esempio l'archivio autore che un plugin SEO manda alla homepage) veniva riprovata all'infinito in testa alla coda, e le pagine accodate dopo, comprese quelle di elenco, non venivano più rigenerate. Ora la copia statica di quella pagina viene rimossa, così vale il redirect di WordPress, e la destinazione viene accodata.
* Una pagina che non si riesce a rigenerare viene riprovata dopo le altre pagine in coda.

= 2.0.23 =
* Integrazione con XB e altri builder: un componente condiviso rigenera solo le pagine che lo usano quando il plugin che lo gestisce le indica (filtro `static_bridge_dependents_for_post`); risposte non affidabili o troppo lunghe riportano al refresh completo.
* I loop XB (`xb/loop`) e le pagine che un plugin segnala (`static_bridge_post_has_query_loop`) sono pagine di elenco: si rigenerano quando cambia un articolo.
* Una release XB di stili o impostazioni rigenera tutto il sito statico.
* Action pubbliche `static_bridge_queue_paths` (rigenerazione mirata, solo percorsi del sito) e `static_bridge_release_published`.
* Aprire un blocco riutilizzabile o un template nell'editor non accoda più rigenerazioni.

= 2.0.22 =
* Diagnostica: due controlli nuovi su Nginx (visitatore con cookie di accesso e richiesta POST alla homepage) segnalano un blocco Nginx copiato senza le condizioni sui cookie o sul metodo; su Apache il controllo delle regole richiede anche queste condizioni.
* Interfaccia più leggibile: testi più grandi (corpo 14–15 px, minimo 12 px), grigi più scuri, note di ogni opzione agganciate al suo riquadro, intestazione della release leggibile.
* Corretti i plurali ("1 errore", "1 avviso"), il riferimento a LocalWP nelle istruzioni Nginx, lo stato di Fluent Forms quando il plugin non è attivo e l'aiuto della Diagnostica su Nginx.

= 2.0.21 =
* Corretta una fuga del codice di rendering interno nelle pagine statiche: il link "Annulla risposta" dei commenti (scritto da WordPress con `&#038;`) conservava `static_bridge_render=…`. Il codice era monouso e già scaduto, ma non deve comparire nelle pagine pubbliche.
* Nuovo controllo delle pagine pubblicate (dati di utenti connessi, nonce, parametri interni) eseguito dai gate di rilascio.

= 2.0.20 =
* Apache: niente più pagine troncate o allungate nell'istante di una pubblicazione. Le regole `.htaccess` servono una copia immutabile della release (`wp-content/static-bridge/served/<id>/`, hard link, nessun dato duplicato), creata a ogni export, push o aggiornamento incrementale; le copie precedenti vengono eliminate dopo 60 secondi, mai finché `.htaccess` le nomina.
* Senza hard link la copia è byte per byte; se non si può creare, le regole tornano a `current/` come nella 2.0.19, con un avviso. Costante di emergenza `STATIC_BRIDGE_APACHE_SERVED_COPIES`.
* Su Apache un push risulta completato, e la cache Cloudflare viene svuotata, solo quando le regole servono la pagina nuova.
* Nginx invariato.

= 2.0.19 =
* Corretti i problemi reali segnalati da Plugin Check e PHPCS (WordPress Coding Standards): 13 messaggi della Diagnostica senza dominio di traduzione, 3 testi con più segnaposto non numerati, un messaggio di errore non sottoposto a escape.
* Nuovo test che blocca queste regressioni.

= 2.0.18 =
* Disinstallazione completa e sicura (`uninstall.php`).
* Attivazione rifiutata su WordPress multisito.
* Verificato su WordPress 7.1.3.
* Intestazione con licenza GPL-3.0-or-later e `Update URI`, così WordPress non sostituisce il plugin con uno omonimo della directory ufficiale.

= 2.0.17 =
* Compatibilità Cloudflare facoltativa: rendering diretto all'origine, svuotamento della cache, regola di cache consigliata, diagnostica.

= 2.0.16 =
* Le pagine con Fluent Forms restano statiche rinnovando nonce e token nel browser.

= 2.0.15 =
* Il rendering interno non sposta più i file statici serviti (niente 403/404 istantanei su Apache).

= 2.0.14 =
* Corrette le regole `.htaccess`: le condizioni di sicurezza valgono per ogni pagina e la cartella del plugin non è raggiungibile direttamente.

= 2.0.13 =
* Conservazione facoltativa delle release.

= 2.0.12 =
* Il push subito dopo un export scrive nella release attiva; traduzioni caricate dopo `init`.
