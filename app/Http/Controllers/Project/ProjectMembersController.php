<?php

namespace App\Http\Controllers\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\ProjectInviter;
use App\Services\ProjectMemberManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Project → Settings → Members (Project Member Management, whole document).
 *
 * Authorization is §17: Workspace Owner, Workspace Admin, or this project's own Admin. §24
 * is explicit that hiding the controls in the UI is not sufficient, so every action here
 * re-checks `manageMembers` server-side, and §28's checklist (workspace containment, project
 * ownership of the member row, valid role) is enforced in ProjectMemberManager.
 */
class ProjectMembersController extends ManagesProjectController
{
    public function __construct(
        private readonly ProjectMemberManager $members,
        private readonly ProjectInviter $inviter,
    ) {}

    /**
     * POST /projects/{project}/settings/members — add a coworker (§11), or invite one by
     * email (docs/features/project-member-invitations.md).
     *
     * One endpoint for both because it is one action from the screen's side: name somebody and
     * give them a role. Whether that ends in a membership or an invitation depends on whether
     * the address already belongs to this workspace, which is the SERVICE's question to answer
     * — the person clicking Add should not have to know, and the screen should not have to
     * decide which endpoint to call.
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->guardMembers($project);

        $data = $request->validate([
            'user_id' => ['required_without:email', 'nullable', 'integer'],
            'email' => ['required_without:user_id', 'nullable', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_keys(config('projects.roles')))],
        ]);

        /*
         * Picked from the workspace list, or typed as an address — BOTH go through
         * ProjectInviter, resolved to an address first.
         *
         * They used to diverge: the picker called ProjectMemberManager directly and added the
         * person in silence, so being put on a project told you nothing unless you happened to
         * look. Only the typed-address path emailed. Whether somebody was told depended on
         * which half of the dialog the admin happened to use, which is not a rule anybody
         * could have guessed.
         *
         * One path means one behaviour: added or invited, you are always emailed. Eligibility
         * (§26) and duplicate rejection (§25) are unchanged — ProjectInviter delegates the
         * actual add to the same ProjectMemberManager.
         */
        $email = (string) ($data['email'] ?? '');

        if (! empty($data['user_id'])) {
            $user = User::find((int) $data['user_id']);
            abort_unless($user !== null, 422, 'That coworker no longer exists.');
            $email = (string) $user->email;
        }

        $result = $this->inviter->invite($project, $email, $data['role'], Auth::user());

        // Reported as a VALIDATION error on the field that was actually USED, so the screen
        // shows it under the control the admin touched rather than as a toast with no anchor —
        // or worse, under an email field they never filled in.
        if (! in_array($result['status'], ['added', 'invited', 'resent'], true)) {
            throw ValidationException::withMessages([
                (empty($data['user_id']) ? 'email' : 'user_id')
                    => $this->inviteFailureMessage($result['status'], $result['email']),
            ]);
        }

