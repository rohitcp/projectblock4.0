<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One person following one work item (POC toolbar).
 *
 * TENANT-SCOPED (CLAUDE.md §7). Separate from `project_subscribers` on purpose: that answers
 * "tell me about this project", this answers "tell me about this work item", and somebody
 * following one contentious item does not thereby want everything else in the project.
 */
class WorkItemSubscriber extends Model
{
    use BelongsToTenant;

    /**
     * Everything that happens on the item — comments, replies, status, assignment.
     * What watching meant before levels existed, and therefore the default.
     */
    public const LEVEL_ALL = 'all';

    /** Only what names me: an @mention, or a reply to something I wrote. */
    public const LEVEL_MENTIONS = 'mentions';

    /**
     * Nothing, including mentions.
     *
     * Distinct from not watching at all: muting is a decision to stay on the item — it keeps
     * the row, so unmuting restores the previous relationship rather than starting one.
     */
    public const LEVEL_MUTE = 'mute';

    /** @var array<int, string> */
    public const LEVELS = [self::LEVEL_ALL, self::LEVEL_MENTIONS, self::LEVEL_MUTE];

    protected $fillable = ['tenant_id', 'work_item_id', 'user_id', 'level'];

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
