<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One message in a wiki page comment thread (docs/features/wiki-comments.md).
 *
 * Soft-deleted, not removed: a deleted message must not orphan the replies that answered it,
 * and "who said what and when" is the one thing a conversation cannot reconstruct afterwards.
 */
class WikiPageComment extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'wiki_page_comments';

    protected $fillable = ['tenant_id', 'thread_id', 'user_id', 'body', 'edited_at'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(WikiPageCommentThread::class, 'thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Written by this person? The one question every edit and delete check asks. */
    public function writtenBy(?User $user): bool
    {
        return $user !== null && (int) $this->user_id === (int) $user->id;
    }

    public function toCard(): array
    {
        $author = $this->author;

        return [
            'id' => $this->id,
            'body' => $this->body,
            // Plain text, escaped by the client. A comment is not rich text: it is a sentence
            // about a document, and accepting HTML here would be a second sanitizer to keep in
            // step with the first for no gain.
            'created_at' => $this->created_at?->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'author' => [
                'id' => $author?->id,
                // `full_name`, not `name`: this app stores the name in two columns and joins
                // them there. A deleted user leaves their words behind, and the thread still
                // has to read.
                'name' => $author?->full_name ?? 'Removed user',
                'avatar_url' => $author?->avatar_url,
                'avatar_color' => $author?->avatarColor(),
                'initial' => $author?->initial() ?? '?',
            ],
        ];
    }
}
