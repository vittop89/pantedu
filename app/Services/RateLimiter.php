<?php

namespace App\Services;

/**
 * Session-backed rate limiter. Matches legacy semantics:
 *   - after $maxAttempts failed hits, block for $lockoutSeconds from last attempt.
 * Call ::hit() on failure, ::reset() on success, ::isBlocked() before attempt.
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $key = 'login_attempts',
        private readonly int $maxAttempts = 5,
        private readonly int $lockoutSeconds = 300,
    ) {
    }

    public function isBlocked(): bool
    {
        $state = $this->state();
        if ($state['count'] < $this->maxAttempts) {
            return false;
        }
        if ((time() - $state['last']) >= $this->lockoutSeconds) {
            $this->reset();
            return false;
        }
        return true;
    }

    public function remainingSeconds(): int
    {
        $state = $this->state();
        if ($state['count'] < $this->maxAttempts) {
            return 0;
        }
        return max(0, $this->lockoutSeconds - (time() - $state['last']));
    }

    public function hit(): void
    {
        $state = $this->state();
        $state['count']++;
        $state['last'] = time();
        $_SESSION[$this->key] = $state;
    }

    public function reset(): void
    {
        unset($_SESSION[$this->key]);
    }

    public function attemptsLeft(): int
    {
        return max(0, $this->maxAttempts - $this->state()['count']);
    }

    /** @return array{count:int,last:int} */
    private function state(): array
    {
        $raw = $_SESSION[$this->key] ?? null;
        return [
            'count' => is_array($raw) ? (int)($raw['count'] ?? 0) : 0,
            'last'  => is_array($raw) ? (int)($raw['last']  ?? 0) : 0,
        ];
    }
}
