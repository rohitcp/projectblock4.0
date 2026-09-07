# Wiki — Highlight & Comment

Extends [Wiki Editor — Lexical](wiki-lexical-editor.md). The interaction is Google Docs':
**select text → floating toolbar → comment → the passage is marked → the thread opens on the
right.**

## Requirement

Select a passage, highlight it, and attach a conversation to it.

## The one decision everything else follows from

**The document stores an ID. Nothing else.**

The editor writes `<mark data-wk="comment" data-wk-threads="tabc123">` around the passage and
that is the whole of what the page's HTML knows about comments. Every comment — its author, its
text, its time, its replies, whether it is resolved — lives in `wiki_page_comment_threads` and
`wiki_page_comments`, keyed by that id.

Comment text baked into a document has no author, no timestamp, and no way to be replied to,
resolved, permissioned, edited, or delivered over a socket. This split is what makes all of
those possible, and it is the requirement's own recommendation.

## Data

| Table | Holds |
|---|---|
| `wiki_page_comment_threads` | one anchored conversation: `uid` (the id in the document), `quote`, `status`, `anchor_missing`, who resolved it and when |
| `wiki_page_comments` | one message: author, body, `edited_at`, soft-deleted |

A thread and a comment are separate so a thread can **outlive its text** — see below. Comments
are soft-deleted so a deleted message cannot orphan the replies that answered it.

`quote` is a COPY of the passage as it read at the time. That is deliberate: it is what the
sidebar shows, and it has to survive the passage being rewritten or deleted.

## Flow

1. Select text → a floating toolbar appears over it: **B · I · U · Link · 5 highlight colours ·
   Remove highlight · Comment**.
2. **Comment** opens a box anchored to the selection: the quote, `Add a comment...`, **Cancel**
   and **Comment**.
3. On submit the thread is created **first**, and only then is the `<mark>` written.

That order is not incidental. A mark written first and a request that fails leaves a highlight
pointing at a thread nobody can open — and the next autosave would persist it. The editor holds
the selection until the server answers, then wraps exactly those words.

The `uid` is minted by the CLIENT, which is unusual and deliberate: the same id has to go into
the document, and asking the server for one first means either a second round trip or a document
saved with a mark pointing at nothing. It is validated for shape and uniqueness — it identifies
a thread, it does not authorize anything.

## Highlight vs comment — two mechanisms on purpose

| | How | Why |
|---|---|---|
| **Normal highlight** | `background-color` on the text (`$patchStyleText`) | Yellow · green · blue · pink · purple · remove. No thread, no anchor, no record. |
| **Comment anchor** | a `<mark>` node carrying thread ids | Light tint + underline when inactive, stronger when its thread is selected; darker again when two threads overlap one passage. |

Separate mechanisms so the two can sit on the same words without either erasing the other — and
so removing a highlight can never remove a conversation.

## Three Lexical traps this feature walked into

All three present as "comments are broken" rather than as an error, and all three are one line:

1. **`MarkNode.excludeFromCopy()` returns true for HTML.** Sensible for a transient highlight,
   exactly wrong for a comment anchor: the exporter walks straight past the `<mark>` and emits
   only its children, so the document saves with the passage intact and the anchor gone — and
   every thread is flagged "referenced text deleted" on the next load. `WkCommentNode` overrides
   it to `false`.
2. **Lexical 0.50 registers a node through `$config()`, not `static getType()` alone.** An
   inherited `$config` registers the subclass under the PARENT's type, so the exporter uses
   MarkNode's HTML config and the mark comes out as a bare `<span>`.
3. **`highlight` was both a data field and a method.** Vue resolves data first, so the method
   was silently unreachable and every highlight button did nothing. Renamed `applyHighlight`.

## The sanitizer, again

`RichTextSanitizer` drops anything not on its allowlist, and the autosave runs every document
through it — so `data-wk-threads` on `<mark>` had to be allowed or the anchors would be
destroyed on the first save. It survives as **data, never as authority**: an id is looked up,
and a thread that does not exist simply does not open.

## Referenced text deleted

If the passage a thread points at is edited, the mark moves with it — Lexical's own node
handling does that, which is why `MarkNode` was subclassed rather than reinvented.

If the passage is **deleted**, the thread is not. After every change the editor reports which
uids the document still carries; the server compares and sets `anchor_missing`. Nothing is
deleted because of it, ever. The sidebar shows **Referenced text deleted** and keeps every
message — the conversation may be the only record of why the text went. A mark that comes back
(an undo) clears the flag just as quietly.

The client only ever REPORTS. It does not decide a thread's fate, and only editors report at
all: a reader cannot change the document, so their view of the anchors proves nothing.

