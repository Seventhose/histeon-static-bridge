# Sicurezza

Static Bridge gira dentro WP: il plugin scrive copie statiche delle pagine pubbliche, regole per il server web, e pulisce la cache del CDN. Una falla può toccare siti veri. Se ne trovi una, segnalala in privato, non in una issue pubblica.

## Come segnalare

- Sul repository pubblico usa **Security → Report a vulnerability** (segnalazione privata di GitHub). Solo i manutentori la vedono.
- Scrivi la versione di Static Bridge, cosa può fare chi sfrutta la falla, con quale ruolo WP, e i passi per riprodurla.
- Non provarla su siti che non sono tuoi.

Rispondiamo il prima possibile, diciamo se la confermiamo e, quando la correzione è pronta, la pubblichiamo con una nota nel changelog (`readme.txt`). Chi l'ha segnalata è citato, se lo desidera.

## Versioni coperte

Solo l'ultima release. Le versioni precedenti non ricevono correzioni: aggiorna. Static Bridge è anche un modulo di Histeon: se la falla riguarda solo come Histeon lo include, segnalala nel repository di Histeon.

## Cosa è una falla

Il modello di sicurezza del plugin è in `docs/SECURITY.md`, nei sorgenti allegati a ogni release. In breve:

- chi ha meno permessi riesce a fare ciò che il suo ruolo non permette;
- contenuto salvato da un utente che esegue codice nel browser di un altro (XSS);
- richieste fatte a nome di un amministratore senza il suo consenso (CSRF), o dal server verso indirizzi interni (SSRF);
- contenuto privato, in bozza o protetto da password che diventa pubblico;
- segreti (chiavi, token) leggibili da chi non dovrebbe.

Non sono falle di Static Bridge ciò che può già fare un amministratore, né i problemi di plugin e temi di terzi.
