# Static Bridge

Le pagine pubbliche di un sito WP servite come HTML statico; modulo di Histeon, funziona anche da solo.

Static Bridge genera una copia HTML delle pagine pubbliche e la fa consegnare direttamente dal web server ai visitatori anonimi, senza avviare PHP né interrogare il database. Tutto ciò che è dinamico resta a WP, sullo stesso dominio: amministrazione, login, REST, AJAX, anteprime, URL con parametri, richieste POST, utenti autenticati, feed, sitemap e percorsi esclusi.

Questo repository riceve, a ogni release, una copia dei sorgenti e della documentazione: lo sviluppo non avviene qui. Ogni release contiene `static-bridge-<versione>.zip` (il plugin) con la sua firma `.minisig`, `SHA256SUMS` e l'archivio dei sorgenti `static-bridge-<versione>-sorgenti.tar.gz`.

## Aggiornamenti

Lo ZIP è il plugin da installare. Prima di installarlo a mano, controlla l'impronta del file con `SHA256SUMS` (`sha256sum -c SHA256SUMS`).

Una volta installato, Static Bridge si aggiorna da solo dalle release di questo repository, dall'amministrazione di WP come ogni plugin: verifica la firma dello ZIP prima di installarlo e non manda alcun dato del sito. Come funziona e come spegnerlo: [docs/UPDATES.md](docs/UPDATES.md).

## Come funziona

