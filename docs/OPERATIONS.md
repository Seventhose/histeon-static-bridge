# Operazioni, release e ripristino

## Dove sono salvati i file

La radice è WP_CONTENT_DIR/static-bridge/, normalmente
wp-content/static-bridge/ nel document root WordPress. La schermata
Impostazioni mostra il percorso assoluto effettivo.

- Release: wp-content/static-bridge/releases/<id>/.
- HTML della home: index.html.
- HTML di /articolo/: articolo/index.html.
- Manifest e marcatore di release completata: nella directory della release.
- Puntatore corrente: symlink wp-content/static-bridge/current verso una
  directory sotto releases/.
- Staging: directory della release in preparazione, prima che venga attivata.
- Solo Apache, dalla 2.0.20: copie servite in
  wp-content/static-bridge/served/<id>/, hard link degli `index.html` e del
  manifest della release corrente. Sono le uniche cartelle che `.htaccess`
  nomina; vedere [Configurazione del server](SERVER-SETUP.md#pagine-troncate-nellistante-di-una-pubblicazione-corretto-nella-2020).

La release contiene principalmente HTML renderizzato e metadati di release.
CSS, JavaScript, immagini, font e upload non vengono copiati: restano ai loro
URL WordPress. Job, opzioni e attività sono inoltre registrati nel database
WordPress; la coda d'esportazione usa una tabella indicizzata dedicata.

## Pubblicazione iniziale e sostituzione

La scansione enumera contenuti pubblicati, pubblici, interrogabili e senza
password, quindi segue link interni e viste raggiungibili. La sitemap non è
obbligatoria. La coda è persistente e lavora a blocchi; il limite prudenziale
corrente è 100.000 URL unici. Ogni risposta HTML è limitata a 5 MiB.

La release statica contiene route senza query string. Se WordPress usa i
permalink semplici (`/?p=123`, `/?page_id=45`), gli articoli e le pagine con
quegli URL restano dinamici su WordPress: non vengono serviti per errore come
home statica e l'export li segnala tra gli URL non staticizzati. Per includere
anche quei contenuti nella release, configura permalink leggibili prima
dell'export completo. Le richieste con query continuano a bypassare il router
statico e raggiungono WordPress.

I file sono scritti nella nuova release. Solo dopo completamento e scrittura
corretta di manifest e marker, il plugin sostituisce atomicamente il symlink
current. Un errore o annullamento lascia online la release precedente e non
pubblica la release incompleta.

## Migrare una release da locale al sito live

Static Bridge prepara gli URL same-origin sia nelle esportazioni complete
sia negli aggiornamenti incrementali come percorsi root-relative (`/articolo/`,
`/wp-content/uploads/...`), non come URL assoluti del dominio locale. Gestisce
anche le URL con slash escapati dentro JSON; i domini esterni restano invariati.
Il percorso conserva eventuali sottopercorsi della URL WordPress: per questa
migrazione locale/live la struttura pubblica dei permalink deve rimanere uguale.
Dalla 2.2.14 canonical, `link rel="alternate"` (hreflang, feed), shortlink,
Open Graph, Twitter e i dati strutturati JSON-LD restano invece assoluti, con
l'origine pubblica di WordPress: anteprime dei social e `@id` li vogliono
completi. Il manifest registra quell'origine (`absolute_url_origin`). Una
release importata su un dominio diverso non viene attivata: quei tag
indicherebbero il dominio di partenza a motori di ricerca e anteprime.
WordPress serve il sito e l'Attività chiede un export completo sul nuovo
dominio. Le release di prima della 2.2.14, interamente relative, si attivano
come prima. Verificare comunque canonical, Open
Graph e sitemap SEO sul dominio live (Diagnostica: *Canonical e anteprime come
le vede un visitatore*).

Con All-in-One WP Migration, tenere Static Bridge attivo sia quando si crea
l'archivio locale sia durante il ripristino, includere database, plugin e file
di `wp-content` e non escludere `wp-content/static-bridge/releases`. Il filtro
Static Bridge esclude soltanto `static-bridge/current`, che è un symlink e
creerebbe copie duplicate degli stessi HTML nell'archivio, e dalla 2.0.20
`static-bridge/served` (copie Apache derivabili: gli archivi non conservano gli
hard link). Sulla destinazione Apache la prima pubblicazione delle regole dopo
l'import crea una copia servita nuova. Al termine, l'hook
AIO verifica manifest, marker `.ready` e dichiarazione `portable_internal_urls`,
quindi ricrea `current` verso la release importata. Se l'importer non carica
l'hook nella richiesta finale, Static Bridge ripete lo stesso controllo al primo
accesso amministrativo autorizzato, ma solo se il marker `.enabled` indica che
il routing era atteso. Se release o manifest non superano i controlli, il
routing resta disattivato e WordPress serve il frontend: non parte una
ristaticizzazione automatica e non viene cancellata alcuna release.

Il database migrato conserva impostazioni e stato del plugin. Le URL locali
eventualmente già accodate vengono rimappate sul nuovo `home_url`; se
l'aggiornamento incrementale è attivo, la coda viene riprogrammata. Le
successive pubblicazioni e modifiche tornano quindi ad aggiornare solo le
pagine e le viste correlate, senza rifare l'export completo. Lasciare attivo
Static Bridge e configurare WP-Cron (meglio un cron di sistema sui siti con
poco traffico). L'HTML non sostituisce gli asset: immagini, CSS, font, tema e
plugin devono essere trasferiti con il sito oppure già presenti sul live.

Questa integrazione è stata verificata con All-in-One WP Migration 7.111 su uno
stack di prova: export GUI `.wpress` e restore GUI completo in un sito distinto,
quindi controllo del manifest, ripristino del puntatore, routing Nginx e coda
incrementale. Il restore importa la release senza riesportarla; se al termine
manca il symlink `current`, lo ricrea il recupero al primo accesso
amministrativo. Un export/import statico ZIP gestito da Static Bridge non è
implementato; il percorso verificato è la migrazione completa tramite AIO. Le release create da versioni precedenti
senza `portable_internal_urls` non vengono attivate automaticamente sul nuovo
dominio: creare una release completa portabile in locale prima della
migrazione.

## Aggiornamenti incrementali e pianificazione

Dopo una prima release, gli hook WordPress accodano gli URL interessati da
pubblicazione, modifica, passaggio a bozza/privato/cestino e cancellazione,
insieme a tutte le pagine degli elenchi che li mostrano: home e pagina articoli,
archivio del tipo, autore, termini e loro genitori, anno, mese e giorno, da
pagina 1 all'ultima, e le pagine con un Query Loop o un loop Etch/Histeon (al
massimo 30). Creazione, rinomina ed eliminazione di termini, slug degli autori e
impostazioni di lettura o permalink accodano a loro volta le route coinvolte;
per le impostazioni Attività chiede anche un export completo. Una voce che
continua a fallire (401, 403, 5xx, rete) viene abbandonata dopo 8 tentativi
con un avviso in Attività se la release ne ha già una copia (resta quella fino
al prossimo export); senza copia resta in coda, e quindi dinamica, così Strict
non risponde 404 per una pagina che WordPress serve. Un 404 o un 410 rimuove
la copia. Le richieste
vengono lavorate via WP-Cron in gruppi
limitati; se WP-Cron dipende dal traffico, una coda può restare in attesa. Se
`DISABLE_WP_CRON` è attivo o il sito riceve poco traffico, configurare un cron di
sistema che richiami `wp-cron.php` con la frequenza prevista dall'hosting e
controllare in **Attività** che la coda venga svuotata. Non attivare Strict
finché la pubblicazione automatica non è stata verificata sullo staging reale.

