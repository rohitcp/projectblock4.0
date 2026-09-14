# Work Item Mentions & Comment Notifications

## Requirement

Tell the people a comment concerns, once each, through the right channel — and tell nobody
else. Mentions, replies, assignment and thread participation each mean something different,
and a project member who is not involved gets nothing at all.

## Architecture

`App\Services\WorkItemCommentNotifier` decides the audience; the channels only deliver. It runs
from `WorkItemCollaborationController::storeComment`, immediately after `MentionNotifier`, and
is given the list of who was mentioned so it can exclude them.

```
storeComment
 ├─ MentionSync::sync            → who is named in this text
 ├─ MentionNotifier::mentioned   → tier 1, in-app + email
 └─ WorkItemCommentNotifier::commented(…, $mentioned)
      ├─ tier 2 DIRECT        → parent comment's author   in-app + email
      └─ tier 3 PARTICIPATING → assignee, prior commenters, watchers on `all`   in-app only
```

## Tiers

A person lands in exactly **one** tier; a higher tier wins. That is what makes "assignee **and**
mentioned" a single notification rather than two.

| Tier | Who | Channels |
|---|---|---|
| 1 · Mentioned | named in the comment | In-app + email |
| 2 · Direct | author of the comment being replied to | In-app + email |
| 3 · Participating | assignee, anyone who already commented, watchers on `all` | In-app only |
| — | every other project member | nothing |

Email is sent only for the two direct tiers, plus assignment (which has its own notifier). A
comment on an item you merely watch is in-app by design — email there is the firehose the
requirement rules out.

## Watch levels

`work_item_subscribers.level`, one of:

| Level | Meaning |
|---|---|
| `all` (default) | comments, replies, status, assignment |
| `mentions` | only an @mention, or a reply to something you wrote |
| `mute` | nothing at all, mentions included |

**Mute silences mentions too.** That reading is forced by the option list: "Mentions & Replies
Only" already exists as a separate level, so if mute still delivered mentions the two settings
would be identical. Mute is the one way to be named on an item and stay quiet.

The level is applied to the **finished** participating set, not to the watcher query. Filtering
only the watcher list left somebody on `mentions` still receiving plain comments, because being
the assignee — or having commented once — put them in the tier by another door.

## Business Rules

- Never notify somebody about their own action.
- One notification per person, per comment, per type — enforced again at the Inbox write, so a
  re-saved comment cannot stack rows.
- Every notification deep-links to `?tab=comments#comment-<id>`, the same anchor mentions use.
- Watching (on/off) and watch level are separate controls: one answers "am I on this item", the
  other "how much do I want". Folding them together made "stop watching" and "watch less" the
  same click.

## Acceptance Criteria

- A top-level comment notifies the assignee in-app, with no email.
- A reply notifies the parent author in-app **and** by email.
- Somebody who is both assignee and mentioned receives exactly one notification.
- A muted user receives nothing, even when mentioned.
- A `mentions` watcher receives nothing for a plain comment, but still receives a reply to
  their own comment.
- Uninvolved project members receive nothing.

## Verified

All six criteria exercised against the database, plus the real controller path
(`storeComment` → reply → one Inbox row + one queued email).

## Not in this slice

- **Email for general watched activity** is not configurable yet — tier 3 is in-app, full stop.
  `emails/comment-posted.blade.php` is written and unused, ready for that switch.
- **Workspace-level notification preferences.** The level is per work item; a global "never
  email me" belongs in Settings and would sit in front of all of this.
- **Status, assignment and blocked notifiers do not consult the watch level yet.** They have
  their own recipient lists that predate it, so `mute` currently silences comments and mentions
  but not a status change. Worth unifying behind this service.
