# OpenBolletteDB

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![Database](https://img.shields.io/badge/DB-SQLite-003B57?logo=sqlite&logoColor=white)
![Tested on](https://img.shields.io/badge/tested%20on-Fedora%2044-294172?logo=fedora&logoColor=white)
![License](https://img.shields.io/badge/license-All%20Rights%20Reserved-red)

Applicazione PHP minimale, senza framework né dipendenze esterne, per tenere traccia delle bollette
domestiche (Luce, Gas, Acqua, TARI, Bonifica) in un database SQLite locale.

## Requisiti

- PHP 8.x con estensione PDO SQLite
- Un server web (Apache/php-fpm o il server integrato di PHP)

> **Compatibilità**: al momento testato solo su **Fedora 44** (Apache 2.4 + php-fpm 8.x). Su altre
> distribuzioni/versioni dovrebbe funzionare allo stesso modo, ma percorsi di configurazione, nomi dei
> pacchetti e comportamento di SELinux possono variare.

## Installazione su Fedora 44

```bash
# 1. Pacchetti necessari
sudo dnf install -y httpd php-fpm php-pdo git

# 2. Avvio e abilitazione dei servizi al boot
sudo systemctl enable --now httpd php-fpm

# 3. Apertura della porta HTTP sul firewall (se il servizio "http" non è già abilitato)
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --reload

# 4. Clonazione del progetto nella webroot
cd /var/www/html
sudo git clone https://github.com/markhawks/openbollettedb.git OpenBolletteDB
cd OpenBolletteDB

# 5. Permessi: php-fpm su Fedora gira come utente/gruppo "apache" e deve poter scrivere
#    nella cartella data/ (il database SQLite e i file -wal/-shm di WAL mode)
sudo chgrp apache data
sudo chmod g+w data

# 6. Schema del database (+ opzionale: dati di esempio)
php app/migrate.php
php app/seed_demo.php
```

Apri `http://<indirizzo-del-server>/OpenBolletteDB/`.

### SELinux (Fedora Server, enforcing di default)

Se SELinux è in modalità `enforcing` (predefinito su Fedora Server), oltre ai permessi Unix del punto 5
serve anche assegnare alla cartella `data/` un contesto scrivibile da Apache, altrimenti PDO fallisce
con `SQLSTATE[HY000]: General error: 8 attempt to write a readonly database`:

```bash
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/html/OpenBolletteDB/data(/.*)?"
sudo restorecon -Rv /var/www/html/OpenBolletteDB/data
```

### Configurazione Apache per `data/`

Su Fedora l'`httpd.conf` di sistema imposta `AllowOverride None` sulla webroot: qualsiasi `.htaccess`
dentro il progetto (incluso `data/.htaccess`, già presente nel repo) viene **ignorato**. Per bloccare
davvero l'accesso diretto al database e la navigazione delle cartelle, crea invece
`/etc/httpd/conf.d/openbollettedb.conf`:

```apacheconf
<Directory "/var/www/html/OpenBolletteDB">
    Options -Indexes
</Directory>

<Directory "/var/www/html/OpenBolletteDB/data">
    Require all denied
</Directory>
```

e ricarica Apache: `sudo apachectl configtest && sudo systemctl reload httpd`. Verifica che
`data/openbollettedb.sqlite` non sia più raggiungibile via browser prima di considerare l'installazione
completa.

## Avvio rapido (sviluppo, senza Apache)

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

## Backup

Il database è un singolo file SQLite (`data/openbollettedb.sqlite`, escluso da git). Prima di
aggiornamenti importanti o manutenzioni, conviene farne una copia — includendo un checkpoint del WAL
per essere certi che tutte le scritture recenti siano nel file principale:

```bash
php -r "
\$pdo = new PDO('sqlite:data/openbollettedb.sqlite');
\$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE);');
copy('data/openbollettedb.sqlite', 'data/backup_' . date('Ymd_His') . '.sqlite');
"
```

Il file di backup resta comunque dentro `data/` (protetta da git e dall'accesso web) e va copiato altrove
per una conservazione più sicura.

## Sicurezza

Pensata per uso **locale/LAN**, senza autenticazione: chiunque raggiunga l'URL può leggere e
modificare i dati. Non esporre questa app direttamente su Internet senza aggiungere un livello di
autenticazione.

## Note di rilascio

Lo storico delle modifiche è consultabile nell'app stessa tramite l'icona 📝 accanto al nome, oppure
direttamente in `changelog.php`.

## Roadmap e limiti noti

- **Nessuna autenticazione**: chiunque raggiunga l'URL può leggere/modificare/cancellare i dati (vedi
  [Sicurezza](#sicurezza)). Un sistema di login è previsto per una versione futura, prima di esporre
  l'app oltre la rete locale.
- **Pensata per un singolo nucleo familiare/utenza**: non gestisce più abitazioni o più utenti con dati
  separati.
- **Nessuna suite di test automatizzati**: le verifiche vengono fatte manualmente prima di ogni
  rilascio (vedi `changelog.php`).
- **Compatibilità**: testato solo su Fedora 44; su altre distribuzioni potrebbero servire aggiustamenti
  a pacchetti, percorsi e configurazione SELinux/Apache.

## Licenza

Copyright riservato — vedi [`LICENSE`](LICENSE). Il codice è pubblico a scopo di consultazione, non è
concesso il riutilizzo senza autorizzazione.