        return $this->listResponse($project, $this->inviteMessage($result['status'], $result['email']));
    }

    /**
     * POST /projects/{project}/settings/members/{member}/resend — send it again.
     *
     * The requirement's own rule: an invitation whose email did not arrive must be resendable
     * without creating a second member row. `ProjectInviter` resends on a FRESH token, because
     * only the hash of the old one was ever stored.
     */
    public function resend(Project $project, ProjectMember $member): JsonResponse
    {
        $this->guardMembers($project);
        $this->assertBelongs($project, $member);

        abort_unless($member->isPending() && $member->email, 422, 'That member has already joined.');

        $result = $this->inviter->invite(
            $project,
            (string) $member->email,
            $member->invited_role ?: $member->role,
            Auth::user(),
        );

        if (! in_array($result['status'], ['added', 'invited', 'resent'], true)) {
            throw ValidationException::withMessages([
                'email' => $this->inviteFailureMessage($result['status'], $result['email']),
            ]);
        }

        return $this->listResponse($project, 'Invitation sent again to '.$result['email'].'.');
    }

    /** What the admin who clicked Add needs to read when it worked. */
    private function inviteMessage(string $status, string $email): string
    {
        return match ($status) {
            'added' => $email.' was added to the project and has been emailed.',
            'resent' => 'That invitation was already outstanding, so it has been sent again to '.$email.'.',
            default => 'Invitation sent to '.$email.'.',
        };
    }

    /** And when it did not. Each one says what to DO, not only what went wrong. */
    private function inviteFailureMessage(string $status, string $email): string
    {
        return match ($status) {
            'already_member' => $email.' is already a member of this project.',
            'suspended_member' => $email.' is a suspended member of this workspace. Reactivate them on Settings → Members first.',
            'no_seats' => 'This workspace has no seats left. Free a seat or upgrade the plan, then invite '.$email.' again.',
            'invalid_email' => 'That does not look like an email address.',
            'workspace_unavailable' => 'This project has no workspace to invite into.',
            default => 'The invitation to '.$email.' could not be sent. Try again in a moment.',
        };
    }

    /** PATCH /projects/{project}/settings/members/{member}/role — change a role (§13). */
    public function updateRole(Request $request, Project $project, ProjectMember $member): JsonResponse
    {
        $this->guardMembers($project);
        $this->assertBelongs($project, $member);

        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(config('projects.roles')))],
        ]);

        $this->members->updateRole($project, $member, $data['role'], Auth::user());

        return $this->listResponse($project, 'Project role updated successfully.');
    }

    /** DELETE /projects/{project}/settings/members/{member} — remove from project (§14). */
    public function remove(Project $project, ProjectMember $member): JsonResponse
    {
        $this->guardMembers($project);
        $this->assertBelongs($project, $member);

        $this->members->remove($project, $member, Auth::user());

        return $this->listResponse($project, 'Member removed from project.');
    }

    /**
     * §17 authorization. Separate from `guardManage()` so "who can manage members" can
     * diverge from "who can edit project settings" without touching call sites.
     */
    private function guardMembers(Project $project): void
    {
        abort_unless(Auth::user()->can('manageMembers', $project), 403);
    }

    private function assertBelongs(Project $project, ProjectMember $member): void
    {
        // §28: the member row must belong to this project — never trust the URL alone.
        abort_unless($member->project_id === $project->id, 404);
    }

    private function listResponse(Project $project, string $message): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'message' => $message,
            'members' => static::memberList($project),
            'candidates' => static::candidates($project),
        ]);
    }

    /**
     * The project's members, with both role layers (§6).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function memberList(Project $project): array
    {
        $workspaceRoles = WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->pluck('role', 'user_id');

        return ProjectMember::query()
            ->where('project_id', $project->id)
            ->with(['user', 'addedBy'])
            ->get()
            ->map(fn (ProjectMember $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                // A pending row has no user yet, so the ADDRESS is the name — otherwise an
                // invitation shows as a blank line and nobody can tell who is expected.
                'name' => $m->user?->displayName() ?? $m->email,
                'email' => $m->user?->email ?? $m->email,
                'initial' => $m->user?->initial() ?? mb_strtoupper(mb_substr((string) $m->email, 0, 1)),
                // The screen needs to tell an invitation from a membership: only one of them
                // can be given a role, removed as a person, or resent.
                'pending' => $m->isPending(),
                'invited_at' => $m->invited_at?->format('Y-m-d'),
                'workspace_role' => $workspaceRoles[$m->user_id] ?? null,
                'role' => $m->role,
                'added_by' => $m->addedBy?->displayName(),
                'added_at' => $m->created_at?->format('Y-m-d'),
                'is_lead' => $m->user_id === $project->lead_user_id,
            ])
            ->sortBy('name')->values()->all();
    }

    /**
     * Workspace coworkers eligible to be added (§8/§9): active workspace members only,
     * excluding anyone already on the project.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function candidates(Project $project): array
    {
        $existing = ProjectMember::query()->where('project_id', $project->id)->pluck('user_id');

        return WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->whereNotIn('user_id', $existing)
            ->with('user')
            ->get()
            ->map(fn (WorkspaceMembership $m) => [
                'id' => $m->user_id,
                'name' => $m->user?->displayName(),
                'email' => $m->user?->email,
                'initial' => $m->user?->initial(),
                'workspace_role' => $m->role,
            ])
            ->sortBy('name')->values()->all();
    }
}
