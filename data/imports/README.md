# Importazione automatica bollette

La struttura è organizzata per utenza e anno:

```text
data/imports/
└── luce/
    └── 2026/
        ├── da_elaborare/  # inserire qui i PDF Octopus
        ├── importate/     # PDF spostati dopo un inserimento riuscito
        └── errori/        # rapporti relativi ai documenti non riconosciuti
```

L'interfaccia consigliata è **📄 Importa PDF** nella dashboard Luce: carica il documento, mostra le
differenze rispetto alla bolletta esistente e richiede conferma prima di aggiornare i dati. Il nome
del PDF viene salvato separatamente dalle note.

Per preparare automaticamente un altro anno:

```bash
php app/import_octopus.php --year=2027 --prepare
```

Per analizzare i PDF senza scrivere nel database:

```bash
php app/import_octopus.php --year=2026
```

Per confermare l'inserimento delle bollette riconosciute:

```bash
php app/import_octopus.php --year=2026 --commit
```

Il comando richiede `pdftotext` (pacchetto `poppler-utils` su Fedora). I PDF e i rapporti di errore
sono esclusi da Git. La cartella `data/` deve rimanere non accessibile dal web.

Se per lo stesso mese esiste una bolletta contrassegnata come stimata, l'importazione reale la
aggiorna mantenendo l'ID e le metriche manuali non presenti nel PDF. Una bolletta già reale non viene
mai sovrascritta automaticamente.
