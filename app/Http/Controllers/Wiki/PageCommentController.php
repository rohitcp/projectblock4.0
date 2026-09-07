<?php

namespace App\Http\Controllers\Wiki;

use App\Events\WikiPageCommentEvent;
use App\Http\Controllers\Controller;
use App\Models\WikiCollection;
use App\Models\WikiPage;
use App\Models\WikiPageComment;
use App\Models\WikiPageCommentThread;
use App\Services\WorkspaceApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Comment threads on a wiki page (docs/features/wiki-comments.md).
 *
 * ## Two permission tiers, not three
 *
 * The requirement names Editor / Viewer / Read-only. A wiki collection has TWO tiers —
 * `openableBy` and `writableBy` — so the mapping is stated once, here, and every method below
 * uses it:
 *
 *  - **write access** → start a thread, reply, edit or delete your own, resolve, reopen;
 *  - **read access** → see threads, and REPLY to them.
 *
 * Reply sits on the read side deliberately. Commenting is not editing: a reader who spots a
 * mistake being unable to say so is the failure this feature exists to prevent, and a reply
 * cannot change a word of the document. Starting a thread is on the write side because it
 * writes a `<mark>` INTO the document, which is an edit however small.
 *
 * A genuine third tier is a change to collection permissions, not to comments.
 *
 * ## Editing and deleting are yours alone
 *
 * Checked against the comment's own author, never against the collection: an editor may resolve
 * anybody's thread, and may not put words in anybody's mouth.
 */
class PageCommentController extends Controller
{
    public function __construct(private readonly WorkspaceApps $apps) {}

    /** GET — every thread on this page, for the sidebar. */
    public function index(WikiCollection $collection, WikiPage $page): JsonResponse
    {
        $this->guardRead($collection, $page);

        return response()->json(['threads' => $this->threads($page)]);
    }

