<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace administration is owner/admin only (CLAUDE.md §6; spec §3 / §13).
 *
 * On the ROUTES and not only on the links that lead to them. Hiding a menu entry is a
 * courtesy: it stops people walking into a page that is not theirs. It is not a permission —
 * anyone who has ever seen the URL, or guesses `/settings/general`, walks straight in. The
 * sidebar hides the entry AND this refuses the request, and only the second one is the rule.
 *
 * `manageSettings` rather than a role string: WorkspacePolicy already owns what "may
 * administer this workspace" means, and a second copy of that rule here is one that drifts.
 */
class EnsureWorkspaceAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        $workspace = $user?->currentWorkspace;

        // 404 rather than 403 on a missing workspace: somebody with no workspace resolved has
        // no settings to be refused from, and saying "forbidden" implies there is something
        // there for them.
        abort_if($user === null || $workspace === null, 404);
        abort_unless($user->can('manageSettings', $workspace), 403);

        return $next($request);
    }
}
