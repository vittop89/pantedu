<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$svc = new \App\Services\Verifica\VerificaDocumentService();
$tex = $svc->readTex(77, (int)($argv[1] ?? 1494));
preg_match_all('/\\\\input\{[^\}]+\}/', $tex, $m);
foreach (array_unique($m[0]) as $line) echo $line . "\n";
echo "---\n";
preg_match_all('/\\\\includegraphics(\[[^\]]*\])?\{[^\}]+\}/', $tex, $g);
foreach (array_unique($g[0]) as $line) echo $line . "\n";
