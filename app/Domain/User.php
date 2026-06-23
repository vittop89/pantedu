<?php

namespace App\Domain;

final class User
{
    public function __construct(
        public readonly string $username,
        public readonly string $passwordHash,
        public readonly string $role,
        public readonly bool $active,
        public readonly ?string $course = null,
        public readonly ?string $notes = null,
        public readonly ?string $created = null,
        public readonly ?string $source = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $email = null,
    ) {
    }

    public static function fromArray(string $username, array $data, string $source = 'local'): self
    {
        return new self(
            username:     $username,
            passwordHash: (string)($data['password_hash'] ?? ''),
            role:         (string)($data['role']          ?? 'student'),
            active:       (bool)  ($data['active']        ?? false),
            course:       $data['course']  ?? null,
            notes:        $data['notes']   ?? null,
            created:      $data['created'] ?? $data['created_date'] ?? null,
            source:       $source,
            firstName:    $data['first_name'] ?? null,
            lastName:     $data['last_name']  ?? null,
            email:        $data['email']      ?? null,
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === 'administrator';
    }
    public function isCollaborator(): bool
    {
        return $this->role === 'collaborator';
    }
    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }
    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    public function displayName(): string
    {
        $full = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
        return $full !== '' ? $full : $this->username;
    }

    public function canAccessSection(string $sectionCode): bool
    {
        if ($this->isAdmin() || $this->isCollaborator() || $this->isTeacher()) {
            return true;
        }
        return $this->course !== null && $this->course === $sectionCode;
    }

    public function verifyPassword(string $plain): bool
    {
        if ($this->passwordHash === '') {
            return false;
        }
        return password_verify($plain, $this->passwordHash);
    }
}
