<?php

namespace App\Broadcasting;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Minimal authenticatable object for guest broadcasting.
 * Allows guest sessions to authenticate with Pusher presence/private channels.
 */
class GuestBroadcastUser implements Authenticatable
{
    public function __construct(
        public int $id,
        public string $name,
        public string $guestToken,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
