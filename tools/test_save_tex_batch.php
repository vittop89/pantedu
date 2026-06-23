<?php
/** Simula POST /api/verifica/save-tex-batch?force=1 con un payload realistico. */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/api/verifica/save-tex-batch?force=1';
$_GET                      = ['force' => '1'];

@session_start();
$_SESSION['autenticato']           = true;
$_SESSION['username']               = 'superadmin';
$_SESSION['user_id']                = 77;
$_SESSION['user_role']               = 'teacher';
$_SESSION['authenticated_section']   = 'SCI.2';

// Payload minimal — Selection + flags InfoVer
$payload = [
    'verTitle'      => 'TEST AUTO ' . date('His'),
    'selectedIIS'   => 'SCI',
    'selectedCLS'   => '2',
    'selectedMATER' => 'MAT',
    'anno'          => '2026',
    'sezione'       => 'NOR',
    'version'       => 'A',
    'problems'      => [[
        'filePath'  => '/eser/SCI/eser_SCI2/MAT/2.0_MAT-Sistemi_lineari-SCI2.php',
        'problemId' => 'problem-1',
        'position'  => 1,
        'type'      => 'Collect',
        'text'      => 'Risolvi i seguenti sistemi lineari',
        'items'     => [[
            'html'            => 'Risolvi: $\\begin{cases} x+y=3\\\\ 2x-y=0 \\end{cases}$',
            'solution'        => '$x=1,\\, y=2$',
            'points'          => 2.0,
            'includeSolution' => false,
        ]],
    ]],
    'options' => ['includeSolutions' => false, 'includeTitlePage' => true],
    'title'   => 'TEST AUTO ' . date('His'),
    'materia' => 'MAT',
    'version_label' => '',
    'dsa'      => false,
    'compensa' => false,
    'nPrint'    => 1,
    'nPrintDSA' => 0,
    'nPrintDIS' => 0,
];
$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

// Inietta body in php://input (fake stream wrapper)
final class FakeInput
{
    private static string $data = '';
    private int $pos = 0;
    public static function set(string $s): void { self::$data = $s; }
    public function stream_open(): bool { $this->pos = 0; return true; }
    public function stream_read(int $count): string {
        $r = substr(self::$data, $this->pos, $count);
        $this->pos += strlen($r);
        return $r;
    }
    public function stream_eof(): bool { return $this->pos >= strlen(self::$data); }
    public function stream_stat(): array { return []; }
    public function url_stat(string $path, int $flags): array { return []; }
}

stream_wrapper_unregister('php');
stream_wrapper_register('php', FakeInput::class);
FakeInput::set($payloadJson);

$req = new \App\Core\Request();
$ctrl = new \App\Controllers\VerificaController();

echo "=== TEST save-tex-batch?force=1 ===\n";
echo "payload size: " . strlen($payloadJson) . " bytes\n";

try {
    $resp = $ctrl->saveTexBatch($req);
    stream_wrapper_restore('php');
    echo "STATUS=" . $resp->status . "\n";
    $body = $resp->body;
    echo "BODY=" . substr($body, 0, 1000) . "\n";
    $j = json_decode($body, true);
    if (is_array($j)) {
        echo "ok=" . var_export($j['ok'] ?? null, true) . "\n";
        if (!empty($j['error'])) echo "error=" . $j['error'] . "\n";
        if (!empty($j['docs'])) {
            echo "docs.count=" . count($j['docs']) . "\n";
            foreach ($j['docs'] as $d) echo "  - id={$d['id']} variant={$d['variant']} title={$d['title']}\n";
        }
        if (!empty($j['zip_url'])) echo "zip_url={$j['zip_url']}\n";
    }
} catch (\Throwable $e) {
    stream_wrapper_restore('php');
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo "FILE: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "TRACE:\n" . $e->getTraceAsString() . "\n";
}
