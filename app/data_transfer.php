<?php
declare(strict_types=1);

const TRANSFER_FORMAT = 'openbollettedb-transfer';
const TRANSFER_VERSION = 1;

function transferJson(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) throw new RuntimeException('Impossibile codificare il file di trasferimento.');
    return $json;
}

function transferValidDate(?string $value, bool $nullable = false): bool
{
    if ($nullable && ($value === null || $value === '')) return true;
    if ($value === null) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function transferExport(PDO $pdo, array $codes, ?int $yearFrom, ?int $yearTo): array
{
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
    if ($codes === []) throw new InvalidArgumentException('Seleziona almeno una tipologia di utenza.');
    $where = ['u.code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')'];
    $params = $codes;
    if ($yearFrom !== null) { $where[] = "CAST(strftime('%Y', b.period_start) AS INTEGER) >= ?"; $params[] = $yearFrom; }
    if ($yearTo !== null) { $where[] = "CAST(strftime('%Y', b.period_start) AS INTEGER) <= ?"; $params[] = $yearTo; }
    $stmt = $pdo->prepare('SELECT b.*, u.code utility_code, u.name utility_name FROM bills b JOIN utilities u ON u.id=b.utility_id WHERE ' . implode(' AND ', $where) . ' ORDER BY u.code,b.period_start,b.id');
    $stmt->execute($params);
    $metricStmt = $pdo->prepare('SELECT `key`,value,unit FROM bill_metrics WHERE bill_id=? ORDER BY `key`,id');
    $readingStmt = $pdo->prepare('SELECT reading_date,reading_value FROM bill_readings WHERE bill_id=? ORDER BY reading_date,id');
    $bills = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $metricStmt->execute([(int)$row['id']]);
        $readingStmt->execute([(int)$row['id']]);
        $bills[] = [
            'utility' => ['code'=>(string)$row['utility_code'], 'name'=>(string)$row['utility_name']],
            'period_start'=>(string)$row['period_start'], 'period_end'=>(string)$row['period_end'],
            'issue_date'=>$row['issue_date'] !== null && $row['issue_date'] !== '' ? (string)$row['issue_date'] : null,
            'amount_total'=>(float)$row['amount_total'], 'notes'=>$row['notes'] !== null ? (string)$row['notes'] : null,
            'metrics'=>array_map(static fn($m)=>['key'=>(string)$m['key'],'value'=>(string)$m['value'],'unit'=>$m['unit'] !== null ? (string)$m['unit'] : null], $metricStmt->fetchAll()),
            'readings'=>array_map(static fn($r)=>['reading_date'=>(string)$r['reading_date'],'reading_value'=>(float)$r['reading_value']], $readingStmt->fetchAll()),
        ];
    }
    $data = ['bills'=>$bills];
    return [
        'format'=>TRANSFER_FORMAT, 'format_version'=>TRANSFER_VERSION,
        'created_at'=>(new DateTimeImmutable())->format(DATE_ATOM), 'app_version'=>'v1.9.0',
        'filters'=>['utilities'=>$codes,'year_from'=>$yearFrom,'year_to'=>$yearTo],
        'data'=>$data, 'checksum_sha256'=>hash('sha256', transferJson($data)),
    ];
}

