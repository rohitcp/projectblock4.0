<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One anchored conversation on a wiki page (docs/features/wiki-comments.md).
 *
 * TENANT-SCOPED (CLAUDE.md §7). The `uid` is the only part of a thread that appears in the
 * document — the editor writes it into a `<mark>` and nothing else. Everything a comment IS
 * lives here, which is what makes replies, resolve, permissions and real-time possible.
 */
class WikiPageCommentThread extends Model
{
    use BelongsToTenant;

    protected $table = 'wiki_page_comment_threads';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'tenant_id',
        'wiki_collection_id',
        'wiki_page_id',
        'uid',
        'quote',
        'status',
        'anchor_missing',
        'created_by',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'anchor_missing' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'wiki_page_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WikiPageComment::class, 'thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    /**
     * The thread as the sidebar reads it.
     *
     * Comments come as a flat list in the order they were written: the first is the comment,
     * the rest are its replies. A tree would be a second shape to render for a depth nobody
     * asked for — a thread is a conversation, not an outline.
     */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'uid' => $this->uid,
            'quote' => $this->quote,
            'status' => $this->status,
            'resolved' => $this->isResolved(),
            'anchor_missing' => (bool) $this->anchor_missing,
            'resolved_by' => $this->resolver?->full_name,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'comments' => $this->comments
                ->sortBy('id')
                ->map(fn (WikiPageComment $c) => $c->toCard())
                ->values()
                ->all(),
        ];
    }
}
