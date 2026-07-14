/**
 * Conferma rafforzata per il pulsante "Svuota anno": l'utente deve digitare
 * l'anno esatto (non basta un click su OK) per evitare cancellazioni accidentali.
 */
function confirmResetAnno(utilityLabel, year) {
  var codice = window.prompt(
    'Stai per eliminare TUTTE le bollette ' + utilityLabel + ' del ' + year + '.\n' +
    'Operazione IRREVERSIBILE.\n\n' +
    'Per confermare, digita l\'anno "' + year + '" e premi OK:'
  );

  if (codice === null) {
    return false; // annullato dall'utente
  }
  if (codice.trim() !== String(year)) {
    window.alert('Codice non corretto: operazione annullata.');
    return false;
  }
  return true;
}
