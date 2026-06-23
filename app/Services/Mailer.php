<?php

namespace App\Services;

use RuntimeException;

/**
 * Invio email via Resend API (transactional service).
 *
 * Setup (.env.local):
 *   RESEND_API_KEY=re_xxxxxxx...   (da https://resend.com/api-keys)
 *
 * Setup (.env):
 *   APP_MAIL_FROM=noreply@pantedu.eu
 *   APP_MAIL_FROM_NAME=Pantedu
 *
 * Fallback: se RESEND_API_KEY è vuota, ricade su PHP mail() (utile in dev).
 * Per testing iniettabile via constructor: $transport = callable
 * che riceve (to, subject, body, headers) e ritorna bool.
 */
final class Mailer
{
    private const RESEND_API_ENDPOINT = 'https://api.resend.com/emails';
    private const RESEND_TIMEOUT_SECONDS = 10;

    /** @var callable(string,string,string,string):bool */
    private $transport;

    public function __construct(
        private readonly string $from,
        private readonly string $fromName = 'Pantedu',
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? self::resendTransport();
    }

    /** Invia un'email plain text. */
    public function send(string $to, string $subject, string $body): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('invalid_recipient');
        }
        if (\strlen($subject) > 200 || \strlen($subject) < 1) {
            throw new RuntimeException('invalid_subject');
        }
        if (\strlen($body) > 100_000) {
            throw new RuntimeException('body_too_large');
        }

        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromName   = mb_encode_mimeheader($this->fromName, 'UTF-8', 'B', "\r\n");
        $headers  = "From: $fromName <{$this->from}>\r\n";
        $headers .= "Reply-To: {$this->from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";

        return ($this->transport)($to, $encSubject, $body, $headers);
    }

    /** Log dell'email su file (per dev / audit). */
    public function logSend(string $to, string $subject, string $body, string $logFile): bool
    {
        $dir = \dirname($logFile);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot_create_log_dir');
        }
        $entry = sprintf(
            "[%s] TO=%s SUBJECT=%s\n%s\n---END---\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $body
        );
        return (bool)file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Transport default: Resend API via HTTPS POST.
     * Fallback a PHP mail() se RESEND_API_KEY non valorizzata (dev mode).
     *
     * @return callable(string,string,string,string):bool
     */
    private static function resendTransport(): callable
    {
        return static function (
            string $to,
            string $encSubject,
            string $body,
            string $headers
        ): bool {
            $apiKey = (string)($_ENV['RESEND_API_KEY'] ?? '');

            // Fallback dev: PHP mail() se RESEND_API_KEY missing
            if ($apiKey === '') {
                error_log('[Mailer] RESEND_API_KEY missing, falling back to PHP mail()');
                return @mail($to, $encSubject, $body, $headers);
            }

            // Estrai From e Reply-To dagli headers MIME (compatibility shim)
            $from = '';
            $replyTo = '';
            if (preg_match('/^From:\s*(.+?)\r?\n/m', $headers, $m)) {
                $from = trim($m[1]);
            }
            if (preg_match('/^Reply-To:\s*(.+?)\r?\n/m', $headers, $m)) {
                $replyTo = trim($m[1]);
            }

            // Decodifica subject base64-encoded (formato RFC 2047)
            $subject = $encSubject;
            if (preg_match('/^=\?UTF-8\?B\?(.+)\?=$/', $encSubject, $m)) {
                $decoded = base64_decode($m[1], true);
                if ($decoded !== false) {
                    $subject = $decoded;
                }
            }

            $payload = [
                'from'    => $from,
                'to'      => [$to],
                'subject' => $subject,
                'text'    => $body,
            ];
            if ($replyTo !== '' && $replyTo !== $from) {
                $payload['reply_to'] = $replyTo;
            }

            $ch = curl_init(self::RESEND_API_ENDPOINT);
            if ($ch === false) {
                error_log('[Mailer] curl_init failed');
                return false;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'User-Agent: Pantedu-Mailer/1.0',
                ],
                CURLOPT_TIMEOUT        => self::RESEND_TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($response === false || $error !== '') {
                error_log("[Mailer] Resend curl error: $error");
                return false;
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                // Log without leaking the full API key
                $keyMasked = substr($apiKey, 0, 6) . '...' . substr($apiKey, -3);
                error_log("[Mailer] Resend API non-2xx: HTTP $httpCode key=$keyMasked response=" . substr((string)$response, 0, 500));
                return false;
            }

            return true;
        };
    }
}