- **Copia statica.** L'export parte dai contenuti pubblicati (pubblici, interrogabili, senza password) e dalla home, aggiunge gli archivi (categorie, tag, tassonomie, autori, date, con le loro pagine) e segue i link interni delle pagine renderizzate. Per ogni pagina salva l'HTML che WP produce per un visitatore anonimo. CSS, JavaScript, immagini e font non vengono copiati: restano ai loro URL sullo stesso dominio.
- **Routing del server.** Le richieste `GET` e `HEAD` senza parametri e senza cookie di sessione trovano prima il file HTML della copia; tutte le altre vanno a WP. Su Apache e LiteSpeed le regole sono scritte dal plugin nel `.htaccess`; su Nginx si copia nella configurazione del sito il blocco mostrato in Impostazioni.
- **Release atomiche.** Ogni export completo è scritto in una nuova cartella; il sito passa alla nuova versione con un solo scambio di puntatore. I visitatori vedono la versione precedente o quella nuova, mai una via di mezzo. Un errore o un annullamento lascia online la release precedente.
- **Prova di freschezza.** Una pagina entra nella copia solo se la risposta contiene un codice monouso che soltanto WP può produrre: una cache o un CDN che restituisce una versione vecchia viene rifiutato. Una pagina che mostra un errore di PHP o del database non entra nella copia.
- **Errori circoscritti.** Una pagina che non si riesce a generare viene riprovata, poi saltata e segnalata; quando possibile resta online la sua copia precedente. Guasti di disco, database o pubblicazione fermano invece il job e lasciano attiva la release in uso.
- **Aggiornamenti mirati.** Salvando un contenuto vengono accodate la sua pagina e quelle collegate (home, archivi, pagine con elenchi), elaborate in sottofondo da WP-Cron; blocchi condivisi, template, menu, widget e impostazioni globali di tema e builder aggiornano tutte le pagine. Il pulsante «Aggiorna la copia statica» rigenera subito la singola pagina.
- **Fallback o Strict.** Con l'impostazione predefinita una pagina che non è nella copia la serve WP, come senza il plugin. Con **Strict** il server risponde 404 alle pagine che non sono nella copia, senza avviare WP.
- **Diagnostica e attività.** La scheda Diagnostica controlla rendering, release, routing, intestazioni e pagine con moduli con richieste GET, senza inviare moduli né cambiare contenuti; la scheda Attività registra export, aggiornamenti ed errori; la scheda Server dice che cosa offre il server e che cosa conviene chiedere all'hosting.
- **Integrazioni.** Fluent Forms (nonce e token anti-spam rinnovati nel browser), WooCommerce (catalogo statico che si aggiorna da solo; carrello, checkout e account a WP), Complianz, plugin delle lingue, Cloudflare e altre CDN (facoltativo), All-in-One WP Migration. Hook pubblici per altri plugin e builder: [docs/DYNAMIC-CONTENT.md](docs/DYNAMIC-CONTENT.md#integrazione-con-altri-plugin-2023).

## Installazione

Requisiti: WP 6.9 o successivo, PHP 8.4 o successivo, installazione singola (il multisito è rifiutato) con la home alla radice del dominio, Apache con `mod_rewrite`, LiteSpeed o Nginx.

1. Fai un backup completo del sito e della configurazione del server.
2. Carica lo ZIP da *Plugin → Aggiungi nuovo → Carica plugin* e attivalo. L'attivazione non cambia il frontend: finché non esiste una release, il sito è servito da WP come prima.
3. **Nginx:** apri *Static Bridge → Impostazioni*, copia il blocco Nginx al posto della `location /` del sito (mantieni la location PHP e `@wperror`), verifica con `nginx -t` e ricarica Nginx. **Apache e LiteSpeed:** le regole vengono gestite dal plugin; OpenLiteSpeed le legge al riavvio, e la Diagnostica dice quando serve.
4. Controlla in *Impostazioni* i percorsi dinamici e, se li usi, le schede *Moduli* (Fluent Forms) e l'integrazione WooCommerce.
5. Da *Genera* avvia il primo export completo e controlla l'esito in *Attività* e *Diagnostica*.
6. Configura un cron di sistema che richiami `wp-cron.php`: con le pagine servite in statico i visitatori non avviano più WP-Cron.
7. Lascia **Strict** disattivato finché non hai verificato il sito.

Per lo sviluppo si può copiare il contenuto di `plugin/` in `wp-content/plugins/static-bridge/`. La guida passo per passo è in [docs/USER-GUIDE.md](docs/USER-GUIDE.md), la configurazione dei server in [docs/SERVER-SETUP.md](docs/SERVER-SETUP.md).

## Esclusioni e limiti

- **Percorsi dinamici.** Un percorso in *Impostazioni → Percorsi dinamici* (di serie `/iscriviti/` e `/contatti/`) resta a WP con tutte le sue sottopagine. Una pagina con un modulo non deve per forza essere dinamica: l'HTML può restare statico mentre l'invio va a WP.
- **Moduli.** Con Fluent Forms la scheda *Moduli* sceglie pagina per pagina fra copia statica e pagina dinamica. CAPTCHA, upload, pagamenti, logica personalizzata e add-on vanno provati sul proprio staging; se il markup non è riconosciuto la pagina resta a WP. Altri plugin di moduli non sono gestiti dalla scheda.
- **WooCommerce.** Carrello, checkout, account, pagamenti e Store API restano sempre a WP; il catalogo statico mostra i prezzi del visitatore anonimo. Prezzi per paese, multi-valuta e prezzi per ruolo non sono coperti; gateway reali, abbonamenti e checkout personalizzati vanno provati sul proprio staging.
- **Ciò che il plugin non vede.** File CSS/JS modificati direttamente, snippet di codice, impostazioni salvate fuori dagli eventi che Static Bridge osserva: per questi casi c'è «Aggiorna tutte le pagine statiche» in *Genera*, o un export completo. Gli elenchi che dipendono dalla data senza che cambi un contenuto si aggiornano con un export completo o con le pagine da aggiornare ogni notte.
- **Contenuti per utente.** Ricerca, personalizzazioni per utente, contenuti generati da JavaScript o da endpoint esterni non diventano statici con la sola generazione delle pagine: gli endpoint restano a WP, o il percorso va escluso.
- **Firewall.** Una pagina servita come file non avvia PHP: i plugin firewall di WP non la vedono. Per le pagine statiche servono regole a monte (WAF del server, del proxy o della CDN). Login, amministrazione, moduli e REST restano coperti dai plugin.
- **WP-Cron.** Gli aggiornamenti incrementali dipendono da WP-Cron: usa un cron di sistema se serve una finestra temporale garantita.
- **Spazio.** Ogni export completo è una copia intera del sito. La scheda *Versioni* mostra le release salvate e la pulizia automatica (accesa di serie sui siti nuovi) tiene una sola versione precedente.
- **Limiti fissi.** Al massimo 100.000 URL per release e 5 MiB per pagina HTML: non sono una promessa di prestazioni su un sito di quelle dimensioni.
- **Hosting.** Le prove automatiche girano su stack di prova isolati (WP 6.9 e 7.1, PHP 8.4, Nginx, Apache, OpenLiteSpeed). Ogni hosting, cache intermedia, CDN e insieme di plugin va provato su uno staging del sito prima della produzione.

## Prove

La suite di regressione non richiede WP, database, rete o invii di moduli: usa stub locali e rimuove solo la propria fixture temporanea.

```sh
php tests/security/run.php
php tests/security/run.php --json
STATIC_BRIDGE_NGINX_BIN="/path/to/nginx" php tests/security/run.php
```

Con `STATIC_BRIDGE_NGINX_BIN` la suite controlla anche la sintassi del blocco Nginx generato. Non è un penetration test del sito né uno scanner malware. Le prove end-to-end su stack usa-e-getta e i gate di rilascio sono in [tests/e2e/README.md](tests/e2e/README.md).

Resta disponibile `bin/export-sitemap.sh` per generare una release da terminale senza la GUI: non installa il plugin e non modifica il routing.

## Guide

- [Indice della documentazione](docs/README.md)
- [Guida utente](docs/USER-GUIDE.md)
- [Configurazione del server](docs/SERVER-SETUP.md): Apache, LiteSpeed, Nginx, scheda Server, Cloudflare
- [Form e contenuti dinamici](docs/DYNAMIC-CONTENT.md): Fluent Forms, WooCommerce, Complianz, lingue, builder, hook
- [Operazioni, release e ripristino](docs/OPERATIONS.md)
- [Aggiornamenti firmati](docs/UPDATES.md)
- [Modello di sicurezza](docs/SECURITY.md)
- [Risoluzione problemi](docs/TROUBLESHOOTING.md)
- [Guida sviluppatori](docs/DEVELOPMENT.md)
- [Changelog](plugin/readme.txt)

## Licenza e sorgenti

GPL-3.0-or-later: il testo è in `LICENSE`. I sorgenti completi di ogni versione sono anche nell'archivio allegato alla sua release.

- Segnalare una falla, in privato: [SECURITY.md](SECURITY.md).
- Il nome Histeon e i marchi di altri (nessuna affiliazione con i prodotti citati): [TRADEMARKS.md](TRADEMARKS.md).
