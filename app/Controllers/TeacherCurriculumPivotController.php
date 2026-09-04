<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * G22.S22 — Endpoint pivot DEPRECATO.
 *
 * Post catalog ownership refactor full (migration 044), TUTTI i kind
 * (indirizzi, classi, materie) sono per-docente: ogni docente possiede
 * direttamente le sue righe in curriculum_entries (owner_user_id).
 * La tabella curriculum_users è stata droppata.
 *
 * Questo controller mantiene gli endpoint per retro-compatibilità con
 * client esistenti: listMine restituisce un pivot vuoto, toggle è no-op
 * + warning. Eventuali integrazioni client devono migrare a
 * /api/teacher/curriculum?scope=all.
 */
final class TeacherCurriculumPivotController
{
    public function listMine(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        return Response::json([
            'ok' => true,
            'pivot' => ['indirizzi' => [], 'classi' => [], 'materie' => []],
            'deprecated' => 'pivot_dropped',
            'replacement' => '/api/teacher/curriculum?scope=all',
        ]);
    }

    public function toggle(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        return Response::json([
            'ok' => false,
            'error' => 'pivot_dropped',
            'detail' => 'Tutti i kind sono ora per-docente. Crea/elimina entries '
                     . 'via POST /api/teacher/curriculum/{kind}.',
        ], 410);
    }
}
