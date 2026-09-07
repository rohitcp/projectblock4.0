<?php

namespace App\Http\Controllers\Invitation;

use App\Http\Controllers\Controller;
use App\Listeners\LinkHelpCenterSpaceMemberships;
use App\Listeners\LinkProjectMemberships;
use App\Models\WorkspaceInvitation;
use App\Services\OnboardingRouter;
use App\Services\WorkspaceInvitationAccepter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The join step for a signed-in invited user (invite spec §28, §33, §49).
 *
 * This is where onboarding hands over: a user who finished profile/role/goals and has an
 * invitation waiting is sent here instead of to Create Workspace (§29), and joining completes
 * their signup. It takes no token in the URL, because `OnboardingRouter` can only name a
 * route — the invitation is found from the signed-in user's own email (§32).
 */
class PendingInvitationController extends Controller
{
    public function __construct(
        private readonly WorkspaceInvitationAccepter $accepter,
        private readonly OnboardingRouter $router,
    ) {}

    /** GET /invite — "Join {Workspace}" for the invitation this user is here for. */
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();
        $invitation = $this->resolve();

        if (! $invitation) {
            // Nothing to join (revoked or expired while they were signing up): fall back to
            // wherever they would otherwise belong.
            return redirect()->route($this->router->postOnboardingRoute($user));
        }

        // The invitation outlived its workspace. Say so rather than redirect: the router
        // would send them straight back here, since it sees the same pending invitation.
        if (! $invitation->workspace) {
            return view('invitations.unavailable', ['state' => 'workspace_unavailable']);
        }

        return view('invitations.pending', [
            'workspace' => $invitation->workspace,
            'invitation' => $invitation,
            'inviterName' => $invitation->inviter?->displayName() ?? 'A workspace admin',
            'roleLabel' => config('workspace.roles')[$invitation->role] ?? ucfirst($invitation->role),
            'error' => session('invitation_error'),
        ]);
    }

    /** POST /invite/join — activate the membership (§33/§37). */
    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $invitation = $this->resolve();

        if (! $invitation) {
            return redirect()->route($this->router->postOnboardingRoute($user));
        }

        $result = $this->accepter->accept($invitation, $user);

        if (! $result['ok']) {
            return back()->with('invitation_error', $result['reason']);
        }

        $request->session()->forget('invitation_token');

        return redirect()->route('invitations.joined');
    }

    /** GET /invite/joined — the "Welcome to {Workspace}" confirmation (§49/§50). */
    public function joined(Request $request): View|RedirectResponse
    {
        $workspace = Auth::user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('welcome');
        }

        /*
         * Where this screen continues to.
         *
         * Normally the workspace. But an invitation sent from Help Center → Space → Add Member
         * was about a Space, and dropping somebody on the workspace home after they accepted it
         * leaves them to find their way to the thing they were invited to — which they cannot
         * do, because they have never seen this application before (P10).
         *
         * `LinkHelpCenterSpaceMemberships` leaves the id here when acceptance completed such a
         * membership. Pulled, so a refresh of a later screen does not resurrect it.
         */
        $spaceId = $request->session()->pull(LinkHelpCenterSpaceMemberships::SESSION_SPACE_KEY);
        // The same problem for a PROJECT invitation
        // (docs/features/project-member-invitations.md). Somebody invited to a project and
        // dropped on the workspace home has to go and find it — and if they guess at a project
        // they were not invited to, the answer is a 403 that reads as the invitation not having
        // worked. `LinkProjectMemberships` leaves the id of the project the email was actually
        // about, so this can take them straight there.
        $projectId = $request->session()->pull(LinkProjectMemberships::SESSION_PROJECT_KEY);

        $continueUrl = route('welcome');
        $continueLabel = 'Go to workspace';

        // The Space wins when both are set: only one of the two can have been the subject of
        // the email that brought them here, and a Space invitation is never sent from a
        // project screen.
        if ($spaceId) {
            $continueUrl = route('help-center.spaces.open', ['space' => $spaceId]);
            $continueLabel = 'Go to the Space';
        } elseif ($projectId) {
            $continueUrl = route('projects.work-items', ['project' => $projectId]);
            $continueLabel = 'Go to the project';
        }

        return view('invitations.joined', [
            'workspace' => $workspace,
            'continueUrl' => $continueUrl,
            'continueLabel' => $continueLabel,
        ]);
    }

    /**
     * The invitation this user is acting on: the one whose link they opened this session if
     * there is one, otherwise the newest pending invitation to their address (§83 — the same
     * person may be invited to several workspaces).
     */
    private function resolve(): ?WorkspaceInvitation
    {
        $user = Auth::user();

        $token = session('invitation_token');
        if ($token) {
            $invitation = WorkspaceInvitation::findByToken((string) $token);
            if ($invitation && $invitation->isAcceptable() && $invitation->email === strtolower((string) $user->email)) {
                return $invitation;
            }
        }

        return WorkspaceInvitation::pendingFor((string) $user->email)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }
}
