<?php
declare(strict_types=1);

function octopusParseItalianDate(string $value): ?string
{
    $value = trim($value);
    if (!preg_match('#^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$#', $value, $match)) {
        return null;
    }

    $day = (int)$match[1];
    $month = (int)$match[2];
    $year = (int)$match[3];
    if (!checkdate($month, $day, $year)) return null;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function octopusParseDecimal(string $value): ?float
{
    $normalized = preg_replace('/[^0-9,.-]/u', '', trim($value));
    if ($normalized === null || $normalized === '') return null;

    if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
        $normalized = str_replace('.', '', $normalized);
    } elseif (!str_contains($normalized, ',') && preg_match('/^\d{1,3}(?:\.\d{3})+$/', $normalized)) {
        $normalized = str_replace('.', '', $normalized);
    }
    $normalized = str_replace(',', '.', $normalized);
    return is_numeric($normalized) ? (float)$normalized : null;
}

function octopusFirstMatch(string $text, array $patterns): ?string
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $match)) {
            return trim((string)$match[1]);
        }
    }
    return null;
}

/**
 * Estrae solo i dati sufficientemente stabili per creare una bolletta Luce.
 * Le espressioni sono volutamente conservative: un documento ambiguo deve
 * essere corretto manualmente, non importato con valori potenzialmente errati.
 */
