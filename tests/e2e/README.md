# Harness end-to-end

Script, template e mu-plugin QA per gli stack WordPress usa-e-getta su cui
girano i gate di rilascio. Ogni script spiega nei commenti in testa che cosa
prova e che cosa gli serve. Di serie gli stack usano i binari di PHP, MySQL,
Nginx e Apache di LocalWP (`SBQA_LOCALWP`) e copiano in sola lettura core e
plugin da percorsi indicati con variabili `SBQA_*` (vedere `setup-stack.sh`).

    export SBQA_WP69_CORE=/percorso/wordpress-6.9.zip
    bash tests/e2e/setup-stack.sh all
    bash tests/e2e/gates-release.sh dist/static-bridge-X.Y.Z.zip

- `profile.sh`, `profile.php` — stack (`SBQA_STACK`), porte, database e
  allowlist esatte; `SBQA_HOME` (default `stacks/`, ignorata da Git).
- `setup-stack.sh`, `seed-baseline.php`, `templates/`, `core-hashes.txt` —
  costruzione da zero; `destroy-stack.sh` — rimozione; `stack.sh` — avvio.
- `run-e2e.sh`, `retention-e2e.sh`, `cloudflare-e2e.sh`, `uninstall-e2e.sh`,
  `ff-e2e.sh`, `apache-race-e2e.sh`, `gates-release.sh` — scenari; gli altri
  `.php`/`.js` sono i loro helper; `mu-plugins/` — mu-plugin installati solo
  negli stack QA.
- `wc-e2e.sh <zip>` (2.2.13) — WooCommerce sugli stack `wc-nginx` (porta 18741) e
  `wc-apache` (18743), WordPress 7.1.3 con WooCommerce 11.2.2 copiato in sola
  lettura da un sito LocalWP (`SBQA_WOOCOMMERCE_SOURCE` per un'altra copia; hash in
  `core-hashes.txt`). Negozio di prova (`wc-qa.php`), catalogo statico, pagine
  transazionali e `/wc-api/` a WordPress, Chrome headless (`wc-browser.js`) che
  aggiunge al carrello dalle pagine statiche anche dopo la scadenza del nonce della
  Store API e arriva all'ordine con il contrassegno, rigenerazione dopo ordine,
  prezzo, saldo ed esaurito. Mu-plugin `sbqa-woocommerce-qa.php`: posta finta, vita
  del nonce della Store API, nonce estraneo di prova.
- `litespeed-e2e.sh <zip>` (2.3.0) — OpenLiteSpeed sullo stack `litespeed`
  (porta 18751): OpenLiteSpeed 1.9.4 con lsphp 8.4 dall'immagine Docker ufficiale,
  fissata per digest in `profile.sh` (`docker pull` prima: il montaggio non scarica
  niente), rete dell'host, ascolto su `127.0.0.1`, modelli `templates/ols-*.conf`,
  `ols.sh` per `$HTTPD -v`/`-t`. Il `.htaccess` della radice vale solo dopo un
  riavvio: regole su `current/`, regola di prova 410 e Diagnostica, protezione
  `wp-content/static-bridge/.htaccess` subito attiva, pubblicazioni senza riavvio,
  WP-CLI senza server (`SBQA_NO_SERVER=1`), contesto per le intestazioni, niente
  `.gz` (il server comprime da solo).
- `apache-race-e2e.sh [runs] [seconds] [exports]` — lettori anonimi
  (`concurrent-readers.php`) durante push continui ed export completi, sullo
  stato lasciato da `run-e2e.sh`; su Apache ogni corpo deve coincidere con un
  file di una release o di una copia servita (2.0.20). Da una checkout con un
  percorso lungo impostare `SBQA_HOME` (percorso breve) e, per provare uno ZIP
  diverso da `plugin/`, `SBQA_PLUGIN_SOURCE` con un'estrazione dentro il
  progetto.
- `server-tab-e2e.sh` (2.3.0) — scheda Server dopo `run-e2e.sh`: su Apache il
  blocco `StaticBridge-Server` senza i moduli, con mod_deflate, mod_filter e
  mod_expires caricati e con un `AllowOverride` che lo rifiuta; su Nginx i
  blocchi da copiare (`nginx -t` e compressione) e l'object cache su Redis e
  Memcached in Docker (solo `127.0.0.1`), con il servizio che si ferma e
  riparte. Le configurazioni cambiate sono copie QA, ripristinate all'uscita.
- `object-cache-e2e.sh` (2.3.0) — il drop-in contro Redis, Redis con password
  e Memcached veri in Docker (`object-cache-backend.php`), senza WordPress.
  Entrambi vogliono una volta `build-php-ext.sh`, che compila `redis.so` e
  `memcached.so` per il PHP 8.4.18 degli stack nell'immagine
  `php:8.4.18-cli-bookworm`, in `$SBQA_HOME/sources/php-ext-8.4`.
