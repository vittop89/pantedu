<?php
/**
 * seed_super_admin.php — Phase 14
 *
 * Crea/aggiorna il docente super-admin tecnico iniziale + il suo istituto.
 *
 * PARAMETRICO (per la pubblicazione open-source): tutti i dati personali e
 * dell'istituto si passano via variabili d'ambiente, così l'istituto che
 * installa pantedu definisce il PROPRIO super-admin (non un account hardcoded).
 * Vedi docs/SUPERADMIN.md per la guida passo-passo.
 *
 * Variabili d'ambiente (con fallback per il solo dev locale):
 *   SEED_ADMIN_USERNAME    username login           (default: superadmin)
 *   SEED_ADMIN_FIRSTNAME   nome                     (default: Vittorio)
 *   SEED_ADMIN_LASTNAME    cognome                  (default: Pantaleo)
 *   SEED_ADMIN_EMAIL       email                    (default: operatore@example.net)
 *   SEED_ADMIN_PASSWORD    password (>= 8 caratteri, OBBLIGATORIA)
 *   SEED_INSTITUTE_CODE    cod. mecc. istituto      (default: MIUR-ESEMPIO-COMUNE ESEMPIO-SCI)
 *   SEED_INSTITUTE_NAME    nome istituto            (default: LICEO SC. ART. E SPORTIVO "ESEMPIO")
 *   SEED_INSTITUTE_CITY    comune                   (default: COMUNE ESEMPIO)
 *   SEED_INSTITUTE_REGION  regione                  (default: PIEMONTE)
 *
 * Idempotente: riesegue senza effetti collaterali. Richiede DB attivo.
 *
 * Uso:
 *   php tools/seeds/seed_super_admin.php [password]
 *   (la password può arrivare da argomento o da SEED_ADMIN_PASSWORD)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\InstituteRepository;

if (!Config::get('database.enabled') || !Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile — abilita DB_ENABLED nel .env.\n");
    exit(1);
}

$username   = $_ENV['SEED_ADMIN_USERNAME']  ?? 'superadmin';
$firstName  = $_ENV['SEED_ADMIN_FIRSTNAME'] ?? 'Vittorio';
$lastName   = $_ENV['SEED_ADMIN_LASTNAME']  ?? 'Pantaleo';
$email      = $_ENV['SEED_ADMIN_EMAIL']     ?? 'operatore@example.net';
$role       = 'teacher';
$password   = $argv[1] ?? ($_ENV['SEED_ADMIN_PASSWORD'] ?? null);

if (!$password) {
    fwrite(STDERR, "Password mancante. Passa come argomento o set SEED_ADMIN_PASSWORD.\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password troppo corta (min 8 caratteri).\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    // ── Upsert user
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $uid = (int)($stmt->fetchColumn() ?: 0);

    if ($uid === 0) {
        $ins = $pdo->prepare(
            'INSERT INTO users
               (username, role, is_super_admin, first_name, last_name, email,
                password_hash, status, active, created_at, approved_at, approved_by)
             VALUES (?,?,1,?,?,?,?,"approved",1,NOW(),NOW(),"seed")'
        );
        $ins->execute([$username, $role, $firstName, $lastName, $email, $hash]);
        $uid = (int)$pdo->lastInsertId();
        echo "Creato user id=$uid\n";
    } else {
        $upd = $pdo->prepare(
            'UPDATE users SET role=?, is_super_admin=1, first_name=?, last_name=?, email=?,
                              password_hash=?, status="approved", active=1
             WHERE id = ?'
        );
        $upd->execute([$role, $firstName, $lastName, $email, $hash, $uid]);
        echo "Aggiornato user id=$uid\n";
    }

    // ── Upsert istituto MIUR + pivot (parametrico via env)
    $repo = new InstituteRepository();
    $institutes = [
        [
            'code'   => $_ENV['SEED_INSTITUTE_CODE']   ?? 'MIUR-ESEMPIO-COMUNE ESEMPIO-SCI',
            'name'   => $_ENV['SEED_INSTITUTE_NAME']   ?? 'LICEO SC. ART. E SPORTIVO "ESEMPIO"',
            'city'   => $_ENV['SEED_INSTITUTE_CITY']   ?? 'COMUNE ESEMPIO',
            'region' => $_ENV['SEED_INSTITUTE_REGION'] ?? 'PIEMONTE',
        ],
    ];
    foreach ($institutes as $i) {
        $iid = $repo->upsert($i['code'], $i['name'], $i['city'], $i['region']);
        $repo->linkTeacher($uid, $iid, 'docente');
        echo "Linked institute '{$i['name']}' (id=$iid)\n";
    }

    $pdo->commit();
    echo "Seed completato.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERRORE: " . $e->getMessage() . "\n");
    exit(2);
}
