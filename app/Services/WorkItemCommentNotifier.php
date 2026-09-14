<?php

namespace App\Services;

use App\Mail\EmailActor;
use App\Mail\WorkItemCommentReplyMail;
use App\Models\InboxNotification;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemComment;
use App\Models\WorkItemSubscriber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Who hears about one new comment, and through which channel
 * (docs/features/work-item-comment-notifications.md).
 *
 * ## The whole problem is the audience, not the delivery
 *
 * Sending a notification is three lines. Deciding who gets one — without telling the same
 * person twice, without telling somebody about their own typing, and without mailing every
 * project member every time anybody says anything — is the feature. So the audience is worked
 * out first, as sets, and only then handed to the channels.
 *
 * ## Tiers, highest first
 *
 * A person lands in exactly ONE tier. The tiers are ordered, and a higher one wins, which is
 * what makes "assignee AND mentioned" a single notification rather than two:
 *
 *   1. MENTIONED   — handled by MentionNotifier before this runs. Excluded here entirely.
 *   2. DIRECT      — the author of the comment being replied to. In-app AND email: somebody
 *                    answered something you wrote, which is as direct as a mention.
 *   3. PARTICIPATING — the assignee, anyone who has already commented on the item, and
 *                    watchers on `all`. In-app only. Email here would be the firehose the
 *                    requirement is explicit about avoiding.
 *
 * Everybody else — the rest of the project — gets nothing. That is the default, not a setting.
 *
 * ## What Mute means, and why
 *
 * `mute` suppresses EVERYTHING, mentions and replies included. That reading is forced by the
 * option list itself: "Mentions & Replies Only" already exists as a separate level, so if mute
 * still delivered mentions and replies the two would be the same setting. Muting is therefore
 * the one way to be named on an item and hear nothing — which is the point of it.
 */
class WorkItemCommentNotifier
{
    public function __construct(
        private readonly InboxNotifier $inbox,
        private readonly RichTextSanitizer $richText,
    ) {}

