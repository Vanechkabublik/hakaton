<?php

declare(strict_types=1);

namespace App\User;

use App\Core\Database\Entity;
use DateTimeImmutable;

final class User extends Entity
{
    public int $id = 0;
    public string $email = '';
    public ?string $passwordHash = null;
    public string $name = '';
    public ?string $slug = null;
    public string $bio = '';
    public ?string $avatarPath = null;
    public Role $role = Role::Reader;
    public UserStatus $status = UserStatus::Active;
    public ?string $blockedReason = null;
    public ?DateTimeImmutable $emailVerifiedAt = null;
    public ?string $pendingEmail = null;
    public ?string $totpSecret = null;
    public ?DateTimeImmutable $totpEnabledAt = null;
    public int $totpLastStep = 0;
    /** @var array<int,string> */
    public array $totpRecovery = [];
    public string $digestFrequency = 'weekly';
    public bool $notifyReplies = true;
    public bool $notifyAuthors = true;
    public bool $notifyPush = false;
    public bool $marketingOptIn = false;
    public string $unsubscribeToken = '';
    public ?DateTimeImmutable $trialUsedAt = null;
    public int $onboardingStep = 0;
    public ?DateTimeImmutable $lastLoginAt = null;
    public ?DateTimeImmutable $lastDigestAt = null;
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function hasPassword(): bool
    {
        return $this->passwordHash !== null && $this->passwordHash !== '';
    }

    public function hasTwoFactor(): bool
    {
        return $this->totpEnabledAt !== null && $this->totpSecret !== null;
    }

    public function totpLastStep(): int
    {
        return $this->totpLastStep;
    }

    /** @return list<string> */
    public function recoveryHashes(): array
    {
        return array_values(array_filter($this->totpRecovery, 'is_string'));
    }

    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function avatarUrl(): ?string
    {
        if ($this->avatarPath === null || $this->avatarPath === '') {
            return null;
        }

        return str_starts_with($this->avatarPath, 'http') ? $this->avatarPath : '/uploads/' . ltrim($this->avatarPath, '/');
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name)) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $letters !== '' ? $letters : '?';
    }

    public function firstName(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name)) ?: [];

        return $parts[0] !== '' ? $parts[0] : 'читатель';
    }
}
