<?php

namespace App\Services;

use RuntimeException;

/**
 * Streams the last N lines of a log file. Replaces the hand-rolled
 * tailFile() in log/logging/view_debug_log.php, but with a path
 * whitelist so only approved log files can be read from the admin UI.
 */
final class LogTailer
{
    /** @var list<string> absolute file paths allowed to read */
    private array $allowedFiles;

    public function __construct(array $allowedFiles)
    {
        $this->allowedFiles = array_values(array_map(
            fn(string $p) => str_replace('\\', '/', $p),
            $allowedFiles
        ));
    }

    /** @return list<string> */
    public function tail(string $absolutePath, int $lines = 50): array
    {
        $abs = str_replace('\\', '/', $absolutePath);
        if (!\in_array($abs, $this->allowedFiles, true)) {
            throw new RuntimeException('log_not_allowed');
        }
        if (!is_file($abs)) {
            return [];
        }

        $f = fopen($abs, 'rb');
        if ($f === false) {
            throw new RuntimeException('cannot_open_log');
        }

        fseek($f, 0, SEEK_END);
        $filesize = ftell($f);
        $buffer    = '';
        $chunkSize = 4096;
        $lineCount = 0;
        $linesArr  = [];

        while ($filesize > 0 && $lineCount < $lines) {
            $seek     = min($chunkSize, $filesize);
            $filesize -= $seek;
            fseek($f, $filesize);
            $buffer    = fread($f, $seek) . $buffer;
            $linesArr  = explode("\n", $buffer);
            $lineCount = count($linesArr) - 1;
        }
        fclose($f);

        return array_slice($linesArr, -$lines);
    }

    /** @return list<string> */
    public function allowed(): array
    {
        return $this->allowedFiles;
    }
}