Per un sito grande configurare un cron di sistema secondo la propria
installazione WordPress e controllare Attività/Diagnostica. Non assumere che
“lavoro accodato” significhi “HTML già pubblicato”. Le modifiche a un singolo
post/pagina aggiornano le route interessate; una modifica a componenti globali
tracciati (Gutenberg, template Bricks, elementi Kadence, dati globali Bricks,
stili e loop di Etch o Customizer) accoda tutte le route HTML già statiche senza
rifare discovery. Per i cambi che il plugin non vede (snippet di codice, tema,
plugin) c'è «Aggiorna tutte le pagine statiche» in Genera; uno script di deploy
può chiedere lo stesso con `POST /wp-json/static-bridge/v1/refresh`
(amministratore, per esempio con una password per le applicazioni), corpo
`{"scope":"all"}` oppure `{"scope":"paths","paths":["/a/","/b/"]}`. Risponde 202
con le route accodate, 409 senza release o con gli incrementali spenti. Svuotare
un'altra cache di pagina (WP Super Cache, LiteSpeed…) non aggiorna le copie
statiche, servite prima di lei.

L'export completo non dipende dalla scheda di wp-admin (2.2.14): dopo l'avvio
ogni passo del worker termina con una richiesta di fondo non bloccante verso
`admin-ajax.php` dello stesso sito (azione `static_bridge_export_continue`, da
utente non connesso), autenticata solo da un codice casuale di 64 caratteri
esadecimali, nuovo a ogni richiesta e confrontato in tempo costante. Una
richiesta più nuova rende inutile quella di prima, quindi la catena è una sola e
un codice intercettato smette di valere al passo dopo. Passa
dall'indirizzo di origine per il rendering, se impostato. WP-Cron resta il
paracadute; il filtro `static_bridge_export_background_requests` (false) spegne
le richieste di fondo. Un export che non avanza da cinque minuti mostra un
avviso in ogni pagina di wp-admin, che prova anche a farlo ripartire.
Su siti grandi questa operazione può essere comunque onerosa.

