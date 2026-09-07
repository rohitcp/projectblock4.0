<?php

namespace App\Http\Controllers\Wiki;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WikiCollection;
use App\Models\WikiCollectionMember;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Who the wiki editor may offer when somebody types `@`
 * (docs/features/wiki-lexical-editor.md).
 *
 * ## The rule: whoever can OPEN the collection
 *
 * Not the whole workspace. Naming somebody who cannot read the page is a mention that leads
 * them to a 403 — and on a private collection it also tells the writer, by omission or
 * inclusion, who is on it. So the list is exactly the people the collection is open to:
 *
 *  - a PUBLIC collection is open to the workspace, so the list is its active members;
 *  - a PRIVATE one is open to its members, its creator, and workspace admins (manageableBy),
 *    which is what `openableBy` itself answers.
 *
 * Gated on READ, not write: a reader replying to a comment needs the same list as an editor
 * writing a paragraph, and neither changes anything by looking somebody up.
 *
 * The payload is deliberately identical to the project mention endpoint's, so the editor's
 * popup is written once and does not care which module it is in.
 */
class PageMentionController extends Controller
{
    public function __construct(private readonly WorkspaceApps $apps) {}

    /** GET /wiki/collections/{collection}/mentionable-users?search=roh */
    public function index(Request $request, WikiCollection $collection): JsonResponse
    {
        abort_unless($this->apps->isEnabled(Auth::user()->currentWorkspace, 'wiki'), 404);
        abort_unless($collection->openableBy(Auth::user()), 403);

        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $needle = mb_strtolower(trim((string) ($data['search'] ?? '')));

        $active = WorkspaceMembership::query()
            ->where('workspace_id', $collection->tenant_id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->pluck('user_id');

        if ($collection->isPrivate()) {
            // Named on it, or made it. Workspace admins reach it through manageableBy, which
            // is checked per user below rather than guessed at from a role list here.
            $named = WikiCollectionMember::query()
                ->where('wiki_collection_id', $collection->id)
                ->pluck('user_id')
                ->push($collection->created_by)
                ->filter()
                ->unique();

            $active = $active->intersect($named)->values();
        }

        if ($active->isEmpty()) {
            return response()->json(['ok' => true, 'users' => []]);
        }

        $users = User::query()
            ->whereIn('id', $active)
            ->when($needle !== '', fn ($q) => $q->where(function ($w) use ($needle) {
                $w->whereRaw('LOWER(full_name) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(display_name) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$needle}%"]);
            }))
            ->orderBy('full_name')
            // A bounded list, whatever was typed: the editor debounces, and this is what stops
            // a two-thousand-person workspace answering with two thousand rows.
            ->limit((int) config('projects.mention_results', 8))
            ->get();

        return response()->json([
            'ok' => true,
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->displayName(),
                'email' => $user->email,
                'initial' => $user->initial(),
                'avatar_url' => $user->avatar_url,
                // The fallback disc colour, so the popup's faces match the rest of the app.
                'avatar_color' => $user->avatarColor(),
            ])->values()->all(),
        ]);
    }
}
