<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\InstituteRepository;
use App\Services\MiurSchoolsService;

/**
 * Phase 25.Q — Onboarding wizard nuovo istituto (super-admin only).
 *
 * Routes:
 *   GET  /admin/institutes        → lista istituti
 *   GET  /admin/institutes/new    → form wizard
 *   POST /admin/institutes/new    → crea istituto + admin di istituto iniziale
 *
 * Crea atomicamente:
 *   1. Riga in `institutes` (code, name, city, region, header_label)
 *   2. Riga in `users` con role='admin' + admin_institute_id = N + password
 *      random one-time (mostrata UNA SOLA VOLTA al super-admin).
 *
 * L'admin di istituto userà poi le proprie credenziali per gestire i propri
 * docenti via `/admin/registrations` (scope automaticamente filtrato).
 */
final class AdminInstitutesController
{
    public function index(Request $req): Response
    {
        $repo = new InstituteRepository();
        $rows = $repo->listActive();
        $miur = MiurSchoolsService::fromConfig();
        $view = View::default();
        $body = $view->render('admin/institutes_index', [
            'rows'  => $rows,
            'flash' => $_SESSION['flash'] ?? null,
            'csrf'  => Csrf::token(),
            'miur_sources' => $miur->sourcesStatus(),
            'miur_index'   => $miur->indexStatus(),
        ]);
        unset($_SESSION['flash']);
        return Response::html($view->render('layout/shell', [
            'title' => 'Istituti — Admin',
            'body'  => $body,
        ]));
    }

    /** GET /admin/institutes/new — form. */
    public function newForm(Request $req): Response
    {
        $view = View::default();
        $body = $view->render('admin/institutes_new', [
            'csrf'  => Csrf::token(),
            'error' => $_SESSION['institute_new_error'] ?? null,
            'old'   => $_SESSION['institute_new_old']   ?? [],
        ]);
        unset($_SESSION['institute_new_error'], $_SESSION['institute_new_old']);
        return Response::html($view->render('layout/shell', [
            'title' => 'Nuovo istituto — Admin',
            'body'  => $body,
        ]));
    }

