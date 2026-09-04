<?php
/** Simula la chiamata di topicPage per /studio/esercizio/SCI/2/MAT/2.0?ids=58 sotto user superadmin (id=77). */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/studio/esercizio/SCI/2/MAT/2.0?ids=58';
$_GET = ['ids' => '58'];

@session_start();
$_SESSION['autenticato']            = true;
$_SESSION['username']                = 'superadmin';
$_SESSION['user_id']                 = 77;
$_SESSION['user_role']               = 'teacher';
$_SESSION['authenticated_section']   = 'SCI.2';

$req = new \App\Core\Request();

// Debug: search direct
$repo = new \App\Repositories\TeacherContentRepository();
$rows1 = $repo->search(['content_type' => 'esercizio', 'subject_code' => 'MAT', 'indirizzo' => 'SCI', 'classe' => '2', 'topic' => '2.0', 'limit' => 50]);
echo "DIRECT_SEARCH rows=" . count($rows1) . "\n";
foreach($rows1 as $r) echo "  id={$r['id']} teacher={$r['teacher_id']} pool=" . (int)($r['shared_with_pool'] ?? 0) . "\n";

$rows2 = $repo->search(['content_type' => 'esercizio', 'limit' => 5]);
echo "ALL_ESER rows=" . count($rows2) . "\n";

// Debug scopedFilters and ACL via reflection
$ctrl = new \App\Controllers\ContentStudyController();
$ref  = new ReflectionClass($ctrl);
$mScoped = $ref->getMethod('scopedFilters'); $mScoped->setAccessible(true);
$mAcl    = $ref->getMethod('applyAclFilter'); $mAcl->setAccessible(true);

$filters = $mScoped->invoke($ctrl, ['type'=>'esercizio','ind'=>'SCI','cls'=>'2','subj'=>'MAT','topic'=>'2.0'], 'esercizio');
$filters['limit'] = 500;
echo "FILTERS=" . json_encode($filters) . "\n";

$rowsX = (new \App\Repositories\TeacherContentRepository())->search($filters);
echo "ROWS_AFTER_SCOPE=" . count($rowsX) . "\n";
foreach($rowsX as $r) echo "  id={$r['id']} t={$r['teacher_id']} v={$r['visibility']}\n";

$rowsY = $mAcl->invoke($ctrl, $rowsX);
echo "ROWS_AFTER_ACL=" . count($rowsY) . "\n";

try {
    $resp = $ctrl->topicPage($req, [
        'type' => 'esercizio',
        'ind'  => 'SCI',
        'cls'  => '2',
        'subj' => 'MAT',
        'topic' => '2.0',
    ]);
    $body = (function() {
        ob_start();
        $this->send();
        return ob_get_clean();
    })->bindTo($resp, get_class($resp))();
    echo "STATUS=" . (string)$resp->status . "\n";
    echo "BODY_LEN=" . strlen($body) . "\n";
    echo "BODY_HEAD=" . substr($body, 0, 600) . "\n---\n";
    if (str_contains($body, 'fm-contract-wrap')) {
        echo "CONTRACT_RENDERED=YES\n";
    } else {
        echo "CONTRACT_RENDERED=NO\n";
    }
    foreach (['fm-draggable-container', 'fm-muted', 'fm-contract-fallback', 'Nessun item', 'data-id=', '#fm-content', 'fm-pt-rendered'] as $needle) {
        echo "  has[$needle]=" . (str_contains($body, $needle) ? 'YES' : 'NO') . "\n";
    }
    if (preg_match('#<div class="fm-draggable-container".*?</div>\s*</div>#s', $body, $m)) {
        echo "DRAG_HTML=" . substr($m[0], 0, 600) . "\n";
    }
} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
