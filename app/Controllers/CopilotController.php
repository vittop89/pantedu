<?php

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * Copilot / AI proxy moderno — sostituisce api/copilot.php + api/copilot_proxy.php.
 *
 * Flow:
 *   POST /api/copilot/chat
 *     body: JSON { token, payload } — token API del provider (OpenAI "sk-..."
 *                                     oppure Anthropic "sk-ant-...")
 *     - auth: collaborator+ (route middleware)
 *     - CSRF + rate limit: route middleware
 *     - Response: formato OpenAI chat-completion (anche se upstream è
 *                 Anthropic → traduciamo automaticamente).
 *
 * Security:
 *   - Nessun CORS permissivo: stessa origin (middleware rifiuta cross-origin)
 *   - Nessun header duplicato (regression gap fix)
 *   - error_log strutturato, non leaka token
 */
final class CopilotController
{
    private const ANTHROPIC_URL   = 'https://api.anthropic.com/v1/messages';
    private const OPENAI_URL      = 'https://api.openai.com/v1/chat/completions';
    private const DEFAULT_MODEL_A = 'claude-sonnet-4-5-20250929';
    private const CURL_TIMEOUT    = 30;

    public function chat(Request $req): Response
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }

        $token   = (string)($data['token'] ?? '');
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        if ($token === '') {
            return Response::json(['error' => 'token_required'], 400);
        }

        $isAnthropic = str_starts_with($token, 'sk-ant-');
        error_log(sprintf(
            '[Copilot] provider=%s tokenPrefix=%s payloadKeys=%s',
            $isAnthropic ? 'anthropic' : 'openai',
            substr($token, 0, 10),
            implode(',', array_keys($payload)),
        ));

        try {
            [$code, $body] = $isAnthropic
                ? $this->callAnthropic($token, $payload)
                : $this->callOpenAi($token, $payload);
        } catch (Throwable $e) {
            error_log('[Copilot] error: ' . $e->getMessage());
            return Response::json(['error' => 'upstream_error', 'detail' => $e->getMessage()], 502);
        }

        // Anthropic → traduci in formato OpenAI così il client resta unico
        if ($isAnthropic && $code === 200) {
            $anthropic = json_decode($body, true) ?: [];
            return Response::json($this->anthropicToOpenAi($anthropic), $code);
        }
        // OpenAI (o errori Anthropic): pass-through JSON
        $decoded = json_decode($body, true);
        return Response::json($decoded ?? ['error' => 'upstream_invalid_json', 'raw' => $body], $code);
    }

    /** @param array $payload  @return array{0:int,1:string} */
    private function callAnthropic(string $token, array $payload): array
    {
        $messages = [];
        $systemPrompt = '';
        foreach ($payload['messages'] ?? [] as $msg) {
            $role = $msg['role'] ?? '';
            if ($role === 'system') {
                $systemPrompt = (string)($msg['content'] ?? '');
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $msg['content'] ?? ''];
        }
        $body = [
            'model'       => (string)($payload['model'] ?? self::DEFAULT_MODEL_A),
            'messages'    => $messages,
            'max_tokens'  => (int)($payload['max_tokens']  ?? 2000),
            'temperature' => (float)($payload['temperature'] ?? 0.7),
        ];
        if ($systemPrompt !== '') {
            $body['system'] = $systemPrompt;
        }

        return $this->curlJson(self::ANTHROPIC_URL, $body, [
            'x-api-key: ' . $token,
            'Content-Type: application/json',
            'anthropic-version: 2023-06-01',
        ]);
    }

    /** @param array $payload  @return array{0:int,1:string} */
    private function callOpenAi(string $token, array $payload): array
    {
        return $this->curlJson(self::OPENAI_URL, $payload, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
    }

    /** @return array{0:int,1:string} [httpCode, rawBody] */
    private function curlJson(string $url, array $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('curl: ' . $err);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, (string)$resp];
    }

    private function anthropicToOpenAi(array $a): array
    {
        return [
            'id'      => $a['id']    ?? 'chatcmpl-' . uniqid(),
            'object'  => 'chat.completion',
            'created' => time(),
            'model'   => $a['model'] ?? 'claude',
            'choices' => [[
                'index'         => 0,
                'message'       => [
                    'role'    => 'assistant',
                    'content' => $a['content'][0]['text'] ?? '',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage'   => [
                'prompt_tokens'     => $a['usage']['input_tokens']  ?? 0,
                'completion_tokens' => $a['usage']['output_tokens'] ?? 0,
                'total_tokens'      => ($a['usage']['input_tokens'] ?? 0) + ($a['usage']['output_tokens'] ?? 0),
            ],
        ];
    }
}
