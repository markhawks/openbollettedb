# OpenBolletteDB

Applicazione PHP minimale, senza framework né dipendenze esterne, per tenere traccia delle bollette
domestiche (Luce, Gas, Acqua, TARI, Bonifica) in un database SQLite locale.

## Requisiti

- PHP 8.x con estensione PDO SQLite
- Un server web (Apache/php-fpm o il server integrato di PHP)

## Avvio rapido

```bash
php app/migrate.php               # crea/aggiorna lo schema e i dati di base
php app/seed_demo.php             # opzionale: popola con bollette di esempio (dati inventati)
php -S localhost:8000 -t .        # avvia il server di sviluppo
```

Apri `http://localhost:8000/`.

`seed_demo.php` inserisce circa due anni di bollette fittizie per ogni utenza, così l'app non parte
vuota alla prima installazione: è un modo pratico per vedere subito grafici, riepiloghi e stime
compilati, e per capire come strutturare le proprie bollette reali. Non fa nulla (e non tocca dati
esistenti) se il database contiene già delle bollette. Ogni riquadro annuale in ogni dashboard ha un
pulsante "🗑️ Svuota anno" per eliminare in un colpo solo tutte le bollette demo di quell'anno/utenza
e ripartire con i propri dati reali.

## Struttura

- `index.php` — routing minimale via `?u=<codice_utenza>`
- `pages/dashboard_*.php` — una dashboard per ogni utenza (luce, gas, acqua, tari, bonifica)
- `new_bill.php` / `edit_bill.php` / `delete_bill.php` / `reset_year.php` — CRUD bollette
- `app/db.php` — connessione PDO/SQLite condivisa
- `app/migrate.php` — schema del database e seed delle utenze
- `app/seed_demo.php` — genera bollette di esempio per la prima installazione (dati inventati)
- `app/csrf.php` — token di sessione usato per proteggere le azioni distruttive (elimina/svuota anno)
- `changelog.php` — note di rilascio, raggiungibile dall'icona 📝 nell'header

Per i dettagli architetturali (modello dati, convenzioni, quirk noti) vedi `CLAUDE.md`.

## Sicurezza

Pensata per uso **locale/LAN**, senza autenticazione: chiunque raggiunga l'URL può leggere e
modificare i dati. Non esporre questa app direttamente su Internet senza aggiungere un livello di
autenticazione.

## Note di rilascio

Lo storico delle modifiche è consultabile nell'app stessa tramite l'icona 📝 accanto al nome, oppure
direttamente in `changelog.php`.
