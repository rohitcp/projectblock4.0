<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Somebody replied to your comment" (docs/features/work-item-comment-notifications.md).
 *
 * One of the three things the requirement sends email for by default — a mention, a reply to
 * your comment, and an assignment — because all three are somebody addressing YOU. A comment
 * on an item you merely watch is in-app only, and has no mailable on purpose.
 *
 * Queued (CLAUDE.md §11), scalars only: a worker rebuilds this with no tenancy context, where
 * a tenant-scoped work item would not resolve.
 */
class WorkItemCommentReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $identifier,
        public readonly string $title,
        public readonly string $projectName,
        public readonly string $actorName,
        /** The reply itself, already truncated — see WorkItemCommentNotifier. */
        public readonly string $excerpt,
        /** Straight to the reply, anchored on its own comment id. */
        public readonly string $url,
        public readonly ?EmailActor $actor = null,
    ) {}

    public function envelope(): Envelope
    {
        // Names the person and the item, because that is what decides whether this is read
        // now or later — and an inbox truncates the rest.
        return new Envelope(subject: "{$this->actorName} replied to your comment on {$this->identifier}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.comment-reply');
    }
}
