<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One attention item in one person's Inbox (docs/features/inbox.md).
 *
 * TENANT-SCOPED (CLAUDE.md §7). "Inbox = things that need my attention" (§46) — not a history.
 * Reading one removes it from the active list and changes nothing about the work item, the
 * assignment or the comment behind it (§5).
 */
class InboxNotification extends Model
{
    use BelongsToTenant;

    /** Somebody gave you a work item (§4.1). */
    public const TYPE_ASSIGNMENT = 'assignment';

    /** Somebody named you in a description or a comment (§7). */
    public const TYPE_MENTION = 'mention';

    /**
     * Somebody replied to a comment YOU wrote.
     *
     * Its own type rather than a `comment` with a flag: a reply to you is a direct
     * notification — in-app and email, like a mention — while a comment on an item you merely
     * watch is not. Two names for two different levels of "this concerns you".
     */
    public const TYPE_REPLY = 'reply';

    /** Somebody commented on a work item you are assigned, participating in, or watching. */
    public const TYPE_COMMENT = 'comment';

    /** §33 lists more the column is deliberately open for. */
    public const TYPES = [
        self::TYPE_ASSIGNMENT, self::TYPE_MENTION, self::TYPE_REPLY, self::TYPE_COMMENT,
    ];

    protected $fillable = [
        'tenant_id', 'recipient_id', 'actor_id', 'type', 'project_id', 'work_item_id',
        'comment_id', 'source_type', 'source_id', 'title', 'excerpt', 'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** One person's Inbox, newest first. */
    public function scopeFor(Builder $query, int $userId): Builder
    {
        return $query->where('recipient_id', $userId);
    }

    /** Still needing attention — what the active list shows and the counter counts. */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