Durante un export completo, timeout/errore di trasporto e risposte HTTP
temporanee 408, 429, 500, 502, 503 e 504 vengono riprovati al massimo due
volte su richieste worker successive. Per 429 e 503 (2.2.14) il worker aspetta
`Retry-After` e almeno 10 secondi, poi 20, e da lì in avanti procede una URL
alla volta con una pausa fra le richieste, anche fra un passo e l'altro, che si
dimezza dopo 20 risposte buone di fila; se la URL è ancora rifiutata viene
rinviata, e dopo le altre un giro di recupero la riprova lentamente dopo un
minuto. Solo se fallisce anche lì segue la regola qui sotto, e la pagina
compare in Genera con «Riprova queste». Se anche il terzo tentativo fallisce,
la sola URL viene registrata come errore e marcata saltata; il job continua
sulle altre pagine e può pubblicare la release. Se esiste una sua copia statica
precedente, il plugin la copia in staging byte-per-byte (incluse le varianti
header SSI verificate quando l'opzione è attiva), così la route non sparisce
dalla release. Tale pagina resta consapevolmente non aggiornata; il log Attività,
il manifest e il riepilogo mostrano il conteggio e l'URL. Se non esiste una
copia precedente, la pagina viene omessa: con Strict attivo risponderà 404
statico, mentre col fallback attivo WordPress potrà servirla dinamicamente.
Gli errori ordinari 401, 403, 404 e 410 restano skip attesi, non errori che
fermano il job. Problemi di database, spazio, scrittura, staging, indice SSI o
pubblicazione restano invece fatali e mantengono attiva la release precedente.

Per una coda già in pausa creata da una versione precedente, **Riprendi** ora
registra come errore la URL che aveva già esaurito i tentativi, mantiene la sua
copia precedente se disponibile e continua senza ripetere l'intera esportazione.

Static Bridge usa un timeout predefinito di 60 secondi per la singola
richiesta HTTP di rendering, mantenendo redirect disabilitati, origine
same-origin e limite della risposta HTML a 5 MiB. È stato osservato che una
coda PHP-FPM temporaneamente satura può consumare parte del timeout prima che
il rendering inizi. Il limite non aumenta il numero di worker e non risolve
saturazione persistente: configurare i timeout del server compatibili con
questa durata e dimensionare/monitorare PHP-FPM.

## Prestazioni dell'export completo

Il percorso predefinito renderizza una URL same-origin alla volta. Ogni fetch
loopback richiede un altro worker PHP mentre quello dell'export è già occupato;
aprire richieste concorrenti può quindi saturare i pool PHP-FPM piccoli e
bloccare proprio le risposte necessarie al job. La GUI indica la modalità
sequenziale. L'opzione parallela resta disponibile tramite il filtro PHP
`static_bridge_export_concurrency` (intervallo 1–8), ma va attivata solo dopo
aver verificato capacità e margine del pool sullo staging. Il parallelismo non
è una cura per un pool saturo.

Ogni worker registra inoltre un watchdog WP-Cron prima del fetch. Un lock dura
90 secondi e il watchdog riprova dopo 95 secondi: se PHP-FPM termina una
richiesta, il job può riprendere senza perdere la coda o pubblicare la release
parziale. Al termine regolare di ogni blocco, il watchdog viene anticipato a
cinque secondi. L'interfaccia continua a verificare lo stato con attesa
progressiva anche dopo errori di trasporto. Un errore circoscritto a una pagina
non mette più in pausa l'intero export; solo un problema di integrità o di
pubblicazione dell'intera release può fermarlo.

Il worker processa blocchi fino a 40 URL per tick e si ferma al budget
temporale configurato (20 secondi predefiniti, massimo 45). Questi limiti
riducono le richieste di polling senza trasformare l'export in una singola
operazione non interrompibile. La velocità dipende da tema, plugin, cache e
capacità PHP-FPM; misurare su staging prima di stimare la durata di un export
completo.

**Annulla esportazione** rimuove le righe della coda del job selezionato ma non
cancella la directory di staging e non modifica `current`; solo dopo
l'annullamento è possibile avviare una nuova release completa. Con la
conservazione attiva (vedi sotto) gli staging abbandonati si eliminano da soli
dopo un'ora; altrimenti si eliminano dalla scheda **Versioni**.

## Spazio e conservazione

Prima di scrivere una pagina l'export completo o incrementale verifica lo
spazio libero sul filesystem della release. Per impostazione predefinita
conserva una riserva minima di 64 MiB oltre ai byte della pagina; aggiorna
manifest e puntatori con scritture complete e sostituzioni atomiche. Se lo
spazio non è verificabile o non basta, il job fallisce senza pubblicare la
release parziale (un aggiornamento incrementale lascia invariato il file
statico già presente). La soglia è personalizzabile via filtro PHP
`static_bridge_min_free_space_bytes` (valore numerico non negativo espresso in
byte). È una protezione best-effort: non può impedire che altri processi
consumino spazio dopo il controllo.

Ogni export completo è una copia intera del sito: senza pulizia, esportazioni
frequenti riempiono il disco e i backup (il controllo di spazio ferma la
pubblicazione prima, ma non libera nulla).

Dalla 2.0.13 esiste la **conservazione** (Impostazioni → *Conservazione
automatica delle release*, numero di release precedenti da 1 a 20). Dalla 2.4.0
è accesa di serie sui siti nuovi, con 1 release precedente; un sito aggiornato
mantiene la sua scelta (spenta, o accesa con il suo numero, di serie 3). La
scheda **Versioni** mostra ogni cartella di `releases/` con stato, pagine e
peso, ed elimina una versione alla volta o in blocco anche con la conservazione
spenta (in blocco tiene 1 precedente); ogni eliminazione rifà il piano sotto i
tre lock e rifiuta le cartelle protette e ogni nome che non sia una cartella di
`releases/`. Se attiva:

- cinque minuti dopo ogni pubblicazione riuscita (evento WP-Cron
  `static_bridge_release_retention`) conserva la release attiva e le N release
  pronte (con `.ready`) più recenti, ordinate per data del `.ready`, ed elimina
  le altre release pronte;
- elimina gli staging senza `.ready` solo se la cartella non è stata modificata
  da più di un'ora (dalla 2.4.0; prima 24 ore);
- non elimina mai: la release `current`; quella registrata in
  `static_bridge_last_release` (usata dal ripristino dopo un'importazione
  All-in-One); la cartella di un'esportazione in corso o in pausa (dalla 2.4.0:
  quella di un export fallito o annullato non riprende mai, e si elimina);
  una release di una transazione di rendering; le release di riferimento della
  verifica SSI; ogni release con righe nell'indice dipendenze V2 o nell'indice
  dei frammenti header. Se uno di questi indici non è leggibile, la pulizia non
  parte;