function transferValidate(array $bundle): array
{
    if (($bundle['format'] ?? '') !== TRANSFER_FORMAT || (int)($bundle['format_version'] ?? 0) !== TRANSFER_VERSION) throw new InvalidArgumentException('Formato di trasferimento non supportato.');
    $data = $bundle['data'] ?? null;
    if (!is_array($data) || !is_array($data['bills'] ?? null)) throw new InvalidArgumentException('Raccolta bollette mancante.');
    if (!hash_equals((string)($bundle['checksum_sha256'] ?? ''), hash('sha256', transferJson($data)))) throw new InvalidArgumentException('Checksum non valido: file incompleto o modificato.');
    if (count($data['bills']) > 10000) throw new InvalidArgumentException('Il file contiene troppe bollette.');
    $result = [];
    foreach ($data['bills'] as $i=>$bill) {
        if (!is_array($bill) || !is_array($bill['utility'] ?? null)) throw new InvalidArgumentException('Bolletta non valida alla riga ' . ($i+1) . '.');
        $code = trim((string)($bill['utility']['code'] ?? ''));
        $name = trim((string)($bill['utility']['name'] ?? $code));
        $start = (string)($bill['period_start'] ?? ''); $end = (string)($bill['period_end'] ?? '');
        $issue = isset($bill['issue_date']) ? (string)$bill['issue_date'] : null;
        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $code) || !transferValidDate($start) || !transferValidDate($end) || $end<$start || !transferValidDate($issue, true) || !is_numeric($bill['amount_total'] ?? null)) throw new InvalidArgumentException('Campi non validi nella bolletta ' . ($i+1) . '.');
        $metrics = [];
        foreach (($bill['metrics'] ?? []) as $m) {
            if (!is_array($m) || !is_scalar($m['value'] ?? null)) throw new InvalidArgumentException('Metrica non valida.');
            $key = trim((string)($m['key'] ?? ''));
            if ($key==='' || strlen($key)>100) throw new InvalidArgumentException('Chiave metrica non valida.');
            $metrics[$key] = ['key'=>$key,'value'=>(string)$m['value'],'unit'=>isset($m['unit']) ? (string)$m['unit'] : null];
        }
        $readings = [];
        foreach (($bill['readings'] ?? []) as $r) {
            if (!is_array($r) || !transferValidDate((string)($r['reading_date'] ?? '')) || !is_numeric($r['reading_value'] ?? null)) throw new InvalidArgumentException('Lettura non valida.');
            $readings[] = ['reading_date'=>(string)$r['reading_date'],'reading_value'=>(float)$r['reading_value']];
        }
        $result[] = ['utility'=>['code'=>$code,'name'=>$name ?: $code],'period_start'=>$start,'period_end'=>$end,'issue_date'=>$issue ?: null,'amount_total'=>(float)$bill['amount_total'],'notes'=>isset($bill['notes']) ? (string)$bill['notes'] : null,'metrics'=>array_values($metrics),'readings'=>$readings];
    }
    return $result;
}

function transferFindBill(PDO $pdo, int $utilityId, string $code, string $start, string $end): ?array
{
    if (in_array($code,['luce','gas'],true)) { $sql="SELECT * FROM bills WHERE utility_id=? AND strftime('%Y-%m',period_start)=strftime('%Y-%m',?) LIMIT 1"; $p=[$utilityId,$start]; }
    elseif ($code==='tari') { $sql="SELECT * FROM bills WHERE utility_id=? AND strftime('%Y',period_start)=strftime('%Y',?) AND CAST((CAST(strftime('%m',period_start) AS INTEGER)-1)/3 AS INTEGER)=CAST((CAST(strftime('%m',?) AS INTEGER)-1)/3 AS INTEGER) LIMIT 1"; $p=[$utilityId,$start,$start]; }
    elseif ($code==='bonifica') { $sql="SELECT * FROM bills WHERE utility_id=? AND strftime('%Y',period_start)=strftime('%Y',?) LIMIT 1"; $p=[$utilityId,$start]; }
    else { $sql='SELECT * FROM bills WHERE utility_id=? AND period_start=? AND period_end=? LIMIT 1'; $p=[$utilityId,$start,$end]; }
    $s=$pdo->prepare($sql); $s->execute($p); return $s->fetch() ?: null;
}

function transferFingerprint(PDO $pdo, array $bill, ?int $localId=null): string
{
    if ($localId!==null) {
        $m=$pdo->prepare('SELECT `key`,value,unit FROM bill_metrics WHERE bill_id=? ORDER BY `key`'); $m->execute([$localId]);
        $r=$pdo->prepare('SELECT reading_date,reading_value FROM bill_readings WHERE bill_id=? ORDER BY reading_date,id'); $r->execute([$localId]);
        $bill['metrics']=$m->fetchAll(); $bill['readings']=$r->fetchAll();
    }
    $metrics=$bill['metrics']??[]; usort($metrics,static fn($a,$b)=>strcmp((string)$a['key'],(string)$b['key']));
    $readings=$bill['readings']??[]; usort($readings,static fn($a,$b)=>strcmp((string)$a['reading_date'],(string)$b['reading_date']));
    $normal=['period_start'=>(string)$bill['period_start'],'period_end'=>(string)$bill['period_end'],'issue_date'=>$bill['issue_date']?:null,'amount_total'=>round((float)$bill['amount_total'],6),'notes'=>$bill['notes']!==null?(string)$bill['notes']:null,
      'metrics'=>array_map(static fn($m)=>['key'=>(string)$m['key'],'value'=>(string)$m['value'],'unit'=>$m['unit']!==null?(string)$m['unit']:null],$metrics),
      'readings'=>array_map(static fn($r)=>['reading_date'=>(string)$r['reading_date'],'reading_value'=>round((float)$r['reading_value'],6)],$readings)];
    return hash('sha256',transferJson($normal));
}

