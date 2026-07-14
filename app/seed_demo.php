<?php
declare(strict_types=1);

/**
 * Popola il database con bollette di esempio (dati interamente inventati),
 * cosi' che una nuova installazione non parta vuota.
 * Si ferma senza toccare nulla se il database contiene gia' delle bollette,
 * per non mescolare mai dati demo con dati reali.
 *
 * Uso: php app/seed_demo.php   (dopo php app/migrate.php)
 */

require __DIR__ . '/db.php';

$pdo = db();

$existing = (int)$pdo->query('SELECT COUNT(*) FROM bills')->fetchColumn();
if ($existing > 0) {
    fwrite(STDERR, "Il database contiene gia' $existing bollette: seed demo annullato per non mescolare dati reali e fittizi.\n");
    exit(1);
}

function utilityId(PDO $pdo, string $code): int
{
    $stmt = $pdo->prepare('SELECT id FROM utilities WHERE code = ?');
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new RuntimeException("Utility '$code' non trovata: esegui prima php app/migrate.php");
    }
    return (int)$id;
}

function insertBill(
    PDO $pdo,
    int $utilityId,
    string $issueDate,
    string $periodStart,
    string $periodEnd,
    float $amount,
    ?string $notes,
    array $metrics
): void {
    $stmt = $pdo->prepare("
        INSERT INTO bills (utility_id, issue_date, period_start, period_end, amount_total, notes)
        VALUES (?,?,?,?,?,?)
    ");
    $stmt->execute([$utilityId, $issueDate, $periodStart, $periodEnd, $amount, $notes]);
    $billId = (int)$pdo->lastInsertId();

    if ($metrics) {
        $ins = $pdo->prepare('INSERT INTO bill_metrics (bill_id, key, value, unit) VALUES (?,?,?,?)');
        foreach ($metrics as [$key, $value, $unit]) {
            $ins->execute([$billId, $key, $value, $unit]);
        }
    }
}

$yearPrev  = (int)date('Y') - 1;
$yearCurr  = (int)date('Y');
$monthCurr = (int)date('n');

$pdo->beginTransaction();
try {
    /* ---------------- LUCE ---------------- */
    $luceId = utilityId($pdo, 'luce');
    $kwhPerMese = [1 => 260, 2 => 240, 3 => 200, 4 => 160, 5 => 140, 6 => 150, 7 => 170, 8 => 160, 9 => 140, 10 => 170, 11 => 210, 12 => 250];
    $energyPrice = 0.13;
    $commFee = 8.50;

    foreach ([$yearPrev, $yearCurr] as $year) {
        $maxMonth = ($year === $yearCurr) ? min(12, $monthCurr + 2) : 12;
        for ($month = 1; $month <= $maxMonth; $month++) {
            $isStima = ($year === $yearCurr && $month > $monthCurr);
            $kwh = $kwhPerMese[$month];
            $canone = ($month <= 10) ? 9.00 : 0.00;
            $amount = round($kwh * $energyPrice + $commFee + $canone, 2);
            $periodStart = sprintf('%04d-%02d-01', $year, $month);
            $periodEnd = date('Y-m-t', (int)strtotime($periodStart));

            $metrics = [
                ['kwh', $kwh, 'kWh'],
                ['energy_price', $energyPrice, 'EUR/kWh'],
                ['commercial_fee', $commFee, 'EUR/mese'],
            ];
            if ($canone > 0) {
                $metrics[] = ['canone_rai', $canone, 'EUR'];
            }
            if ($isStima) {
                $metrics[] = ['stima', 1, 'bool'];
            }

            insertBill($pdo, $luceId, $periodEnd, $periodStart, $periodEnd, $amount,
                $isStima ? 'Bolletta stimata di esempio' : 'Bolletta di esempio', $metrics);
        }
    }

    /* ---------------- GAS ---------------- */
    $gasId = utilityId($pdo, 'gas');
    $smcPerMese = [1 => 140, 2 => 130, 3 => 100, 4 => 60, 5 => 25, 6 => 10, 7 => 6, 8 => 5, 9 => 8, 10 => 35, 11 => 90, 12 => 130];
    $prezzoSmc = 0.85;
    $lettura = 1200.0;

    foreach ([$yearPrev, $yearCurr] as $year) {
        $maxMonth = ($year === $yearCurr) ? $monthCurr : 12;
        for ($month = 1; $month <= $maxMonth; $month++) {
            $smc = $smcPerMese[$month];
            $letturaIni = $lettura;
            $letturaFin = round($lettura + $smc, 2);
            $lettura = $letturaFin;
            $amount = round($smc * $prezzoSmc, 2);
            $periodStart = sprintf('%04d-%02d-01', $year, $month);
            $periodEnd = date('Y-m-t', (int)strtotime($periodStart));

            insertBill($pdo, $gasId, $periodEnd, $periodStart, $periodEnd, $amount, 'Bolletta di esempio', [
                ['smc', $smc, 'Smc'],
                ['lettura_ini', $letturaIni, 'num'],
                ['lettura_fin', $letturaFin, 'num'],
            ]);
        }
    }

    /* ---------------- ACQUA (semestrale) ---------------- */
    $acquaId = utilityId($pdo, 'acqua');
    $letturaAcqua = 500.0;

    foreach ([$yearPrev, $yearCurr] as $year) {
        foreach ([1, 2] as $semestre) {
            if ($year === $yearCurr && ($semestre === 2 || $monthCurr < 6)) {
                continue; // evitiamo periodi non ancora conclusi
            }
            $mcConsumo = $semestre === 1 ? 50.0 : 40.0;
            $mcStart = $letturaAcqua;
            $mcEnd = $letturaAcqua + $mcConsumo;
            $letturaAcqua = $mcEnd;
            $amount = round($mcConsumo * 2.10, 2);

            $periodStart = sprintf('%04d-%02d-01', $year, $semestre === 1 ? 1 : 7);
            $periodEnd = $semestre === 1 ? "$year-06-30" : "$year-12-31";

            insertBill($pdo, $acquaId, $periodEnd, $periodStart, $periodEnd, $amount, 'Bolletta di esempio', [
                ['mc_start', $mcStart, 'm3'],
                ['mc_end', $mcEnd, 'm3'],
                ['consumo_mc', $mcConsumo, 'm3'],
            ]);
        }
    }

    /* ---------------- TARI (trimestrale) ---------------- */
    $tariId = utilityId($pdo, 'tari');
    $trimestri = [
        'Q1' => ['start' => '01-01', 'end' => '03-31', 'month' => 3],
        'Q2' => ['start' => '04-01', 'end' => '06-30', 'month' => 6],
        'Q3' => ['start' => '07-01', 'end' => '09-30', 'month' => 9],
        'Q4' => ['start' => '10-01', 'end' => '12-31', 'month' => 12],
    ];
    $numFattura = 1000;

    foreach ([$yearPrev, $yearCurr] as $year) {
        foreach ($trimestri as $q => $info) {
            if ($year === $yearCurr && $info['month'] > $monthCurr) {
                continue; // trimestre non ancora concluso
            }
            $periodStart = "$year-{$info['start']}";
            $periodEnd = "$year-{$info['end']}";
            $numFattura++;

            insertBill($pdo, $tariId, $periodEnd, $periodStart, $periodEnd, 62.50, 'Avviso di esempio', [
                ['data_fattura', $periodEnd, 'date'],
                ['periodo_competenza', "$q $year", 'text'],
                ['numero_fattura', (string)$numFattura, 'text'],
                ['tipo_avviso', 'Ordinaria', 'text'],
                ['raccolta_diff', 68.0, '%'],
            ]);
        }
    }

    /* ---------------- BONIFICA (annuale) ---------------- */
    $bonificaId = utilityId($pdo, 'bonifica');

    foreach ([$yearPrev, $yearCurr] as $year) {
        $scadenza = "$year-06-30";
        // nell'anno precedente il pagamento e' volutamente in ritardo,
        // per mostrare fin da subito l'avviso ⚠️ in dashboard
        $pagamento = ($year === $yearPrev) ? "$year-07-15" : "$year-06-20";

        insertBill($pdo, $bonificaId, $pagamento, "$year-01-01", "$year-12-31", 15.00,
            'Avviso di esempio – Consorzio di bonifica', [
                ['data_scadenza', $scadenza, 'date'],
                ['data_pagamento', $pagamento, 'date'],
            ]);
    }

    $pdo->commit();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM bills')->fetchColumn();
    echo "OK: dati demo generati ($total bollette, anni $yearPrev-$yearCurr).\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Errore durante la generazione dei dati demo: ' . $e->getMessage() . "\n");
    exit(1);
}