- non segue collegamenti simbolici: rifiuta una cartella `static-bridge` o
  `releases` che sia un symlink, ignora le voci di `releases/` che sono symlink,
  file o nomi non generati dal plugin, e dentro una release da eliminare rimuove
  i symlink senza visitarne la destinazione;
- parte solo se nessun export è in esecuzione (job `running`), non c'è una
  transazione di rendering da recuperare né una diagnostica in corso, e ottiene
  il lock del worker di export, il lock del rendering interno e il lock del
  worker incrementale/push. In caso contrario rinvia (evento ripianificato al
  massimo 3 volte) senza eliminare nulla;
- prima di eliminare, rinomina la cartella in
  `<id>.sb-retention-<12 hex>`; se il tempo disponibile (20 s per esecuzione) si
  esaurisce o PHP si interrompe, l'esecuzione successiva completa solo queste
  cartelle;
- registra ogni esecuzione (eliminate, spazio liberato, rinvii, errori) in
  **Attività**; l'elenco delle cartelle e i pulsanti (solo amministratori, con
  nonce) sono nella scheda **Versioni**.

Disattivare l'opzione rimuove l'evento in attesa; anche la disattivazione del
plugin lo rimuove. Limite residuo: un worker incrementale che avesse già
risolto la release precedente prima dello scambio di `current` potrebbe
ricreare una cartella parziale con lo stesso nome dopo l'eliminazione; la
release appena sostituita è comunque tra le N conservate e la cartella
ricreata, priva di `.ready`, viene trattata come staging abbandonato.

