<?php

declare(strict_types=1);

namespace App\Services\Risdoc\Pt;

/**
 * G22.S15.bis Fase 5+ — Alias back-compat per TexEscape canonica.
 * La utility e' stata spostata in App\Services\Tex\TexEscape (single
 * source of truth per il LaTeX escape). Questa classe estende quella
 * canonica per non rompere i caller esistenti che importano dal
 * namespace Risdoc\Pt.
 */
final class TexEscape extends \App\Services\Tex\TexEscape
{
}
