<?php
declare(strict_types=1);
/** CSV -> COBOL monetary engine -> auditable JSONL -> optional signed webhook. */
function splitCents(int $cents, string $binary): array {
    if ($cents < 0 || $cents > 999999999999) throw new InvalidArgumentException('Invalid cents');
    if (!is_file($binary) || !is_executable($binary)) throw new RuntimeException('Compile COBOL first: '. $binary);
    $pipes = []; $process = proc_open([$binary], [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('COBOL launch failed');
    fwrite($pipes[0], (string)$cents."\n"); fclose($pipes[0]);
    $stdout = trim(stream_get_contents($pipes[1])); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || !preg_match('/^(\d{12}) (\d{12})$/', $stdout, $m)) {
        throw new RuntimeException('COBOL failed: '.$stdout.' '.$stderr);
    }
    $service = (int)$m[1]; $product = (int)$m[2];
    if ($service + $product !== $cents) throw new RuntimeException('Reconciliation failed');
    return [$service, $product];
}
function parseMoney(string $value): int {
    $value = trim($value);
    if (!preg_match('/^\d{1,10}\.\d{2}$/D', $value)) throw new InvalidArgumentException('Use monetary values like 1000.00');
    [$whole,$fraction] = explode('.', $value);
    $cents = (int)$whole * 100 + (int)$fraction;
    if ($cents > 999999999999) throw new InvalidArgumentException('Value exceeds COBOL limit');
    return $cents;
}
function emitWebhook(string $url, string $secret, array $event): void {
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !$secret) throw new InvalidArgumentException('HTTPS webhook and signing secret required');
    $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $timestamp = (string)time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$json, $secret);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$json,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Event-Timestamp: '.$timestamp,'X-Event-Signature: sha256='.$signature],
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_RETURNTRANSFER=>true]);
    $response = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch); curl_close($ch);
    if ($response === false || $status < 200 || $status >= 300) throw new RuntimeException('Webhook delivery failed: HTTP '.$status.' '.$error);
}
function processCsv(string $path, string $binary, ?string $webhook=null, ?string $secret=null): array {
    $fh = fopen($path, 'rb'); if (!$fh) throw new RuntimeException('CSV not found');
    try {
        $headers = fgetcsv($fh, 0, ',', '"', '');
        if ($headers === false) throw new InvalidArgumentException('Empty CSV');
        $headers = array_map('trim', $headers);
        foreach (['Nome','CPF/CNPJ','Valor Total da Venda'] as $field) {
            if (!in_array($field, $headers, true)) throw new InvalidArgumentException('Missing column: '.$field);
        }
        $out=[]; $line=1;
        while (($cells=fgetcsv($fh,0,',','"','')) !== false) {
            $line++; if ($cells === [null]) continue;
            if (count($cells) !== count($headers)) throw new InvalidArgumentException('Column count mismatch line '.$line);
            $row=array_combine($headers,$cells);
            $name=trim($row['Nome']); $taxId=preg_replace('/\D/','',$row['CPF/CNPJ']);
            if ($name === '' || !in_array(strlen($taxId), [11,14], true)) throw new InvalidArgumentException('Invalid customer line '.$line);
            $total=parseMoney($row['Valor Total da Venda']);
            [$service,$product]=splitCents($total,$binary);
            $event=['event'=>'sale.allocated','version'=>1,'reference'=>hash('sha256',$line.'|'.$name.'|'.$taxId.'|'.$total),
                'customer'=>['name'=>$name,'taxId'=>$taxId],
                'amountsCents'=>['total'=>$total,'service'=>$service,'product'=>$product]];
            $out[]=$event;
            if ($webhook !== null) emitWebhook($webhook, (string)$secret, $event);
        }
        return $out;
    } finally { fclose($fh); }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $args=getopt('', ['csv:','cobol:','send-webhook']);
        $csv=$args['csv']??null; $binary=$args['cobol']??dirname(__DIR__).'/bin/split-sale';
        if (!$csv) throw new InvalidArgumentException('Usage: php src/process.php --csv=clients.csv [--cobol=bin/split-sale] [--send-webhook]');
        $send=isset($args['send-webhook']);
        $url=$send?(getenv('WEBHOOK_URL')?:''):null;
        $secret=$send?(getenv('WEBHOOK_SECRET')?:''):null;
        if ($send && (!$url || !$secret)) throw new InvalidArgumentException('WEBHOOK_URL and WEBHOOK_SECRET required');
        foreach (processCsv($csv,$binary,$url,$secret) as $event) echo json_encode($event, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $e) {fwrite(STDERR,$e->getMessage()."\n"); exit(1);}
}
