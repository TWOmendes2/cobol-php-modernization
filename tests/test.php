<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/process.php';
function check(bool $condition, string $message): void {if (!$condition) throw new RuntimeException($message);}
check(parseMoney('1000.00') === 100000,'money parsing');
foreach (['10','10,00','-1.00','0.001','1e3'] as $bad) {
    try {parseMoney($bad); throw new RuntimeException('accepted invalid: '.$bad);} catch (InvalidArgumentException $e) {}
}
$binary=dirname(__DIR__).'/bin/split-sale';
if (is_executable($binary)) {
    foreach ([0,1,2,3,5,10,101,100000,999999999999] as $value) {
        [$s,$p]=splitCents($value,$binary);
        check($s+$p===$value,'reconciliation');
        check($s===intdiv($value*7+5,10),'70% rounding');
    }
    $csv=tempnam(sys_get_temp_dir(),'sales');
    file_put_contents($csv,"Nome,CPF/CNPJ,Valor Total da Venda\nCliente Exemplo,12345678901,1000.00\n");
    $events=processCsv($csv,$binary); unlink($csv);
    check(count($events)===1 && $events[0]['amountsCents']['service']===70000,'CSV pipeline');
    echo "PASS: parser + COBOL integration + CSV\n";
} else echo "PASS: parser; SKIP COBOL integration (install GnuCOBOL and compile)\n";
