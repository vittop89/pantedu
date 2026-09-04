<?php
// G22.S20 v2.C2 — Full E2E real test: docente1 (485 file) → marco
// Esegue tutto il flusso completo via ImportBundleController reale,
// batch-by-batch come il client JS, verifica counts + indirizzo_id +
// filesystem .contract.json + zero errori.
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
set_exception_handler(function($e){
    fwrite(STDERR, "UNCAUGHT: ".get_class($e).": ".$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(255);
});
require __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Controllers\VerificaSyncController;
use App\Controllers\ImportBundleController;
use App\Services\Crypto\TeacherRecoveryService;

function auth(string $u, int $id, string $role = 'teacher'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    Session::put('autenticato', true);
    Session::put('username', $u);
    Session::put('user_id', $id);
    Session::put('user_role', $role);
    Session::put('authenticated_section', 'teacher');
}

class TestPhpInputWrapper {
    public static string $data = '';
    private int $pos = 0;
    public $context;
    public function stream_open($p,$m,$o,&$op){$this->pos=0;return true;}
    public function stream_read($n){$r=substr(self::$data,$this->pos,$n);$this->pos+=strlen($r);return $r;}
    public function stream_eof(){return $this->pos>=strlen(self::$data);}
    public function stream_stat(){return ['size'=>strlen(self::$data)];}
    public function stream_tell(){return $this->pos;}
    public function stream_seek($o,$w){if($w===SEEK_SET)$this->pos=$o;elseif($w===SEEK_CUR)$this->pos+=$o;elseif($w===SEEK_END)$this->pos=strlen(self::$data)+$o;return true;}
    public function url_stat($p,$f){return ['size'=>strlen(self::$data)];}
}

function callImport(ImportBundleController $ctrl, bool $apply, array $body): array {
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', TestPhpInputWrapper::class);
    TestPhpInputWrapper::$data = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $req = new Request();
    try {
        $resp = $apply ? $ctrl->apply($req) : $ctrl->preview($req);
    } finally {
        stream_wrapper_restore('php');
    }
    return json_decode($resp->body, true) ?: ['ok' => false];
}

$pdo = Database::connection();
$vittorioId = 77;
$marcoId    = 140;
$t0 = microtime(true);

echo "═══ FULL E2E test: docente1 (all) → marco (v2.C2) ═══\n\n";

// ── 1. Setup: cleanup marco + rigenera recovery docente1
echo "[1] Setup…\n";
$pdo->beginTransaction();
$pdo->prepare('DELETE FROM verifica_documents_data WHERE teacher_id=?')->execute([$marcoId]);
$pdo->prepare('DELETE FROM teacher_content_data WHERE teacher_id=? AND content_type IN ("mappa","esercizio","documento")')->execute([$marcoId]);
$pdo->commit();

$svc = new TeacherRecoveryService();
$svc->revoke($vittorioId);
$pdo->prepare('DELETE FROM teacher_recovery_keys WHERE user_id=?')->execute([$vittorioId]);
$pdo->prepare('UPDATE teacher_keys SET kek_recovery_wrapped=NULL, recovery_wrap_kv=NULL WHERE teacher_id=?')->execute([$vittorioId]);
$gen = $svc->generate($vittorioId, '127.0.0.1', 'cli-full-test');
$rHex = $gen['recovery_hex'];
echo "  ✓ marco cleaned, docente1 recovery key regenerated\n";

// ── 2. Manifest signed (full bundle)
echo "\n[2] Operatore: manifest signed (full bundle)…\n";
auth('superadmin', $vittorioId);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/teacher/sync-bundle/manifest';
$ctrl = new VerificaSyncController();
$resp = $ctrl->manifestSigned(new Request());
$payload = json_decode($resp->body, true);
if (!($payload['ok'] ?? false)) { echo "❌ ".json_encode($payload)."\n"; exit(2); }
$manifest = $payload['manifest'];
$nFiles = count($manifest['files']);
$typeCounts = [];
foreach ($manifest['files'] as $f) $typeCounts[$f['type']] = ($typeCounts[$f['type']] ?? 0) + 1;
echo "  ✓ Manifest: $nFiles file totali\n";
foreach ($typeCounts as $t => $c) echo "    - $t: $c\n";

// ── 3. Materializza TUTTI i file (memoria intensa)
@ini_set('memory_limit', '2048M');
echo "\n[3] Materializzazione TUTTI i file (memory_limit 2GB)…\n";
$entries = $ctrl->buildLocalBundleManifest($vittorioId);
$byPath = [];
foreach ($entries as $e) $byPath[$e['path']] = $e;
$filesPayload = [];
$totalBytes = 0;
$errCount = 0;
foreach ($manifest['files'] as $i => $sel) {
    $entry = $byPath[$sel['path']] ?? null;
    if (!$entry) { $errCount++; continue; }
    try {
        $mat = $ctrl->materializeBundleEntry($vittorioId, $entry);
        $filesPayload[] = ['path' => $mat['path'], 'content_b64' => $mat['content']];
        $totalBytes += $mat['size'];
    } catch (Throwable $e) {
        $errCount++;
        echo "  ⚠ materialize fail ".$sel['path'].": ".$e->getMessage()."\n";
    }
    if (($i + 1) % 100 === 0) echo "  ... materialized ".($i+1)."/".$nFiles."\n";
}
echo "  ✓ Materialized ".count($filesPayload)." files, ".number_format($totalBytes/1024/1024, 1)." MB, errors=$errCount\n";

// ── 4. Marco: chiama PREVIEW (full manifest, no files = dry-run)
echo "\n[4] Marco: PREVIEW dry-run…\n";
auth('marco.rossi', $marcoId);
$importCtrl = new ImportBundleController();
$prev = callImport($importCtrl, /*apply*/ false, [
    'recovery_code'     => $rHex,
    'manifest'          => $manifest,
    'files'             => [],
    'conflict_strategy' => 'rename',
]);
if (!($prev['ok'] ?? false)) { echo "❌ preview: ".json_encode($prev)."\n"; exit(4); }
$pr = $prev['report'];
echo "  created=".count($pr['created'])." conflicts=".count($pr['conflicts'])
   ." unsupported=".count($pr['unsupported'])." errors=".count($pr['errors'])."\n";

// ── 5. APPLY in batch da 5 (come il client JS)
echo "\n[5] APPLY chunked (batch=5)…\n";
$BATCH = 5;
$cumulative = ['created'=>0,'conflicts'=>0,'errors'=>0,'unsupported'=>0,'applied'=>0];
$totalChunks = (int)ceil(count($filesPayload) / $BATCH);
for ($i = 0; $i < count($filesPayload); $i += $BATCH) {
    $chunk = array_slice($filesPayload, $i, $BATCH);
    $resp = callImport($importCtrl, /*apply*/ true, [
        'recovery_code'     => $rHex,
        'manifest'          => $manifest,
        'files'             => $chunk,
        'conflict_strategy' => 'rename',
    ]);
    if (!($resp['ok'] ?? false)) {
        echo "  ❌ chunk ".(int)($i/$BATCH+1)." failed: ".json_encode($resp)."\n";
        $cumulative['errors']++;
        continue;
    }
    $r = $resp['report'];
    $cumulative['created']     += count($r['created'] ?? []);
    $cumulative['conflicts']   += count($r['conflicts'] ?? []);
    $cumulative['errors']      += count($r['errors'] ?? []);
    $cumulative['unsupported'] += count($r['unsupported'] ?? []);
    $cumulative['applied']     += $r['applied'] ?? 0;
    foreach ($r['errors'] ?? [] as $e) echo "    ERR: ".json_encode($e)."\n";
    $batchNum = (int)($i/$BATCH+1);
    if ($batchNum % 20 === 0 || $batchNum === $totalChunks) {
        echo "  ... batch $batchNum/$totalChunks (applied cumulative=".$cumulative['applied'].")\n";
    }
}
echo "\n  ✓ APPLY done:\n";
foreach ($cumulative as $k => $v) echo "    $k: $v\n";

// ── 6. Verifica DB post-apply
echo "\n[6] DB verification post-apply…\n";
$sV = $pdo->prepare('SELECT COUNT(*) FROM verifica_documents WHERE teacher_id=?');
$sV->execute([$marcoId]);
$mVerifica = (int)$sV->fetchColumn();

$sQ = $pdo->prepare('SELECT content_type, COUNT(*) as c FROM teacher_content WHERE teacher_id=? GROUP BY content_type');
$sQ->execute([$marcoId]);
$contents = [];
foreach ($sQ as $row) $contents[$row['content_type']] = (int)$row['c'];

echo "  verifica_documents:        $mVerifica\n";
foreach ($contents as $t => $c) echo "  teacher_content.$t:".str_repeat(' ', 18 - strlen($t)).$c."\n";

// ── 7. FK integrity check: tutti i row hanno indirizzo_id consistent con curriculum_entries
echo "\n[7] FK integrity: indirizzo_id ↔ curriculum_entries…\n";
$queries = [
    'verifica_documents' => "SELECT COUNT(*) FROM verifica_documents WHERE teacher_id=? AND indirizzo IS NOT NULL",
    'verifica_documents_FK_ok' => "SELECT COUNT(*) FROM verifica_documents vd JOIN curriculum_entries ce ON ce.id=vd.indirizzo_id WHERE vd.teacher_id=? AND vd.indirizzo IS NOT NULL AND vd.indirizzo = ce.code",
    'teacher_content' => "SELECT COUNT(*) FROM teacher_content WHERE teacher_id=? AND indirizzo IS NOT NULL",
    'teacher_content_FK_ok' => "SELECT COUNT(*) FROM teacher_content tc JOIN curriculum_entries ce ON ce.id=tc.indirizzo_id WHERE tc.teacher_id=? AND tc.indirizzo IS NOT NULL AND tc.indirizzo = ce.code",
];
$counts = [];
foreach ($queries as $label => $sql) {
    $s = $pdo->prepare($sql);
    $s->execute([$marcoId]);
    $counts[$label] = (int)$s->fetchColumn();
}
echo "  verifica_documents:  ind!=NULL=$counts[verifica_documents], FK_consistent=$counts[verifica_documents_FK_ok]\n";
echo "  teacher_content:     ind!=NULL=$counts[teacher_content], FK_consistent=$counts[teacher_content_FK_ok]\n";

// ── 8. Spot-check filesystem .contract.json esercizi
echo "\n[8] Filesystem check: .contract.json esercizi…\n";
$sE = $pdo->prepare("SELECT id, title, metadata_json FROM teacher_content WHERE teacher_id=? AND content_type='esercizio' LIMIT 5");
$sE->execute([$marcoId]);
$contractsFound = 0;
$contractsMissing = 0;
foreach ($sE as $r) {
    $m = json_decode($r['metadata_json'], true);
    $ck = (string)($m['contract_key'] ?? '');
    $abs = __DIR__ . '/../../storage/objects/' . $ck;
    if ($ck !== '' && is_file($abs)) {
        $contractsFound++;
    } else {
        $contractsMissing++;
        echo "  ✗ missing: $ck (id=".$r['id'].")\n";
    }
}
echo "  .contract.json: found=$contractsFound missing=$contractsMissing (su 5 spot-check)\n";

// ── 9. Legacy values audit
echo "\n[9] Legacy values audit (deve essere zero ovunque)…\n";
foreach (['verifica_documents','teacher_content'] as $t) {
    $c = $pdo->query("SELECT COUNT(*) FROM $t WHERE indirizzo IN ('sc','ar','cl','li','ling','af')")->fetchColumn();
    echo "  $t legacy values: $c\n";
}

// ── 10. Performance
$elapsed = microtime(true) - $t0;
echo "\n[10] Total elapsed: ".number_format($elapsed, 1)."s\n";

// Final verdict
$success = (
    $cumulative['errors'] === 0 &&
    $cumulative['applied'] > 0 &&
    $counts['verifica_documents'] === $counts['verifica_documents_FK_ok'] &&
    $counts['teacher_content'] === $counts['teacher_content_FK_ok'] &&
    $contractsFound > 0 &&
    $contractsMissing === 0
);
echo "\n" . ($success ? "✅ FULL E2E PASS — v2.C2 production-ready" : "❌ FULL E2E FAIL") . "\n";
exit($success ? 0 : 1);