function parseOctopusElectricityText(string $text, string $sourceName): array
{
    $compact = preg_replace('/[\t ]+/u', ' ', str_replace("\r", '', $text)) ?? $text;
    $date = '(\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4})';

    $periodStart = null;
    $periodEnd = null;
    $periodPatterns = [
        '/periodo\s+(?:di\s+)?(?:fornitura|fatturazione|riferimento)\s*:?\s*' . $date . '\s*(?:al|–|—|-)\s*' . $date . '/iu',
        '/dal\s+' . $date . '\s+al\s+' . $date . '/iu',
    ];
    foreach ($periodPatterns as $pattern) {
        if (preg_match($pattern, $compact, $match)) {
            $periodStart = octopusParseItalianDate($match[1]);
            $periodEnd = octopusParseItalianDate($match[2]);
            break;
        }
    }

    $dueRaw = octopusFirstMatch($compact, [
        '/(?:data\s+di\s+)?scadenza(?:\s+fattura|\s+pagamento)?\s*:?\s*' . $date . '/iu',
        '/da\s+pagare\s+entro\s+il\s+' . $date . '/iu',
        '/\bentro\s+il\s+' . $date . '/iu',
    ]);
    $invoiceDateRaw = octopusFirstMatch($compact, [
        '/data\s+fattura\s*:?\s*' . $date . '/iu',
    ]);
    $amountRaw = octopusFirstMatch($compact, [
        '/totale\s+(?:fattura\s+)?da\s+pagare\s*:?\s*(?:€\s*)?([0-9.]+,[0-9]{2})/iu',
        '/importo\s+totale\s*:?\s*(?:€\s*)?([0-9.]+,[0-9]{2})/iu',
    ]);
    $kwhRaw = octopusFirstMatch($compact, [
        '/consumo\s+(?:totale|fatturato|del\s+periodo)\s*:?\s*([0-9.]+(?:,[0-9]+)?)\s*kWh/iu',
        '/totale\s+consumi\s*:?\s*([0-9.]+(?:,[0-9]+)?)\s*kWh/iu',
    ]);
    $energyDetail = [];
    preg_match('/^\s*Energia\s+([0-9.]+(?:,[0-9]+)?)\s+([0-9.]+,[0-9]+)\s*€\/kWh\s+([0-9.]+,[0-9]{2})\s*€/imu', $compact, $energyDetail);
    $lossDetail = [];
    preg_match('/^\s*Perdite\s+([0-9.]+(?:,[0-9]+)?)\s+([0-9.]+,[0-9]+)\s*€\/kWh\s+([0-9.]+,[0-9]{2})\s*€/imu', $compact, $lossDetail);

    $energyKwh = isset($energyDetail[1]) ? octopusParseDecimal($energyDetail[1]) : null;
    $energyUnitPrice = isset($energyDetail[2]) ? octopusParseDecimal($energyDetail[2]) : null;
    $lossesKwh = isset($lossDetail[1]) ? octopusParseDecimal($lossDetail[1]) : null;
    $lossesCost = isset($lossDetail[3]) ? octopusParseDecimal($lossDetail[3]) : null;
    $lossesMode = null;
    $billedKwh = $kwhRaw !== null ? octopusParseDecimal($kwhRaw) : null;
    if ($lossesKwh !== null) {
        $includedInConsumption = $energyKwh !== null && $billedKwh !== null
            && abs($billedKwh - ($energyKwh + $lossesKwh)) < 0.51;
        $lossesMode = $includedInConsumption ? 'incluse_consumo' : 'separate';
    } elseif ($energyKwh !== null && $energyUnitPrice !== null
        && preg_match('/prezzo_fisso\/kWh\s*\(con\s+perdite\)/iu', $compact)) {
        $lossesKwh = $energyKwh * 0.10;
        $lossesCost = $energyKwh * ($energyUnitPrice / 11.0);
        $lossesMode = 'incluse_prezzo';
    }
    $physicalKwh = $energyKwh ?? $billedKwh;
    $chargedKwh = $physicalKwh;
    if ($physicalKwh !== null && $lossesKwh !== null && $lossesMode !== 'incluse_prezzo') {
        $chargedKwh = $physicalKwh + $lossesKwh;
    }
    $invoiceNumber = octopusFirstMatch($compact, [
        '/numero\s+fattura\s+elettronica\s+valida\s+ai\s+fini\s+fiscali\s*:?\s*([A-Z0-9][A-Z0-9._\/-]+)/iu',
        '/(?:fattura|documento)\s+(?:n\.?|numero)\s*:?\s*([A-Z0-9][A-Z0-9._\/-]+)/iu',
    ]);
    $pod = octopusFirstMatch($compact, [
        '/\bPOD\s*:?\s*(IT[0-9A-Z]{12,16})\b/iu',
    ]);
    $canoneRaiRaw = octopusFirstMatch($compact, [
        '/canone\s+di\s+abbonamento\s+alla\s+televisione\s+per\s+uso\s+privato\s+(?:€\s*)?([0-9.]+,[0-9]{2})\b/iu',
        '/canone\s+RAI\s*:?\s*(?:€\s*)?([0-9.]+,[0-9]{2})\b/iu',
    ]);

    $result = [
        'provider' => preg_match('/octopus\s+energy/iu', $compact) ? 'Octopus Energy' : null,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'due_date' => $dueRaw !== null ? octopusParseItalianDate($dueRaw) : null,
        'invoice_date' => $invoiceDateRaw !== null ? octopusParseItalianDate($invoiceDateRaw) : null,
        'amount_total' => $amountRaw !== null ? octopusParseDecimal($amountRaw) : null,
        // kwh rappresenta il consumo fisico; billed_kwh la quantità sulla
        // quale sono applicate le componenti che includono le perdite.
        'kwh' => $physicalKwh,
        'billed_kwh' => $chargedKwh,
        'physical_kwh' => $physicalKwh,
        'grid_losses_kwh' => $lossesKwh,
        'grid_losses_cost' => $lossesCost,
        'grid_losses_mode' => $lossesMode,
        'invoice_number' => $invoiceNumber,
        'pod' => $pod,
        // Facoltativo: non tutte le bollette contengono una rata del Canone RAI.
        'canone_rai' => $canoneRaiRaw !== null ? octopusParseDecimal($canoneRaiRaw) : null,
        'source_name' => $sourceName,
    ];

    $errors = [];
    foreach ([
        'provider' => 'fornitore Octopus Energy',
        'period_start' => 'data iniziale del periodo',
        'period_end' => 'data finale del periodo',
        'due_date' => 'scadenza della fattura',
        'amount_total' => 'totale da pagare',
        'kwh' => 'consumo totale in kWh',
        'invoice_number' => 'numero della fattura',
    ] as $key => $label) {
        if ($result[$key] === null) $errors[] = "Non riconosciuto: $label.";
    }
    if ($periodStart !== null && $periodEnd !== null && $periodEnd < $periodStart) {
        $errors[] = 'Il periodo estratto ha una data finale precedente a quella iniziale.';
    }

    $result['errors'] = $errors;
    return $result;
}
