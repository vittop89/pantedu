<?php

declare(strict_types=1);

/**
 * G27.badge — export_sources_to_fonti_tex.php
 *
 * Strumento diagnostico (CLI). Estrae il registro `sources.registry.json` di
 * un docente e produce un file LaTeX `fonti.tex` con una `\definefonte{...}`
 * per riga, pronto per essere `\input{...}` in un main_SOL.tex di test
 * isolato (utile per debug delle macro di verifica.sty senza passare dalla
 * pipeline TexBuilder).
 *
 * NB: la pipeline run-time NON usa questo file: BadgeRenderer::renderFontiPreamble()
 * dump il preambolo direttamente nell'header di esercizi_SOL.tex.
 *
 * Usage:
 *   php tools/export_sources_to_fonti_tex.php --teacher=77 [--out=PATH]
 *   php tools/export_sources_to_fonti_tex.php --teacher=77 --institute=106
 *
 * Default output: storage/_diag/fonti_{teacherId}.tex
 */

require_once __DIR__ . '/../app/bootstrap.php';

$teacherId   = 0;
$instituteId = 0;
$outPath     = '';
foreach ($argv as $a) {
    if (preg_match('/^--teacher=(\d+)$/', $a, $m))   $teacherId   = (int)$m[1];
    if (preg_match('/^--institute=(\d+)$/', $a, $m)) $instituteId = (int)$m[1];
    if (preg_match('/^--out=(.+)$/', $a, $m))        $outPath     = $m[1];
}
if ($teacherId <= 0) {
    fwrite(STDERR, "ERR: --teacher=ID obbligatorio\n");
    exit(1);
}
if ($instituteId <= 0) {
    $instituteId = \App\Support\TeacherContextResolver::firstInstituteId($teacherId);
    if ($instituteId <= 0) {
        fwrite(STDERR, "ERR: nessun institute_id risolvibile per teacher $teacherId; passa --institute=ID\n");
        exit(1);
    }
}

$badges  = \App\Services\TexBuilder\BadgeRenderer::loadFor($instituteId, $teacherId);
$preamble = $badges->renderFontiPreamble();

if ($outPath === '') {
    $outPath = __DIR__ . "/../storage/_diag/fonti_{$teacherId}.tex";
}
$dir = \dirname($outPath);
if (!is_dir($dir)) {
    if (!@mkdir($dir, 0o775, true) && !is_dir($dir)) {
        fwrite(STDERR, "ERR: impossibile creare $dir\n");
        exit(2);
    }
}
$bytes = file_put_contents($outPath, $preamble);
if ($bytes === false) {
    fwrite(STDERR, "ERR: scrittura fallita: $outPath\n");
    exit(3);
}

$lines = substr_count($preamble, "\n");
echo "OK: $lines righe scritte in $outPath\n";
echo "Per testare:\n";
echo "  \\documentclass{article}\n";
echo "  \\usepackage{verifica}\n";
echo "  \\input{" . $outPath . "}\n";
echo "  \\begin{document}\n";
echo "    \\badge[bg=green]{KEY}{42}{17}{2}\n";
echo "  \\end{document}\n";