function transferPreview(PDO $pdo, array $bills): array
{
    $u=$pdo->prepare('SELECT id FROM utilities WHERE code=?'); $counts=['new'=>0,'identical'=>0,'different'=>0]; $rows=[];
    foreach ($bills as $i=>$bill) {
        $u->execute([$bill['utility']['code']]); $uid=$u->fetchColumn();
        $local=$uid!==false?transferFindBill($pdo,(int)$uid,$bill['utility']['code'],$bill['period_start'],$bill['period_end']):null;
        $status=$local===null?'new':(transferFingerprint($pdo,$bill)===transferFingerprint($pdo,$local,(int)$local['id'])?'identical':'different');
        $counts[$status]++; $rows[]=['index'=>$i,'status'=>$status,'local_id'=>$local!==null?(int)$local['id']:null,'bill'=>$bill];
    }
    return ['counts'=>$counts,'rows'=>$rows];
}

function transferImport(PDO $pdo, array $bills, string $mode): array
{
    if (!in_array($mode,['keep','replace'],true)) throw new InvalidArgumentException('Modalità conflitto non valida.');
    $out=['inserted'=>0,'replaced'=>0,'identical'=>0,'skipped'=>0];
    $us=$pdo->prepare('SELECT id FROM utilities WHERE code=?'); $ui=$pdo->prepare('INSERT INTO utilities(code,name) VALUES(?,?)');
    $bi=$pdo->prepare('INSERT INTO bills(utility_id,period_start,period_end,issue_date,amount_total,notes) VALUES(?,?,?,?,?,?)');
    $bu=$pdo->prepare('UPDATE bills SET period_start=?,period_end=?,issue_date=?,amount_total=?,notes=? WHERE id=?');
    $mi=$pdo->prepare('INSERT INTO bill_metrics(bill_id,`key`,value,unit) VALUES(?,?,?,?)');
    $ri=$pdo->prepare('INSERT INTO bill_readings(bill_id,reading_date,reading_value) VALUES(?,?,?)');
    foreach($bills as $bill){
        $us->execute([$bill['utility']['code']]); $uid=$us->fetchColumn();
        if($uid===false){$ui->execute([$bill['utility']['code'],$bill['utility']['name']]);$uid=(int)$pdo->lastInsertId();}else{$uid=(int)$uid;}
        $local=transferFindBill($pdo,$uid,$bill['utility']['code'],$bill['period_start'],$bill['period_end']);
        if($local!==null && transferFingerprint($pdo,$bill)===transferFingerprint($pdo,$local,(int)$local['id'])){$out['identical']++;continue;}
        if($local!==null && $mode==='keep'){$out['skipped']++;continue;}
        if($local===null){$bi->execute([$uid,$bill['period_start'],$bill['period_end'],$bill['issue_date'],$bill['amount_total'],$bill['notes']]);$id=(int)$pdo->lastInsertId();$out['inserted']++;}
        else{$id=(int)$local['id'];$bu->execute([$bill['period_start'],$bill['period_end'],$bill['issue_date'],$bill['amount_total'],$bill['notes'],$id]);$pdo->prepare('DELETE FROM bill_metrics WHERE bill_id=?')->execute([$id]);$pdo->prepare('DELETE FROM bill_readings WHERE bill_id=?')->execute([$id]);$out['replaced']++;}
        foreach($bill['metrics'] as $m)$mi->execute([$id,$m['key'],$m['value'],$m['unit']]);
        foreach($bill['readings'] as $r)$ri->execute([$id,$r['reading_date'],$r['reading_value']]);
    }
    return $out;
}
