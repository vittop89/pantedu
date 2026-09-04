<?php

/**
 * notify_policy_update.php — preavviso aggiornamento ToS/AUP (dry-run default).
 *
 * Rende operativo l'impegno preso in docs/legal/tos_docente.md §8 e
 * docs/legal/aup.md §6: "modifiche sostanziali comunicate con anticipo minimo
 * di 30 giorni". Il banner in-app copre chi entra; questo job copre chi non
 * entra — che è esattamente la persona che il preavviso deve raggiungere.
 *
 * Invia una email per ogni milestone configurata (default 30/7/1 giorni
 * residui) a ogni teacher/admin che non ha ancora accettato la versione in
 * arrivo. Il dedupe è su (utente, versione, milestone).
 *
 * Uso:
 *   php tools/legal/notify_policy_update.php               # dry-run
 *   php tools/legal/notify_policy_update.php --apply
 *   php tools/legal/notify_policy_update.php --apply --milestone=7
 *
 * Cron suggerito (una volta al giorno, mattina):
 *   15 7 * * *  php /var/www/pantedu/tools/legal/notify_policy_update.php --apply
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Gdpr\TosAcceptanceService;
use App\Services\Mailer;

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile.\n");
    exit(1);
}

$apply = \in_array('--apply', $argv, true);
$onlyMilestone = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--milestone=')) {
        $onlyMilestone = (int)substr($a, \strlen('--milestone='));
    }
}

$pdo = Database::connection();
$svc = new TosAcceptanceService($pdo);

/** @var list<int> $milestones */
$milestones = (array)Config::get('multitenancy.legal_notice_milestones', [30, 7, 1]);
$milestones = array_map('intval', $milestones);
if ($onlyMilestone !== null) {
    $milestones = [$onlyMilestone];
}

$pending = array_values(array_filter(
    $svc->pendingVersions(),
    static fn(array $v) => $v['is_substantial']
));

if ($pending === []) {
    echo "Nessuna versione sostanziale in preavviso. Nulla da notificare.\n";
    exit(0);
}

// Il preavviso è un impegno contrattuale: se una versione è stata pubblicata
// con meno giorni di quelli promessi, il job lo dice invece di far finta di
// niente — la violazione va vista prima che qualcuno la subisca.
$noticeDays = TosAcceptanceService::noticeDays();
foreach ($pending as $v) {
    $published = new DateTimeImmutable((string)$v['published_at']);
    $effective = new DateTimeImmutable((string)$v['effective_from']);
    $window = (int)$published->diff($effective)->days;
    if ($window < $noticeDays) {
        fwrite(STDERR, sprintf(
            "[ATTENZIONE] %s %s ha una finestra di preavviso di %d giorni, "
            . "sotto i %d promessi da ToS §8 / AUP §6.\n",
            strtoupper((string)$v['doc_type']),
            (string)$v['version'],
            $window,
            $noticeDays
        ));
    }
}

$from = (string)($_ENV['APP_MAIL_FROM'] ?? '');
$mailer = null;
if ($apply) {
    if ($from === '') {
        fwrite(STDERR, "APP_MAIL_FROM non configurata: impossibile inviare.\n");
        exit(1);
    }
    $mailer = new Mailer($from, (string)($_ENV['APP_MAIL_FROM_NAME'] ?? 'Pantedu'));
}
$siteUrl = rtrim((string)($_ENV['APP_URL'] ?? 'https://pantedu.eu'), '/');
$replyTo = (string)($_ENV['APP_MAIL_REPLY_TO'] ?? '') ?: null;

$target = $svc->targetVersions();

$recipients = $pdo->prepare(
    "SELECT u.id, u.username, u.email, u.first_name
     FROM users u
     WHERE u.role IN ('teacher','admin')
       AND u.email IS NOT NULL AND u.email <> ''
       AND NOT EXISTS (
           SELECT 1 FROM user_tos_acceptance a
           WHERE a.user_id = u.id AND a.tos_version = :tv AND a.aup_version = :av
       )"
);
$recipients->execute([':tv' => $target['tos'], ':av' => $target['aup']]);
$users = $recipients->fetchAll(PDO::FETCH_ASSOC) ?: [];