    /**
     * @param  Collection<int, User>  $mentioned  users MentionNotifier has already told about
     *                                            this same comment — never told again here.
     */
    public function commented(
        WorkItem $item,
        WorkItemComment $comment,
        ?User $actor,
        Collection $mentioned,
    ): void {
        $item->loadMissing('project');

        if (! $item->project) {
            return;
        }

        $muted = $this->mutedUserIds($item);
        $exclude = $muted
            ->merge($mentioned->pluck('id'))
            // Never about your own action. Checked here rather than per tier, because it is
            // true of every tier and a per-tier check is one that can be forgotten in the next.
            ->push($actor?->id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        $direct = $this->directRecipients($comment)->diff($exclude);

        /*
         * A higher tier already has these people; `$direct` joins the exclusions so nobody
         * appears in both.
         *
         * `$quiet` is the fix for a rule that reads as obvious and is easy to implement
         * wrongly: "based on their notification preference". Filtering only the WATCHER query
         * by level left a person on `mentions` still receiving plain comments, because being
         * the assignee or having commented once put them in this tier by another door. The
         * level is a statement about the item, so it has to be applied to the finished set —
         * not to one of the three ways into it.
         */
        $quiet = $this->quietUserIds($item);
        $participating = $this->participatingRecipients($item, $comment)
            ->diff($exclude)
            ->diff($direct)
            ->diff($quiet);

        $excerpt = (string) $this->richText->excerpt(
            $comment->content,
            (int) config('projects.excerpt.email'),
        );

        $this->notifyDirect($item, $comment, $actor, $direct, $excerpt);
        $this->notifyParticipating($item, $comment, $actor, $participating, $excerpt);
    }

    /**
     * People who have muted this work item.
     *
     * Also consulted by MentionNotifier, so a muted person is not mailed a mention — see the
     * note on this class about why mute has to outrank being named.
     *
     * @return Collection<int, int>
     */
    public function mutedUserIds(WorkItem $item): Collection
    {
        return WorkItemSubscriber::query()
            ->where('work_item_id', $item->id)
            ->where('level', WorkItemSubscriber::LEVEL_MUTE)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * People who have asked NOT to hear about ordinary activity on this item — `mentions` and
     * `mute` alike.
     *
     * Only the participating tier consults this. Somebody on `mentions` still gets a reply to
     * their own comment, which is what that level says on the tin; `mute` is already excluded
     * everywhere by `mutedUserIds`.
     *
     * @return Collection<int, int>
     */
    private function quietUserIds(WorkItem $item): Collection
    {
        return WorkItemSubscriber::query()
            ->where('work_item_id', $item->id)
            ->whereIn('level', [WorkItemSubscriber::LEVEL_MENTIONS, WorkItemSubscriber::LEVEL_MUTE])
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * Tier 2: the author of the comment this one replies to.
     *
     * Only a reply has one. A top-level comment answers nobody, so this is empty and the whole
     * direct tier is skipped — which is why a plain comment never sends email.
     *
     * @return Collection<int, int>
     */
    private function directRecipients(WorkItemComment $comment): Collection
    {
        if (! $comment->parent_comment_id) {
            return collect();
        }

        $parent = WorkItemComment::query()->find($comment->parent_comment_id);

        return collect($parent?->author_id ? [(int) $parent->author_id] : []);
    }

    /**
     * Tier 3: assignees, prior commenters, and watchers who asked for everything.
     *
     * "Prior commenters" is what makes a thread work: replying to a thread you are part of
     * reaches the people in it, without anybody having to press Watch. Watchers on
     * `mentions` are deliberately absent — they said only-if-it-names-me, and this tier is
     * everything else.
     *
     * @return Collection<int, int>
     */
    private function participatingRecipients(WorkItem $item, WorkItemComment $comment): Collection
    {
        $assignees = $item->assignees()->pluck('users.id');

        $participants = WorkItemComment::query()
            ->where('work_item_id', $item->id)
            ->where('id', '!=', $comment->id)
            ->whereNotNull('author_id')
            ->distinct()
            ->pluck('author_id');

        $watchers = WorkItemSubscriber::query()
            ->where('work_item_id', $item->id)
            ->where('level', WorkItemSubscriber::LEVEL_ALL)
            ->pluck('user_id');

        return $assignees->merge($participants)->merge($watchers)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /** In-app AND email — somebody answered something they wrote. */
    private function notifyDirect(
        WorkItem $item,
        WorkItemComment $comment,
        ?User $actor,
        Collection $userIds,
        string $excerpt,
    ): void {
        if ($userIds->isEmpty()) {
            return;
        }

        $users = User::query()->whereIn('id', $userIds)->get();

        foreach ($users as $user) {
            $this->inbox->commentEvent(
                InboxNotification::TYPE_REPLY, $user, $item, $comment, $actor, $excerpt,
            );
        }

        $url = $this->commentUrl($item, $comment);

        foreach ($users as $user) {
            if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            try {
                Mail::to($user->email)->send(new WorkItemCommentReplyMail(
                    identifier: (string) $item->identifier,
                    title: (string) $item->title,
                    projectName: (string) $item->project->name,
                    actorName: $actor?->displayName() ?? 'Someone',
                    excerpt: $excerpt,
                    url: $url,
                    actor: EmailActor::fromUser($actor, 'Someone'),
                ));
            } catch (\Throwable $e) {
                // The Inbox row is already written, so the person WILL see this in the app.
                // A mail server being down must not fail somebody's comment.
                Log::warning('work_item.comment.reply_email_failed', [
                    'work_item_id' => $item->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** In-app only, by default — see the tier note on this class. */
    private function notifyParticipating(
        WorkItem $item,
        WorkItemComment $comment,
        ?User $actor,
        Collection $userIds,
        string $excerpt,
    ): void {
        if ($userIds->isEmpty()) {
            return;
        }

        foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
            $this->inbox->commentEvent(
                InboxNotification::TYPE_COMMENT, $user, $item, $comment, $actor, $excerpt,
            );
        }
    }

    /**
     * Straight to the comment (§ "Every notification should deep-link").
     *
     * The same shape MentionNotifier builds, so every route into a comment — mention, reply or
     * participation — lands on the same anchor and the page has one thing to scroll to.
     */
    private function commentUrl(WorkItem $item, WorkItemComment $comment): string
    {
        return route('projects.work-items.show', [
            'project' => $item->project_id,
            'workItem' => $item->id,
        ]).'?tab=comments#comment-'.$comment->id;
    }
}
