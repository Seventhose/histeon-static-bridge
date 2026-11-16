# Documentazione Static Bridge

Static Bridge serve le pagine pubbliche di un sito WordPress come HTML statico
sullo stesso dominio; amministrazione, login, REST, moduli e utenti autenticati
restano a WordPress. La panoramica è nel [README](../README.md); le novità di
ogni versione sono nel changelog di [`plugin/readme.txt`](../plugin/readme.txt).

Questi manuali sono documentazione del repository e non sono inclusi nello ZIP
installabile, che contiene i soli file del plugin.

## Manuali

- [Guida utente](USER-GUIDE.md): installazione di prova, prima release e uso
  delle schede amministrative.
- [Configurazione del server](SERVER-SETUP.md): instradamento Apache, LiteSpeed
  e Nginx, scheda Server, Cloudflare e altre CDN, verifica e disattivazione.
- [Form e contenuti dinamici](DYNAMIC-CONTENT.md): esclusioni, Fluent Forms,
  WooCommerce, Complianz, plugin delle lingue, builder e hook per altri plugin.
- [Operazioni, release e ripristino](OPERATIONS.md): cartelle, aggiornamenti
  incrementali, cron, spazio, conservazione delle release e ritorno a WordPress.
- [Aggiornamenti firmati](UPDATES.md): come il plugin si aggiorna dalle release
  pubbliche e come si verifica la firma.
- [Modello di sicurezza](SECURITY.md): confini, controlli implementati e limiti.
- [Risoluzione problemi](TROUBLESHOOTING.md): sintomi e controlli sicuri.
- [Guida sviluppatori](DEVELOPMENT.md): struttura dei sorgenti e ciclo di
  modifica e verifica.
- [Prove end-to-end](../tests/e2e/README.md): gli stack di prova usa-e-getta e
  i gate di rilascio.

## Ambito supportato

- WordPress 6.9 o successivo e PHP 8.4 o successivo, come dichiarato
  nell'intestazione del plugin; installazioni singole (il multisito è
  rifiutato), con la home alla radice del dominio e i contenuti in
  `/wp-content/`.
- Apache con mod_rewrite e file `.htaccess` modificabile, LiteSpeed o
  OpenLiteSpeed, oppure Nginx configurato a mano con il blocco prodotto dalla
  GUI.
- Un document root WordPress scrivibile da PHP e un filesystem che consenta la
  creazione e la sostituzione dei symlink delle release.
- Un'origine pubblica WordPress raggiungibile dal processo PHP per acquisire il
  rendering frontend.

Le prove automatiche girano su stack di prova isolati (WordPress 6.9 e 7.1,
PHP 8.4, Nginx 1.26, Apache 2.4, OpenLiteSpeed 1.9). Ogni hosting, cache
intermedia, CDN e insieme di plugin va provato su uno staging del sito prima
della produzione.

Bricks, Kadence Theme, Kadence Blocks, Etch, Fluent Forms, WooCommerce,
Complianz e TranslatePress hanno integrazioni specifiche o controlli mirati, ma
non una certificazione di compatibilità universale con temi, estensioni,
gateway, personalizzazioni o configurazioni di cache di terze parti.
