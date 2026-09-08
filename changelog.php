<?php
declare(strict_types=1);

/* Storico versioni: aggiungere una nuova voce in cima ad ogni rilascio */
$release = [
  [
    'version' => 'v1.6.0',
    'date'    => '08/09/2026',
    'changes' => [
      'OpenBolletteDB è ora software libero e open source sotto licenza GNU AGPL v3 o successiva, con obbligo di mantenere disponibile il sorgente anche per le versioni modificate offerte tramite rete.',
      'Documentazione completamente aggiornata e disponibile in italiano e inglese, con panoramica delle funzioni, installazione, sicurezza, backup, limiti noti e licenza.',
      'Dashboard e moduli TARI aggiornati per distinguere importo lordo, credito/rimborso utilizzato e totale effettivamente da pagare, consentendo di registrare correttamente avvisi azzerati da crediti precedenti.',
      'Nuova e modifica TARI ora usano esclusivamente anno e trimestre: il selettore mensile è nascosto, Dal/Al sono automatici e il controllo duplicati opera sul trimestre completo.',
      'Il credito/rimborso TARI si inserisce ora come valore positivo e viene sottratto automaticamente dall\'importo lordo per calcolare il totale da pagare, evitando errori di segno.',
      'Aggiunti codice cliente e codice utenza alle bollette TARI, salvati come testo e mostrati nello storico subito dopo l\'anno per verificarne eventuali variazioni nel tempo.',
      'Aggiunto il numero di svuotature trimestrali dell\'indifferenziato da 20 litri: la dashboard TARI le rappresenta con piccoli bidoncini grigi e mostra un bidoncino trasparente quando il valore è zero.',
      'La tabella TARI ora assegna alle colonne una larghezza dinamica e abilita lo scorrimento orizzontale sui display più stretti, evitando che i nuovi dati risultino compressi.',
      'Sui monitor di grandi dimensioni la card dello storico TARI si allarga dinamicamente in base alle colonne, fino al limite dello schermo, senza occupare spazio inutilmente.',
      'Aggiunta la modalità “Bolletta stimata” alla TARI: campo nei moduli, badge nello storico, riga evidenziata e separazione degli importi reali e stimati nel riepilogo e nel grafico annuale.',
      'La percentuale di raccolta differenziata TARI è ora in grassetto e colorata per fascia tariffaria: rosso sotto il 25%, giallo dal 25% a meno dell\'80% e verde dall\'80%; al passaggio del mouse viene mostrata la legenda completa.',
      'Le etichette identificative TARI sono state ampliate in “Codice utente/cliente” e “Codice utenza/Contratto n.” per rispecchiare le diverse denominazioni presenti negli avvisi.',
      'La dicitura TARI “Numero avviso” è stata ampliata in “Numero avviso/Fattura n.” per adattarsi ai diversi documenti emessi dai gestori.',
      'Tutte le date della pagina Nuova bolletta sono ora visualizzate e inserite stabilmente nel formato italiano gg/mm/aaaa, mantenendo internamente il formato ISO necessario al database.',
      'Il formato italiano gg/mm/aaaa è stato esteso anche a tutti i campi data della pagina Modifica bolletta, con conversione trasparente nel formato tecnico del database.',
      'Nei moduli Nuova e Modifica TARI è ora possibile compilare Codice Utente/Cliente e Codice Utenza/Contratto n. richiamando con un pulsante il rispettivo valore più recente.',
      'Ripristinato il calendario nei campi data di Nuova e Modifica bolletta tramite un selettore compatibile con la visualizzazione italiana gg/mm/aaaa, compresi i campi aggiunti dinamicamente.',
      'Il precedente campo “Data immissione” rappresenta ora la “Data scadenza”, cioè il termine massimo di pagamento; non viene più compilato automaticamente con la data odierna e nella TARI è mostrato accanto alla Data fattura.',
      'Nello storico TARI la colonna Data scadenza è stata spostata subito dopo l\'importo Da pagare.',
      'I riquadri annuali e il riepilogo TARI mostrano ora totale annuo, differenza in euro e variazione percentuale rispetto all\'anno precedente; il confronto è segnalato come non disponibile quando mancano dati validi.',
    ],
  ],
  [
    'version' => 'v1.5.1',
    'date'    => '05/09/2026',
    'changes' => [
      'Dashboard Acqua: ripristinato il raggruppamento coerente per data di emissione/immissione in tabella, riepilogo annuale e grafico.',
      'Uniformata la visualizzazione delle letture del contatore: gli zeri decimali non significativi vengono rimossi, conservando gli eventuali decimali reali.',
      'Le date future del periodo per la prossima autolettura sono ora evidenziate in rosso e accompagnate da una campanella; le date trascorse restano neutre.',
      'Rinnovata la dashboard Acqua: consumo rilevato o stimato, conguaglio e quantità addebitata sono separati per ogni bolletta e riepilogati per anno.',
      'La nuova visualizzazione dei consumi Acqua, inizialmente proposta come anteprima Acqua 2.0, è diventata la dashboard ufficiale e ha sostituito la precedente.',
    ],
  ],
  [
    'version' => 'v1.5',
    'date'    => '05/09/2026',
    'changes' => [
      'Sicurezza: tutte le dashboard e le pagine operative verificano ora l\'autenticazione anche quando vengono richiamate direttamente; le operazioni di creazione, modifica, eliminazione, svuotamento anno e logout accettano esclusivamente richieste POST protette da token CSRF.',
      'Sicurezza delle sessioni rafforzata: cookie HttpOnly, SameSite e Secure su HTTPS, rigenerazione dell\'identificativo, scadenza dopo 30 minuti di inattività e revoca immediata delle sessioni dopo cambio password, reset, eliminazione dell\'utente o modifica del ruolo.',
      'Aggiunta la limitazione dei tentativi di accesso: dopo cinque credenziali errate la coppia utente/indirizzo IP viene bloccata temporaneamente per 15 minuti.',
      'Protetti i componenti interni e il database dall\'accesso via web; migrazioni e generazione dei dati dimostrativi sono ora eseguibili soltanto da riga di comando. Aggiornate anche le istruzioni sui permessi sicuri della cartella dati e dei file SQLite.',
      'Centralizzata e rafforzata la validazione dei dati delle bollette: controllo di date, intervalli, importi, valori numerici, lunghezze e campi specifici per utenza; i messaggi di errore non espongono più dettagli interni del database.',
      'Il database impedisce metriche duplicate e bollette duplicate. La colonna dei valori delle metriche è stata migrata da REAL a TEXT per conservare correttamente date, codici e identificativi con zeri iniziali senza alterare i calcoli numerici.',
      'Rimossi i limiti temporali fissi: è possibile inserire bollette precedenti al 2021 e i selettori degli anni si adattano ai dati disponibili.',
      'Dashboard Luce + Gas: vengono inclusi anche gli anni contenenti soltanto bollette Gas e, al caricamento del grafico, sono sempre visibili tutti gli anni disponibili.',
      'Corrette le medie mensili di Luce e Gas: vengono calcolate sui mesi realmente presenti anziché dividere sempre il totale annuale per dodici.',
      'Dashboard Acqua: le bollette sono attribuite all\'anno del periodo di consumo, il numero di giorni comprende entrambe le date estreme e il consumo medio giornaliero non perde più un giorno.',
      'Bolletta Acqua: ripristinati e resi chiaramente visibili i campi numerici delle letture in metri cubi nella pagina di modifica.',
      'Bolletta Acqua: aggiunto il periodo indicato in bolletta per effettuare la prossima lettura, modificabile nei moduli di inserimento e modifica e mostrato in una nuova colonna della dashboard.',
      'Aggiornato Chart.js a una versione fissata e verificata tramite controllo di integrità, evitando dipendenze caricate da URL senza versione.',
      'Schermata di login aggiornata con numero e data della versione; il riquadro è ora più ampio e responsivo, con margini laterali garantiti e spazio sufficiente per futuri ampliamenti del testo.',
    ],
  ],
  [
    'version' => 'v1.4',
    'date'    => '16/08/2026',
    'changes' => [
      'Pagina Acqua: aggiunta la colonna "m³/giorno" in tabella, con il consumo medio giornaliero di ogni bolletta (consumo netto diviso i giorni del periodo), per confrontare a colpo d\'occhio quanto un trimestre sfora rispetto agli altri.',
      'Pagina Acqua: aggiunta la colonna "Storico letture", a destra di "Letture". Da "Nuova bolletta" e "Modifica" si possono ora annotare a mano più letture intermedie del contatore (data + m³) durante il periodo, per seguire l\'andamento del consumo tra una bolletta e l\'altra.',
      'Pagina Acqua: aggiunta l\'opzione "📊 Bolletta stimata (previsione)" (come già presente per Luce): badge in tabella, riga in corsivo, riepilogo annuale con spesa reale/stimata separata, e nel grafico "Consumo Acqua per Anno" il consumo stimato appare impilato con un colore diverso rispetto a quello reale.',
    ],
  ],
  [
    'version' => 'v1.3',
    'date'    => '08/08/2026',
    'changes' => [
      'Aggiunta la pagina "Gestione utenti" (account.php), raggiungibile dall\'icona ⚙️ Utente nel menu in alto: ogni utente può cambiare il proprio nome e la propria password senza intervenire sul database.',
      'Introdotti i ruoli utente: un amministratore può creare altri utenti legati alle stesse bollette, assegnare il ruolo "Amministratore" (lettura e scrittura) o "Sola lettura", reimpostare la password di un altro utente ed eliminarlo. Per evitare di restare bloccati fuori dall\'app, un amministratore non può modificare o eliminare il proprio account da questa sezione (si usa "Il mio profilo").',
      'Gli utenti con ruolo "Sola lettura" non vedono più i pulsanti "+ Nuova bolletta", "✏️ Modifica", "🗑️ Elimina" e "🗑️ Svuota anno" nelle dashboard; l\'accesso a new_bill.php, edit_bill.php, delete_bill.php e reset_year.php è comunque bloccato anche lato server per chi non è amministratore, non solo nascosto in pagina.',
      'Corretto il menu in alto: i pulsanti (Luce, Gas, Acqua, Tari, Bonifica, ⚙️ Utente, 🔓 Esci) non vanno più a capo aggiungendo nuove voci; pagina allargata per ospitarli su una sola riga.',
      'Rinominata la voce di menu "⚙️ Utenza" in "⚙️ Utente".',
      'Corretta la codifica dei caratteri di index.html (mancava il charset UTF-8, i puntini di sospensione potevano apparire come simboli corrotti nel redirect verso index.php).',
    ],
  ],
  [
    'version' => 'v1.2',
    'date'    => '18/07/2026',
    'changes' => [
      'Aggiunta una pagina di accesso (login.php): l\'app ora richiede l\'autenticazione prima di poter consultare o modificare le bollette (in precedenza chiunque raggiungesse l\'URL poteva farlo liberamente).',
      'Creato un utente predefinito "admin" (password iniziale "admin2026", da cambiare) come unica utenza disponibile: la gestione multi-utenza non è ancora implementata, quindi il menu "Utenza" nella schermata di login mostra solo "Default".',
      'Aggiunto il pulsante "🔓 Esci" nel menu in alto per terminare la sessione.',
    ],
  ],
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
      'Pubblicato il repository su GitHub (github.com/markhawks/openbollettedb).',
      'Rinominato l\'account GitHub da "maccumaccu" a "markhawks" (il vecchio URL del repository resta comunque reindirizzato automaticamente da GitHub).',
      'README: aggiunta la guida di installazione su Fedora 44 (pacchetti, servizi Apache/php-fpm, firewalld, permessi, SELinux, configurazione Apache per data/) e una sezione di backup del database.',
      'Aggiunti LICENSE (tutti i diritti riservati), badge informativi e una sezione "Roadmap e limiti noti" nel README.',
      'Rafforzata la conferma del pulsante "🗑️ Svuota anno": ora bisogna digitare l\'anno esatto in una finestra di conferma (non basta più un click su OK), per evitare cancellazioni accidentali.',
      'Aggiunta un\'icona (favicon 🧾) mostrata nella scheda del browser, prima assente.',
      'Corretto un bug nella pagina Gas: conteneva un secondo documento HTML annidato (doppio head/body e Chart.js caricato due volte), invisibile a schermo ma non valido.',
      'Aggiunti screenshot (Luce, Gas, Acqua, form Nuova bolletta) nel README, generati con dati demo.',
      'Corretto il link "Acqua" del menu (usava una maiuscola non riconosciuta dal routing) e aggiunto un case esplicito per acqua in index.php, invece di affidarsi implicitamente al ramo "default".',
      'Uniformata la voce "TARI" del menu in alto a "Tari", coerente con lo stile delle altre etichette (Luce, Gas, Acqua, Bonifica).',
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
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
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
