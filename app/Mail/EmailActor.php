<?php

namespace App\Mail;

use App\Models\User;

/**
 * The person an email is ABOUT — the inviter, the assigner, the commenter (§16 of the
 * transactional email guide).
 *
 * A value object rather than three more constructor arguments on seven mailables. It carries
 * exactly what `<x-email.avatar>` needs to obey the rule that matters: show the uploaded photo
 * when there is one, initials when there is not, and never initials over a photo that exists.
 *
 * NOT an Eloquent model, deliberately. Most of these mailables are queued, and a worker
 * rebuilds them with no tenancy context — a `User` would have to be re-resolved there, and a
 * deleted account would fail the job rather than send an email that says who did the thing.
 * These are plain scalars captured at the moment the email was raised.
 */
class EmailActor
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $avatarUrl = null,
        public readonly ?string $initial = null,
        public readonly int|string|null $id = null,
    ) {}

    /**
     * Build from a user, tolerating the absence of one.
     *
     * `$fallbackName` covers the caller that has a name but no account behind it — an
     * invitation raised by somebody since removed, or an address that never signed up. The
     * result still renders: an initials tile, coloured from the name instead of the id.
     */
    public static function fromUser(?User $user, ?string $fallbackName = null): self
    {
        if (! $user) {
            $name = (string) ($fallbackName ?? '');

            return new self($name, null, mb_substr($name, 0, 1) ?: '?', null);
        }

        return new self(
            $user->displayName(),
            $user->avatar_url,
            $user->initial(),
            $user->id,
        );
    }
}