    /**
     * POST — start a thread on a passage.
     *
     * The `uid` comes FROM THE CLIENT, which is unusual and deliberate: the editor has to write
     * the same id into the document's `<mark>`, and letting the server invent one would mean
     * either a second round trip or a document saved with a mark pointing at nothing. It is
     * validated for shape and uniqueness, so a collision or a crafted value is refused rather
     * than trusted — and it identifies a thread, it does not authorize anything.
     */
    public function store(Request $request, WikiCollection $collection, WikiPage $page): JsonResponse
    {
        $this->guardWrite($collection, $page);

        $data = $request->validate([
            'uid' => ['required', 'string', 'max:40', 'alpha_dash', 'unique:wiki_page_comment_threads,uid'],
            'quote' => ['nullable', 'string', 'max:2000'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $thread = DB::transaction(function () use ($data, $collection, $page) {
            $thread = WikiPageCommentThread::create([
                'wiki_collection_id' => $collection->id,
                'wiki_page_id' => $page->id,
                'uid' => $data['uid'],
                // Trimmed and capped, because it is a QUOTE of the passage and not the passage
                // itself — a thread anchored to three pages of text still shows one line.
                'quote' => Str::limit(trim((string) ($data['quote'] ?? '')), 300) ?: null,
                'status' => WikiPageCommentThread::STATUS_OPEN,
                'created_by' => Auth::id(),
            ]);

            $thread->comments()->create(['user_id' => Auth::id(), 'body' => $data['body']]);

            return $thread;
        });

        return $this->answer($thread, WikiPageCommentEvent::CREATED);
    }

    /** POST — a reply. Read access is enough; see the class note. */
    public function reply(Request $request, WikiCollection $collection, WikiPage $page, WikiPageCommentThread $thread): JsonResponse
    {
        $this->guardRead($collection, $page);
        $this->guardThread($thread, $page);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $thread->comments()->create(['user_id' => Auth::id(), 'body' => $data['body']]);

        return $this->answer($thread, WikiPageCommentEvent::REPLIED);
    }

    /** PATCH — edit your own message. */
    public function update(Request $request, WikiCollection $collection, WikiPage $page, WikiPageCommentThread $thread, WikiPageComment $comment): JsonResponse
    {
        $this->guardRead($collection, $page);
        $this->guardThread($thread, $page);
        abort_unless((int) $comment->thread_id === (int) $thread->id, 404);
        // Yours alone — an editor may resolve anybody's thread and may not rewrite their words.
        abort_unless($comment->writtenBy(Auth::user()), 403, 'You can only edit your own comments.');

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $comment->update(['body' => $data['body'], 'edited_at' => now()]);

        return $this->answer($thread, WikiPageCommentEvent::UPDATED);
    }

    /**
     * DELETE — remove your own message.
     *
     * Deleting the ONLY message deletes the thread, mark and all: an anchored conversation with
     * nothing in it is a highlight nobody can explain. Deleting a reply leaves the thread.
     */
    public function destroy(WikiCollection $collection, WikiPage $page, WikiPageCommentThread $thread, WikiPageComment $comment): JsonResponse
    {
        $this->guardRead($collection, $page);
        $this->guardThread($thread, $page);
        abort_unless((int) $comment->thread_id === (int) $thread->id, 404);
        abort_unless($comment->writtenBy(Auth::user()), 403, 'You can only delete your own comments.');

        $comment->delete();

        if ($thread->comments()->count() === 0) {
            // The card is taken BEFORE the delete: it is what the broadcast carries, and every
            // other tab needs to be told which thread went away.
            $card = $thread->fresh(['resolver'])->toCard();
            $thread->delete();

            $this->broadcast($page, WikiPageCommentEvent::DELETED, $card + ['removed' => true]);

            return response()->json([
                'ok' => true,
                'removed' => true,
                'threads' => $this->threads($page),
            ]);
        }

        return $this->answer($thread, WikiPageCommentEvent::DELETED);
    }

    /** POST — resolve, or reopen. Write access: it changes what everyone else sees. */
    public function resolve(Request $request, WikiCollection $collection, WikiPage $page, WikiPageCommentThread $thread): JsonResponse
    {
        $this->guardWrite($collection, $page);
        $this->guardThread($thread, $page);

        $resolved = $request->boolean('resolved', true);

        $thread->update([
            'status' => $resolved ? WikiPageCommentThread::STATUS_RESOLVED : WikiPageCommentThread::STATUS_OPEN,
            'resolved_by' => $resolved ? Auth::id() : null,
            'resolved_at' => $resolved ? now() : null,
        ]);

        return $this->answer(
            $thread,
            $resolved ? WikiPageCommentEvent::RESOLVED : WikiPageCommentEvent::REOPENED,
        );
    }

    /**
     * POST — the marks that are still in the document, told by the editor after a save.
     *
     * This is how "referenced text deleted" is discovered. The client cannot be trusted to
     * decide a thread's fate, and it is not asked to: it reports which uids it still has, and
     * the server sets a FLAG. Nothing is deleted, ever — a thread whose passage is gone keeps
     * every message and says so in the sidebar, which is exactly what the requirement asks for.
     * A thread whose mark comes back (an undo) is un-flagged just as quietly.
     */
    public function anchors(Request $request, WikiCollection $collection, WikiPage $page): JsonResponse
    {
        $this->guardWrite($collection, $page);

        $data = $request->validate([
            'uids' => ['present', 'array'],
            'uids.*' => ['string', 'max:40'],
        ]);

        $present = array_values(array_filter($data['uids']));

        WikiPageCommentThread::query()->where('wiki_page_id', $page->id)
            ->whereIn('uid', $present)->where('anchor_missing', true)
            ->update(['anchor_missing' => false]);

        WikiPageCommentThread::query()->where('wiki_page_id', $page->id)
            ->when($present !== [], fn ($q) => $q->whereNotIn('uid', $present))
            ->where('anchor_missing', false)
            ->update(['anchor_missing' => true]);

        return response()->json(['ok' => true, 'threads' => $this->threads($page)]);
    }

    /* ---------------------------------------------------------------- */

    /** @return array<int, array<string, mixed>> */
    private function threads(WikiPage $page): array
    {
        return WikiPageCommentThread::query()
            ->where('wiki_page_id', $page->id)
            ->with(['comments.author', 'resolver'])
            ->orderBy('id')
            ->get()
            ->map(fn (WikiPageCommentThread $t) => $t->toCard())
            ->all();
    }

    /** The one answer every mutating method gives: the thread, the list, and the broadcast. */
    private function answer(WikiPageCommentThread $thread, string $type): JsonResponse
    {
        $fresh = $thread->fresh(['comments.author', 'resolver']);
        $card = $fresh->toCard();

        $this->broadcast($fresh->page, $type, $card);

        return response()->json([
            'ok' => true,
            'thread' => $card,
            'threads' => $this->threads($fresh->page),
        ]);
    }

    /**
     * Tell the other tabs — and never let that failing cost the comment.
     *
     * `ShouldBroadcastNow` publishes SYNCHRONOUSLY, so with Reverb down the dispatch throws
     * and takes the whole request with it: the comment is already written, the transaction is
     * already committed, and the author is told their comment failed. That is the wrong way
     * round. Real-time is an ADDITION to a page that already works over HTTP — every other
     * client will see this thread on their next load regardless.
     *
     * Logged rather than swallowed: a socket that is down for a week should be visible to
     * whoever reads the logs, just not to whoever is writing a comment.
     */
    private function broadcast(WikiPage $page, string $type, array $card): void
    {
        try {
            WikiPageCommentEvent::dispatch(
                (string) $page->tenant_id,
                (int) $page->id,
                $type,
                $card,
                (int) Auth::id(),
            );
        } catch (\Throwable $e) {
            Log::warning('Wiki comment broadcast failed', [
                'page_id' => $page->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function guardRead(WikiCollection $collection, WikiPage $page): void
    {
        abort_unless($this->apps->isEnabled(Auth::user()->currentWorkspace, 'wiki'), 404);
        abort_unless((int) $page->wiki_collection_id === (int) $collection->id, 404);
        abort_unless($collection->openableBy(Auth::user()), 403);
    }

    private function guardWrite(WikiCollection $collection, WikiPage $page): void
    {
        abort_unless($this->apps->isEnabled(Auth::user()->currentWorkspace, 'wiki'), 404);
        abort_unless((int) $page->wiki_collection_id === (int) $collection->id, 404);
        abort_unless($collection->writableBy(Auth::user()), 403);
    }

    /** A thread from another page must 404 here, whatever the URL says it is. */
    private function guardThread(WikiPageCommentThread $thread, WikiPage $page): void
    {
        abort_unless((int) $thread->wiki_page_id === (int) $page->id, 404);
    }
}
