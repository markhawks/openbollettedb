<?php
declare(strict_types=1);

function post_string(array $input, string $key, string $default = ''): string
{
    $value = $input[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
}

function valid_iso_date(string $date, bool $optional = false): bool
{
    if ($date === '') return $optional;
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function validate_bill_input(string $utilityCode, array $input): array
{
    $errors = [];
    foreach ($input as $key => $value) {
        if (!in_array($key, ['reading_date', 'reading_value'], true) && !is_scalar($value)) {
            $errors[] = 'Il formato della richiesta non è valido.';
        }
    }
    $start = post_string($input, 'period_start');
    $end = post_string($input, 'period_end');
    $issue = post_string($input, 'issue_date');
    $amount = post_string($input, 'amount_total');

    if (!valid_iso_date($start) || !valid_iso_date($end)) {
        $errors[] = 'Le date del periodo non sono valide.';
    } elseif ($end < $start) {
        $errors[] = 'La fine del periodo non può precedere l\'inizio.';
    }
    if (!valid_iso_date($issue, true)) $errors[] = 'La data di immissione non è valida.';
    if ($amount === '' || !is_numeric($amount) || (float)$amount < 0) {
        $errors[] = 'L\'importo deve essere un numero maggiore o uguale a zero.';
    }

    $nonNegativeByUtility = [
        'luce' => ['kwh', 'energy_price', 'commercial_fee', 'canone_rai'],
        'gas' => ['smc', 'lettura_ini', 'lettura_fin', 'commercial_fee'],
        'acqua' => ['mc_start', 'mc_end', 'consumo_mc'],
        'tari' => ['raccolta_diff'],
    ];
    foreach ($nonNegativeByUtility[$utilityCode] ?? [] as $key) {
        $value = post_string($input, $key);
        if ($value !== '' && (!is_numeric($value) || (float)$value < 0)) {
            $errors[] = "Il campo $key deve essere un numero maggiore o uguale a zero.";
        }
    }
    foreach (['extra_adjust', 'mc_conguaglio'] as $key) {
        $value = post_string($input, $key);
        if ($value !== '' && !is_numeric($value)) $errors[] = "Il campo $key deve essere numerico.";
    }

    if ($utilityCode === 'gas') {
        $initial = post_string($input, 'lettura_ini');
        $final = post_string($input, 'lettura_fin');
        if (is_numeric($initial) && is_numeric($final) && (float)$final < (float)$initial) {
            $errors[] = 'La lettura finale Gas non può essere inferiore a quella iniziale.';
        }
    }

    if ($utilityCode === 'acqua') {
        $initial = post_string($input, 'mc_start');
        $final = post_string($input, 'mc_end');
        if (is_numeric($initial) && is_numeric($final) && (float)$final < (float)$initial) {
            $errors[] = 'La lettura finale Acqua non può essere inferiore a quella iniziale.';
        }
        $nextStart = post_string($input, 'next_reading_start');
        $nextEnd = post_string($input, 'next_reading_end');
        if (($nextStart === '') !== ($nextEnd === '')) {
            $errors[] = 'Indica entrambe le date del periodo per la prossima lettura.';
        } elseif (!valid_iso_date($nextStart, true) || !valid_iso_date($nextEnd, true)) {
            $errors[] = 'Il periodo per la prossima lettura contiene una data non valida.';
        } elseif ($nextStart !== '' && $nextEnd < $nextStart) {
            $errors[] = 'La fine del periodo per la prossima lettura non può precedere l\'inizio.';
        }
        $dates = $input['reading_date'] ?? [];
        $values = $input['reading_value'] ?? [];
        if (!is_array($dates) || !is_array($values)) {
            $errors[] = 'Lo storico letture non è valido.';
        } else {
            $rows = max(count($dates), count($values));
            for ($i = 0; $i < $rows; $i++) {
                $date = isset($dates[$i]) && is_scalar($dates[$i]) ? trim((string)$dates[$i]) : '';
                $value = isset($values[$i]) && is_scalar($values[$i]) ? trim((string)$values[$i]) : '';
                if ($date === '' && $value === '') continue;
                if ($date === '' || $value === '') {
                    $errors[] = 'Ogni lettura intermedia deve avere data e valore.';
                    continue;
                }
                if (!valid_iso_date($date) || $date < $start || $date > $end) {
                    $errors[] = 'Le letture intermedie devono avere una data valida compresa nel periodo della bolletta.';
                }
                if (!is_numeric($value) || (float)$value < 0) {
                    $errors[] = 'Il valore di ogni lettura intermedia deve essere un numero maggiore o uguale a zero.';
                }
            }
        }
    }

    if ($utilityCode === 'tari') {
        $percentage = post_string($input, 'raccolta_diff');
        if (is_numeric($percentage) && ((float)$percentage < 0 || (float)$percentage > 100)) {
            $errors[] = 'La raccolta differenziata deve essere compresa tra 0 e 100.';
        }
        $quarter = post_string($input, 'periodo_competenza');
        if ($quarter !== '' && valid_iso_date($start) && valid_iso_date($end)) {
            if (!preg_match('/^Q([1-4])(?:\s+(\d{4}))?$/', $quarter, $match)) {
                $errors[] = 'Il trimestre TARI non è valido.';
            } else {
                $q = (int)$match[1];
                $year = substr($start, 0, 4);
                $firstMonth = (($q - 1) * 3) + 1;
                $expectedStart = sprintf('%s-%02d-01', $year, $firstMonth);
                $expectedEnd = date('Y-m-t', strtotime(sprintf('%s-%02d-01', $year, $firstMonth + 2)));
                if ((isset($match[2]) && $match[2] !== $year) || $start !== $expectedStart || $end !== $expectedEnd) {
                    $errors[] = 'Il trimestre TARI non coincide con le date del periodo.';
                }
            }
        }
        $noticeType = post_string($input, 'tipo_avviso', 'Ordinaria');
        if (!in_array($noticeType, ['Ordinaria', 'Rettifica', 'Conguaglio'], true)) {
            $errors[] = 'Il tipo di avviso TARI non è valido.';
        }
        if (!valid_iso_date(post_string($input, 'data_fattura'), true)) $errors[] = 'La data fattura TARI non è valida.';
    }

    if ($utilityCode === 'bonifica') {
        if (!valid_iso_date(post_string($input, 'data_scadenza'), true)) $errors[] = 'La data di scadenza non è valida.';
        if (!valid_iso_date(post_string($input, 'data_pagamento'), true)) $errors[] = 'La data di pagamento non è valida.';
    }

    return array_values(array_unique($errors));
}
