<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A comment thread on a wiki page moved (docs/features/wiki-comments.md).
 *
 * ## One class, several event names
 *
 * Created, replied, edited, deleted, resolved, reopened — one fact ("this thread moved") with
 * six labels. Six classes would be six copies of the same channel, the same payload and the
 * same tenancy rule, free to drift the first time one is changed. `broadcastAs()` returns the
 * type, so the wire reads as six events while the rules live in one place. The same decision
 * `HelpCenterTicketEvent` records.
 *
 * ## The channel is the PAGE
 *
 * `private-tenant.{tenantId}.wiki.page.{pageId}` (CLAUDE.md §12). A comment concerns whoever
 * has that page open, which is usually several people and never only its author — a per-user
 * channel would leave the person actually reading the paragraph the last to know.
 *
 * ## Now, and after commit
 *
 * **Now**, because a queue worker nobody is running is an update that never arrives.
 *
 * **After commit**, because a socket message that beats its own COMMIT makes the browser
 * refetch and be served the state from before the write. On a fast connection that is the
 * ordinary case, not a rare race.
 *
 * ## The payload carries the whole thread
 *
 * Unlike the ticket stream, which sends an id and lets the client refetch: a thread is small,
 * bounded by how much anyone will type into one, and the sidebar has to redraw it anyway. One
 * round trip saved on every keystroke somebody else makes is worth more than the few hundred
 * bytes — and a refetch-per-event would hammer the page endpoint whenever a thread gets busy.
 */
class WikiPageCommentEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public const CREATED = 'wiki.comment.created';

    public const REPLIED = 'wiki.comment.replied';

    public const UPDATED = 'wiki.comment.updated';

    public const DELETED = 'wiki.comment.deleted';

    public const RESOLVED = 'wiki.comment.resolved';

    public const REOPENED = 'wiki.comment.reopened';

    /** @param  array<string, mixed>  $thread */
    public function __construct(
        public readonly string $tenantId,
        public readonly int $pageId,
        public readonly string $type,
        public readonly array $thread,
        /*
         * Who caused it. The client uses this to skip its own echo: the person who just typed
         * a comment already has it on screen, and redrawing from the socket would move their
         * caret out of the box they are still typing in.
         */
        public readonly int $actorId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("tenant.{$this->tenantId}.wiki.page.{$this->pageId}");
    }

    public function broadcastAs(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'page_id' => $this->pageId,
            'thread' => $this->thread,
            'actor_id' => $this->actorId,
        ];
    }
}