Senza conservazione automatica, controllare spazio disco e dimensione delle
cartelle, ma non cancellare manualmente current, la release puntata da current
o il solo backup disponibile. Prima di ogni pulizia manuale:

1. identificare il target e verificare che non sia puntato da current;
2. verificare backup e release alternative ripristinabili;
3. eseguire la pulizia solo con una procedura approvata e fuori da un export in
   corso.

La protezione HTTP delle release archiviate deve restare in vigore anche se i
file restano sul disco.

**Copie servite su Apache (2.0.20).** Ogni pubblicazione crea una copia della
release corrente in `served/` fatta di hard link: nessun byte di HTML
duplicato, solo una cartella per pagina (4 KiB ciascuna su ext4: circa 4 MiB per
1.000 pagine) e le voci di directory. Una copia viene eliminata 60 secondi dopo
che `.htaccess` ha smesso di nominarla (evento WP-Cron
`static_bridge_served_copy_maintenance`, e a ogni pubblicazione), mai finché
`.htaccess` la nomina o se `.htaccess` non è leggibile. Questa pulizia è sempre
attiva e non dipende dalla conservazione delle release. Con pubblicazioni
continue restano solo le copie degli ultimi 60 secondi. Senza hard link la
copia è byte per byte e occupa la dimensione della release; se lo spazio non
basta le regole tornano a `current/` con un avviso. I byte liberati dalla
conservazione delle release contano solo i file non più collegati da una
copia. Non cancellare a mano le cartelle di `served/`: la copia nominata da
`.htaccess` è quella che Apache sta servendo.

## Disattivazione e ritorno al sito dinamico

Disattivare Static Bridge dalla bacheca:

- Nginx: viene rimosso .enabled; il blocco server manuale ricade sul fallback
  WordPress. Il plugin non rimuove la configurazione Nginx.
- Apache: viene rimosso il routing Static Bridge dal .htaccess; il blocco
  separato di privacy resta se ci sono release o copie servite e, dalla 2.0.14,
  nega l'accesso diretto a tutta `wp-content/static-bridge/` (anche all'ultima
  release). Le copie servite restano sul disco come le release; alla
  riattivazione se ne crea una nuova e le vecchie vengono eliminate.
- Entrambi: la modalità strict viene disattivata e i file statici non vengono
  cancellati.

Alla riattivazione la release più recente torna servita. Dalla 2.2.1, se dopo
quella release sono cambiati articoli, pagine o altri contenuti pubblici
(stato diverso da bozza automatica e revisione), Strict resta spento finché un
export completo non pubblica una release nuova; l'avviso compare in Attività e
nelle pagine di amministrazione. Le modifiche fatte a plugin spento entrano
solo con quell'export.

Poi aprire una pagina pubblica, fare login, visitare wp-admin, verificare REST
e pagine dinamiche. La risposta deve arrivare dal WordPress normale; non
cancellare i file durante il test. Se il server continua a servire statico,
controllare marker .enabled e regole effettivamente caricate.

## Modifica o rollback

La prima scelta di rollback è disattivare il plugin e verificare il fallback a
WordPress. Per ripristinare un'altra release non eseguire sostituzioni manuali
del symlink in produzione con un export attivo: usare prima una procedura
staging collaudata e backup del filesystem. Static Bridge non offre un pulsante
di rollback.