    /** POST /admin/institutes/new — crea istituto + admin iniziale. */
    public function create(Request $req): Response
    {
        $post = $req->post;
        $code  = trim((string)($post['code']  ?? ''));
        $name  = trim((string)($post['name']  ?? ''));
        $city  = trim((string)($post['city']  ?? ''));
        $region = trim((string)($post['region'] ?? ''));
        $header = trim((string)($post['header_label'] ?? ''));
        $adminUsername = trim((string)($post['admin_username'] ?? ''));
        $adminEmail    = trim((string)($post['admin_email']    ?? ''));
        $adminFirst    = trim((string)($post['admin_first_name'] ?? ''));
        $adminLast     = trim((string)($post['admin_last_name']  ?? ''));

        $errs = [];
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) {
            $errs[] = 'Codice istituto non valido (2-20 char A-Z/0-9/-_).';
        }
        if ($name === '') {
            $errs[] = 'Nome istituto richiesto.';
        }
        if ($adminUsername === '' || !preg_match('/^[a-z0-9._-]{3,32}$/', $adminUsername)) {
            $errs[] = 'Username admin non valido (3-32 char minuscole/numeri/._-).';
        }
        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errs[] = 'Email admin non valida.';
        }

        if ($errs) {
            $_SESSION['institute_new_error'] = implode(' ', $errs);
            $_SESSION['institute_new_old']   = $post;
            return Response::redirect('/admin/institutes/new');
        }

        if (!Database::isAvailable()) {
            $_SESSION['institute_new_error'] = 'DB non disponibile.';
            return Response::redirect('/admin/institutes/new');
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();

            // 1. Insert / upsert institutes (canonico: dedup per stessa scuola)
            $repo = new InstituteRepository();
            $iid = $repo->upsertCanonical($code, $name, $city !== '' ? $city : null, $region !== '' ? $region : null);
            // Aggiorna header_label se fornito (upsert minimal non lo include)
            if ($header !== '') {
                $upd = $pdo->prepare('UPDATE institutes SET header_label = ? WHERE id = ?');
                $upd->execute([$header, $iid]);
            }

            // 2. Insert admin user con password random one-time
            $plainPassword = $this->generatePassword();
            $hash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            // Audit 25.R.31 (L7) — must_change_password=1: l'admin iniziale deve
            // cambiare la password one-time al primo login (enforced da AuthMiddleware).
            $stmt = $pdo->prepare(
                'INSERT INTO users
                    (username, role, first_name, last_name, email, password_hash,
                     must_change_password, status, active, admin_institute_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $adminUsername, 'admin', $adminFirst, $adminLast,
                $adminEmail, $hash,
                1, 'approved', 1, $iid,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $_SESSION['institute_new_error'] = 'Errore creazione: ' . $e->getMessage();
            $_SESSION['institute_new_old']   = $post;
            return Response::redirect('/admin/institutes/new');
        }

        // Mostra credenziali UNA SOLA VOLTA
        $_SESSION['flash'] = [
            'type'    => 'success',
            'title'   => "Istituto «{$name}» creato.",
            'message' => "Admin iniziale: {$adminUsername} — password one-time: <code>{$plainPassword}</code> (annota e cambia al primo login).",
        ];
        return Response::redirect('/admin/institutes');
    }

    /**
     * POST /admin/institutes/miur/update — carica le anagrafiche scuole MIUR
     * (file JSON-LD scaricati dal catalogo opendata dati.istruzione.it) e
     * rigenera l'indice di ricerca.
     *
     * Upload (multipart): statali_file, paritarie_file (almeno uno). Solo JSON-LD
     * con "@graph". super_admin only (middleware di gruppo). Le sorgenti vengono
     * unite nell'indice. NB: richiede upload_max_filesize/post_max_size adeguati
     * (statali ~51 MB, paritarie ~8 MB) + nginx client_max_body_size.
     */
    public function miurUpdate(Request $req): Response
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M'); // rebuild json_decode del JSON ~50MB
        $storage = (string)Config::get('app.paths.storage');
        $dataDir = $storage . '/data';
        if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
            return Response::json(['ok' => false, 'error' => 'data_dir_failed'], 500);
        }

        $targets = [
            'statali'   => $dataDir . '/scuole_miur.json',
            'paritarie' => $dataDir . '/scuole_miur_paritarie.json',
        ];
        $loaded = [];
        foreach (['statali', 'paritarie'] as $k) {
            $f = $_FILES[$k . '_file'] ?? null;
            $errCode = is_array($f) ? (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            if ($errCode === UPLOAD_ERR_NO_FILE) {
                continue; // campo non compilato
            }
            if ($errCode !== UPLOAD_ERR_OK) {
                return Response::json([
                    'ok' => false, 'error' => 'upload_error',
                    'field' => $k . '_file', 'detail' => $this->uploadErrMsg($errCode),
                ], 400);
            }
            $tmp = (string)($f['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                return Response::json(['ok' => false, 'error' => 'not_uploaded', 'field' => $k . '_file'], 400);
            }
            if ($vErr = $this->validateMiurJsonFile($tmp)) {
                return Response::json(['ok' => false, 'error' => $vErr, 'field' => $k . '_file'], 400);
            }
            $dest = $targets[$k];
            if (is_file($dest)) {
                @rename($dest, $dest . '.prev'); // rollback rapido se serve
            }
            if (!move_uploaded_file($tmp, $dest)) {
                return Response::json(['ok' => false, 'error' => 'move_failed', 'field' => $k . '_file'], 500);
            }
            @chmod($dest, 0644);
            $loaded[$k] = (int)($f['size'] ?? filesize($dest));
        }
        if (!$loaded) {
            return Response::json(['ok' => false, 'error' => 'no_file'], 400);
        }

        // Rigenera l'indice unendo le sorgenti presenti (statali + paritarie).
        $svc = MiurSchoolsService::fromConfig();
        try {
            $count = $svc->rebuild();
        } catch (\Throwable $e) {
            return Response::json([
                'ok' => false, 'error' => 'index_rebuild_failed',
                'detail' => $e->getMessage(), 'loaded' => $loaded,
            ], 500);
        }

        return Response::json([
            'ok'      => true,
            'loaded'  => $loaded,
            'records' => $count,
            'sources' => $svc->sourcesStatus(),
            'index'   => $svc->indexStatus(),
        ]);
    }

    /** Valida un file caricato: JSON-LD MIUR con "@graph". @return string|null errore. */
    private function validateMiurJsonFile(string $path): ?string
    {
        $size = (int)@filesize($path);
        if ($size < 1024) {
            return 'file_too_small';
        }
        // Legge i primi 256KB: deve essere JSON ({…) con "@graph". No decode in RAM.
        $head = (string)@file_get_contents($path, false, null, 0, 262144);
        $trim = ltrim($head);
        if ($trim === '') {
            return 'file_unreadable';
        }
        if ($trim[0] !== '{') {
            return 'not_json';
        }
        if (strpos($head, '@graph') === false) {
            return 'not_miur_graph_json';
        }
        return null;
    }

    /** Messaggio leggibile per i codici UPLOAD_ERR_*. */
    private function uploadErrMsg(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'file troppo grande (supera upload_max_filesize/post_max_size)',
            UPLOAD_ERR_PARTIAL    => 'upload incompleto (connessione interrotta)',
            UPLOAD_ERR_NO_TMP_DIR => 'cartella temporanea mancante sul server',
            UPLOAD_ERR_CANT_WRITE => 'impossibile scrivere il file su disco',
            UPLOAD_ERR_EXTENSION  => 'upload bloccato da un\'estensione PHP',
            default               => 'errore upload (codice ' . $code . ')',
        };
    }

    private function generatePassword(int $len = 16): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
