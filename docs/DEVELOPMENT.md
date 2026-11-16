# Guida sviluppatori

## Struttura

- plugin/static-bridge.php: bootstrap, callback di attivazione/disattivazione,
  GUI, inventario/export HTML, routing e diagnostica.
- plugin/export-queue.php: tabella e operazioni della coda indicizzata.
- plugin/assets/admin.js e admin.css: interfaccia della bacheca.
- tests/security/run.php: suite di regressione autonoma con stub WordPress.
- tests/e2e/: harness end-to-end su stack WordPress usa-e-getta (6.9 Nginx e
  Apache, 7.1.3 Nginx, Fluent Forms su Nginx e Apache) e `gates-release.sh`,
  che esegue tutti i gate di una release. Gli stack si costruiscono da zero con
  `setup-stack.sh` in `$SBQA_HOME` (default `tests/e2e/stacks/`, ignorata da
  Git); vedere [`tests/e2e/README.md`](../tests/e2e/README.md).
- config/apache-root.htaccess: riferimento Apache incluso nel progetto.
- bin/export-sitemap.sh: utility di compatibilità separata; non installa il
  plugin e non configura il routing.

Tutto ciò che serve a ricostruire gli stack di prova è in tests/e2e. Non
includere tests, docs o vecchi pacchetti nell'artefatto installabile. Controllare a mano il contenuto del pacchetto di ogni release.

## Flusso di export

1. L'azione admin autenticata crea un job e una nuova directory staging.
2. Una tabella indicizzata conserva URL deduplicati e stato per job.
3. L'inventario WordPress procede con paginazione keyset; la scansione HTML
   scopre anche link interni.
4. Il plugin acquisisce solo URL normalizzati della stessa origine con API
   HTTP sicura, limiti di tempo/dimensione e redirect non automatici.
5. Ogni URL viene mappato a un index.html sotto la release e scritto via file
   temporaneo.
6. Errori transitori di una singola route sono riprovati fino a due volte;
   se persistono, la route viene registrata una volta come errore, saltata e la
   sua ultima copia valida viene mantenuta quando disponibile. Il worker
   prosegue con le altre route.
7. Errori di staging, database, scrittura, disco, indici obbligatori o commit
   della release falliscono chiusi; manifest/marker e current vengono sostituiti
   solo dopo il completamento sicuro della coda.

Il web server serve poi current solo per le richieste ammesse. Gli asset non HTML
non vengono copiati.

## Regole di modifica

- Preservare il fallback WordPress e il commit atomico; distinguere un errore
  isolato di route (non fatale, con copia precedente mantenuta se disponibile)
  da un errore infrastrutturale che rende insicura la release.
- Non allargare l'origine HTTP accettata senza revisione di sicurezza.
- Validare ogni nuovo mapping di URL su traversal, symlink, host e query.
- Se si modificano esclusioni o snippet, aggiornare entrambi i percorsi Apache
  e Nginx e i test corrispondenti.
- Non dichiarare supporto per un builder, form, cache o plugin dopo il solo
  controllo del markup: aggiungere test runtime specifici.
- Mantenere chiaro il confine tra test con stub, test database, test server e
  test end-to-end.

## Verifiche prima del commit/pacchetto

~~~sh
php -l plugin/static-bridge.php
php -l plugin/export-queue.php
php -l tests/security/run.php
php tests/security/run.php
~~~

Eseguire inoltre il parser Nginx quando disponibile, verificare Apache su
ambiente compatibile e seguire il gate completo descritto in
[`tests/e2e/README.md`](../tests/e2e/README.md):

~~~sh
export SBQA_WP69_CORE=/percorso/wordpress-6.9.zip   # o un albero 6.9 estratto
bash tests/e2e/setup-stack.sh all                   # una volta per macchina
bash tests/e2e/gates-release.sh dist/static-bridge-X.Y.Z.zip
~~~

Quando si aggiunge uno scenario end-to-end: metterlo in tests/e2e, leggere
stack e percorsi solo da `profile.sh`/`profile.php` (mai percorsi assoluti),
mantenere le allowlist esatte (cartella con marcatore `.sbqa-stack`, database,
socket, home) e non salvare dati degli stack fuori da `$SBQA_HOME`. Non usare dati live o cancellare
release per creare fixture; la suite CLI deve mantenere i propri test sotto
directory temporanea.

Ogni artefatto deve avere versione coerente nei file e note di rilascio,
inventario dei file, hash e risultati di test riferiti al codice esatto.
Aggiornare documentazione e limiti quando un comportamento cambia.