## Permissions — two tiers, not three

The requirement names Editor / Viewer / Read-only. A wiki collection has two — `openableBy` and
`writableBy` — so the mapping is stated once, in `PageCommentController`:

- **write access** → start a thread, reply, edit or delete your own, resolve, reopen;
- **read access** → see threads, and **reply**.

Reply sits on the read side deliberately: commenting is not editing, a reply cannot change a
word of the document, and a reader who spots a mistake being unable to say so is the failure
this feature exists to prevent. Starting a thread is on the write side because it writes a mark
INTO the document.

**Editing and deleting are yours alone**, checked against the comment's own author: an editor
may resolve anybody's thread and may not put words in anybody's mouth.

A genuine third tier is a change to collection permissions, not to comments.

## Sidebar

Right of the document, never over it — a panel that covers the passage is one you have to close
to answer. **Open** and **Resolved** tabs; resolved threads leave the working list and stay
readable, and can be reopened. Each thread shows its quote, then each message with avatar, name,
body, and `Sep 6, 2026 · 10:03 PM`.

The actions are **icons with tooltips** — pen and bin on your own message, reply and tick on the
thread, and a rotate for reopen in the tick's place. Four words repeated on every message in
every thread is most of what a narrow sidebar has room for, and the panel stops reading as a
conversation and starts reading as a list of links. Each carries `data-tip` for the tooltip and
`aria-label` for a screen reader: an icon with neither is a button nobody can name.

Save and Cancel inside an open edit or reply box stay **words**. They are the point of the form
you are looking at, they appear one pair at a time, and a tick beside a cross is exactly the
pair a tooltip is worst at telling apart.

Clicking a thread scrolls the document to its passage and paints it as selected; clicking the
passage selects the thread. Both go through one method, so "selected" means the same thing
whichever end it started from.

## Real time

`private-tenant.{tenantId}.wiki.page.{pageId}` — the PAGE, not the person, because a comment
concerns whoever has that page open. Authorized in `routes/channels.php` against active
workspace membership, the page's own tenant, and the collection's read rule (CLAUDE.md §12).

`WikiPageCommentEvent` carries six names on one class and the WHOLE thread in the payload —
unlike the ticket stream, which sends an id and lets the client refetch. A thread is small and
the sidebar has to redraw it anyway; a refetch per event would hammer the page endpoint whenever
a thread got busy. The actor's id rides along so a client can skip its own echo rather than
redrawing the box somebody is still typing in.

**Broadcasting is wrapped in a try/catch.** `ShouldBroadcastNow` publishes synchronously, so
with Reverb down the dispatch throws and takes the whole request with it: the comment is already
committed and the author is told it failed. Real-time is an ADDITION to a page that already
works over HTTP. The failure is logged, not shown.

## Acceptance Criteria

1. Selecting text shows the floating toolbar; it disappears when the selection collapses.
2. Comment opens a box quoting the selection; Cancel leaves the document untouched.
3. Submitting creates the thread, marks the passage, and opens the sidebar on it.
4. The mark survives save → sanitize → reload with its thread id intact.
5. Clicking a marked passage selects its thread; clicking a thread scrolls to its passage.
6. Reply, edit own, delete own, resolve and reopen all work and update the list.
7. Deleting the last message deletes the thread and its mark; deleting a reply does not.
8. Deleting the passage flags the thread **Referenced text deleted** and keeps every message.
9. Highlight colours apply and clear, independently of any comment on the same words.
10. A second browser sees new comments, replies and resolutions without reloading.

## What is NOT in MVP 1.0

`@mention` notifications, email, comment assignment, follow-thread and reactions — the
requirement lists them as later. The channel and the event are already in place for them.

## Files

| File | |
|---|---|
| `database/migrations/2026_09_30_000003_create_wiki_page_comment_tables.php` | Both tables. |
| `app/Models/WikiPageCommentThread.php`, `WikiPageComment.php` | The models. |
| `app/Http/Controllers/Wiki/PageCommentController.php` | Every endpoint, and the permission mapping. |
| `app/Events/WikiPageCommentEvent.php` | The broadcast. |
| `routes/channels.php` | Who may listen. |
| `public/assets/js/lexical/nodes.js` | `WkCommentNode`. |
| `public/assets/js/lexical/editor.js` | Floating toolbar, highlight, mark, anchor reporting. |
| `public/assets/js/wiki-page.js` | The sidebar, the thread actions, the socket. |
| `public/assets/js/realtime.js` | `PB.onWikiComments`. |
| `app/Services/RichTextSanitizer.php` | Lets the anchor survive a save. |
