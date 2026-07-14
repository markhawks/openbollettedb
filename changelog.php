<?php
declare(strict_types=1);

/* Storico versioni: aggiungere una nuova voce in cima ad ogni rilascio */
$release = [
  [
    'version' => 'v1.1',
    'date'    => '14/07/2026',
    'changes' => [
      'Corretto l\'ordine della colonna mese nelle tabelle Luce, Gas e Luce + Gas: ora Gennaio è 01 e Dicembre è 12 (prima la numerazione seguiva l\'ordine di visualizzazione, decrescente).',
      'Pagina Gas: aggiunta la crescita/decrescita di consumo (Smc) e spesa rispetto all\'anno precedente, sia accanto al titolo di ogni riquadro annuale sia nel Riepilogo Annuale, con percentuale, importo/quantità risparmiati o aumentati e anno di confronto.',
      'Aggiunta la pagina "Note di rilascio" (changelog), raggiungibile dall\'icona 📝 vicino al nome dell\'app in ogni pagina, aggiornata ad ogni modifica introdotta.',
      'Pagina Luce: aggiunta la stessa crescita/decrescita di consumo (kWh) e spesa rispetto all\'anno precedente, accanto al titolo di ogni riquadro annuale e nel Riepilogo Annuale.',
      'Sicurezza: bloccato l\'accesso diretto al file del database SQLite e la navigazione delle cartelle del progetto via browser (in precedenza scaricabile direttamente).',
      'Sicurezza: rimosso il debug (`display_errors`) attivo per errore in `new_bill.php`; gli errori restano comunque registrati nel log del server.',
      'Sicurezza: aggiunta protezione contro CSRF sull\'eliminazione delle bollette (prima bastava un link diretto).',
      'Aggiunto un README.md con istruzioni di avvio e panoramica del progetto, in preparazione alla pubblicazione su repository privato.',
      'Corretto un bug latente nella pagina TARI: un numero avviso composto solo da cifre veniva salvato da SQLite come numero anziché testo, causando un errore fatale in visualizzazione.',
      'Aggiunto `app/seed_demo.php`: genera bollette di esempio (dati inventati) per tutte le utenze alla prima installazione, senza mai toccare un database che contiene già bollette reali.',
      'Aggiunto un pulsante "🗑️ Svuota anno" su ogni riquadro annuale di ogni dashboard, per eliminare in un colpo solo le bollette demo (o comunque quelle di un anno) e ripartire con i propri dati.',
      'Pubblicato il repository su GitHub (github.com/maccumaccu/openbollettedb).',
      'README: aggiunta la guida di installazione su Fedora 44 (pacchetti, servizi Apache/php-fpm, firewalld, permessi, SELinux, configurazione Apache per data/) e una sezione di backup del database.',
      'Aggiunti LICENSE (tutti i diritti riservati), badge informativi e una sezione "Roadmap e limiti noti" nel README.',
    ],
  ],
  [
    'version' => 'v1.0',
    'date'    => '05/07/2026',
    'changes' => [
      'Nuova pagina Bonifica: gestione avvisi annuali del Consorzio di bonifica, con segnalazione ⚠️ per pagamenti in ritardo o mancanti e calcolo della crescita % rispetto all\'anno precedente.',
      'Sistema di bollette stimate per Luce: badge 📊 "Stima" in tabella, linee tratteggiate nel grafico e riepilogo separato tra importi reali e stimati.',
      'Nuovo footer con nome applicazione, anno corrente e conteggio delle bollette registrate.',
      'Restyling dell\'header: logo, numero di versione e data di rilascio; icone di ogni dashboard allineate a quelle del menu di navigazione.',
    ],
  ],
];
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Note di rilascio – OpenBolletteDB</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/css/index_style.css">
  <style>
    .changelog-entry { position: relative; padding-left: 20px; border-left: 3px solid #eff6ff; margin-bottom: 24px; }
    .changelog-entry:last-child { margin-bottom: 0; }
    .changelog-entry::before {
      content: '';
      position: absolute;
      left: -7px; top: 4px;
      width: 11px; height: 11px;
      border-radius: 50%;
      background: #3b82f6;
    }
    .changelog-head { display: flex; align-items: baseline; gap: 10px; margin-bottom: 8px; }
    .changelog-version { font-size: 1.1rem; font-weight: 800; color: #1b1f2a; }
    .changelog-date { font-size: 0.85rem; color: #667; }
    .changelog-entry ul { margin: 0; padding-left: 18px; }
    .changelog-entry li { margin-bottom: 6px; line-height: 1.5; }
  </style>
</head>
<body>

<div class="container">

  <header class="topbar">
    <div>
      <h1>📝 Note di rilascio</h1>
      <div class="sub">Tutte le novità introdotte in OpenBolletteDB, versione per versione</div>
    </div>
  </header>

  <section class="card">
    <?php foreach ($release as $r): ?>
      <div class="changelog-entry">
        <div class="changelog-head">
          <span class="changelog-version"><?= htmlspecialchars($r['version']) ?></span>
          <span class="changelog-date"><?= htmlspecialchars($r['date']) ?></span>
        </div>
        <ul>
          <?php foreach ($r['changes'] as $c): ?>
            <li><?= htmlspecialchars($c) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </section>

</div>
</body>
</html>