$alreadySent = $pdo->prepare(
    'SELECT 1 FROM legal_version_notifications
     WHERE user_id = :uid AND version_id = :vid AND milestone_days = :m'
);
$markSent = $pdo->prepare(
    'INSERT IGNORE INTO legal_version_notifications (user_id, version_id, milestone_days)
     VALUES (:uid, :vid, :m)'
);

$sent = 0;
$skipped = 0;
$failed = 0;

foreach ($pending as $version) {
    $days = (int)$version['days_remaining'];
    // La milestone scattata è la più piccola fra quelle >= ai giorni residui:
    // un cron saltato per un giorno non deve far perdere il preavviso.
    $due = null;
    foreach ($milestones as $m) {
        if ($days <= $m && ($due === null || $m < $due)) {
            $due = $m;
        }
    }
    if ($due === null) {
        echo sprintf(
            "%s %s — %d giorni residui: nessuna milestone raggiunta.\n",
            strtoupper((string)$version['doc_type']),
            (string)$version['version'],
            $days
        );
        continue;
    }

    $label = strtoupper((string)$version['doc_type']) . ' v' . (string)$version['version'];
    $effDate = date('d/m/Y', strtotime((string)$version['effective_from']));

    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $email = (string)$u['email'];

        $alreadySent->execute([':uid' => $uid, ':vid' => (int)$version['id'], ':m' => $due]);
        if ($alreadySent->fetchColumn() !== false) {
            $skipped++;
            continue;
        }

        $name = trim((string)($u['first_name'] ?? '')) ?: (string)$u['username'];
        $subject = sprintf('Aggiornamento %s — in vigore dal %s', $label, $effDate);
        $body = renderBody($name, $label, $version, $effDate, $days, $siteUrl);

        if (!$apply) {
            echo "[DRY] $email — $subject (milestone T-$due)\n";
            $sent++;
            continue;
        }

        try {
            $mailer->send($email, $subject, $body, $replyTo);
            $markSent->execute([':uid' => $uid, ':vid' => (int)$version['id'], ':m' => $due]);
            $sent++;
        } catch (Throwable $e) {
            // Nessun mark: il prossimo giro riprova. Un preavviso mancato è
            // peggio di un preavviso doppio.
            $failed++;
            fwrite(STDERR, "[ERRORE] $email — " . $e->getMessage() . "\n");
        }
    }
}

echo sprintf(
    "\n%s — inviate: %d, già notificate: %d, fallite: %d\n",
    $apply ? 'APPLY' : 'DRY-RUN',
    $sent,
    $skipped,
    $failed
);
if (!$apply) {
    echo "Per inviare davvero: php tools/legal/notify_policy_update.php --apply\n";
}
exit($failed > 0 ? 1 : 0);

/**
 * @param array<string,mixed> $version
 */
function renderBody(
    string $name,
    string $label,
    array $version,
    string $effDate,
    int $days,
    string $siteUrl,
): string {
    $summary = $version['summary'] !== null
        ? "\nCosa cambia:\n" . (string)$version['summary'] . "\n"
        : '';
    $when = $days <= 1 ? "domani, $effDate" : "il $effDate (fra $days giorni)";

    return <<<TXT
    Gentile {$name},

    i documenti contrattuali di pantedu sono stati aggiornati.

    Documento: {$label}
    Entrata in vigore: {$when}
    {$summary}
    Da quella data l'utilizzo di pantedu come docente richiederà l'accettazione
    esplicita della nuova versione. Fino ad allora resta valida la versione
    precedente e non è richiesta alcuna azione immediata.

    Puoi leggere i documenti qui:
      {$siteUrl}/legal/tos
      {$siteUrl}/legal/aup

    Puoi accettare in anticipo qui:
      {$siteUrl}/tos-acceptance

    Se non intendi accettare i nuovi termini, puoi richiedere l'esportazione dei
    tuoi dati (art. 20 GDPR) prima della data di entrata in vigore:
      {$siteUrl}/privacy/your-data

    --
    pantedu.eu
    TXT;
}
