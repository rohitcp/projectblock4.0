<?php

namespace App\Services;

use App\Mail\ProjectMemberAddedMail;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Invite somebody to a project by email (docs/features/project-member-invitations.md).
 *
 * ## Two people can be at that address, and they need different things
 *
 * **Already in the workspace.** There is nothing to accept — they have an account and they can
 * reach the project the moment the row exists. So they are added straight away and TOLD, with
 * a link that opens the project. An acceptance step here would be a link that logs them into
 * an account they are already logged into.
 *
 * **Not in the workspace.** They cannot be a project member, because project membership is
 * layered on workspace membership. So this sends the WORKSPACE invitation that already exists,
 * carrying the project as its context, and leaves a placeholder `project_members` row behind.
 * `LinkProjectMemberships` completes that row when they accept — the same shape the Help
 * Center uses for Spaces, and the reason neither feature has its own acceptance flow.
 *
 * ## Nothing here invents a second invitation system
 *
 * One token, one expiry rule, one seat check, one accept screen, one onboarding path for people
 * with no account — all of it `WorkspaceInviter`'s, which is why a project invitation gets the
 * "guided through account setup" half of the requirement for free.
 *
 * ## A failed email never costs the row
 *
 * `WorkspaceInviter` reports rather than throws when delivery fails, and the placeholder is
 * created regardless. Re-inviting the same address then finds that placeholder and RESENDS on a
 * fresh token instead of writing a second row — which is the requirement's own rule, and the
 * reason `invite()` treats "already invited" as a resend rather than an error.
 */
class ProjectInviter
{
    public function __construct(
        private readonly WorkspaceInviter $inviter,
        private readonly ProjectMemberManager $members,
    ) {}

    /**
     * Invite one address to one project.
     *
     * @return array{status:string, email:string, member?:ProjectMember}
     */
    public function invite(Project $project, string $email, string $role, User $actor): array
    {
        $email = mb_strtolower(trim($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'invalid_email', 'email' => $email];
        }

        // From `tenant_id`, not a relation: a Project has no `workspace()` — it IS scoped to
        // the tenant, and the tenant is the workspace (CLAUDE.md D5). Reading a relation that
        // does not exist returns null, which is how this first reported every invitation as
        // "workspace unavailable".
        $workspace = Workspace::find($project->tenant_id);
        if (! $workspace) {
            return ['status' => 'workspace_unavailable', 'email' => $email];
        }

        /*
         * Everything below runs inside the WORKSPACE's tenancy context.
         *
         * `ProjectMember` is tenant-scoped, so `tenant_id` is stamped by BelongsToTenant from
         * the active context — and this service is reachable from places that have none: a
         * resend, a console command, a listener. Without this the placeholder insert fails on
         * "Field 'tenant_id' doesn't have a default value", after the invitation email has
         * already gone out. Establishing it here rather than relying on the caller is what
         * makes the outcome the same wherever it is called from.
         */
        return $workspace->run(fn () => $this->inviteWithin($project, $workspace, $email, $role, $actor));
    }

    /**
     * The body of {@see invite()}, inside the workspace's tenancy context.
     *
     * @return array{status:string, email:string, member?:ProjectMember}
     */
    private function inviteWithin(
        Project $project,
        Workspace $workspace,
        string $email,
        string $role,
        User $actor,
    ): array {
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        $membership = $user ? WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->where('user_id', $user->id)
            ->first() : null;

        // ---- already in the workspace: add and tell them ------------------------------
        if ($user && $membership && $membership->status === WorkspaceMembership::STATUS_ACTIVE) {
            if (ProjectMember::query()->where('project_id', $project->id)
                ->where('user_id', $user->id)->exists()) {
                return ['status' => 'already_member', 'email' => $email];
            }

            // Any placeholder left over from an earlier invitation to the same address is this
            // same person — it is replaced rather than left beside the real membership.
            ProjectMember::query()->where('project_id', $project->id)
                ->whereNull('user_id')->where('email', $email)->delete();

            $member = $this->members->add($project, $user->id, $role, $actor);
            $this->sendAddedEmail($project, $workspace, $actor, $user, $role);

            return ['status' => 'added', 'email' => $email, 'member' => $member];
        }

        // ---- not in the workspace: invite, and hold their place -----------------------
        $existing = ProjectMember::query()
            ->where('project_id', $project->id)
            ->whereNull('user_id')
            ->where('email', $email)
            ->first();

        $context = $this->context($project, $workspace->name ?? '', $actor);

        // A placeholder already here means this address was invited before. Resend rather than
        // refuse: the row is what the screen works from, and a second row would be the
        // duplicate the requirement forbids.
        if ($existing) {
            $sent = $existing->invitation
                && $this->inviter->resend($workspace, $actor, $existing->invitation, $context);

            if ($sent) {
                $existing->forceFill(['invited_role' => $role, 'invited_at' => now()])->save();

                return ['status' => 'resent', 'email' => $email, 'member' => $existing];
            }

            // The invitation behind it is gone, revoked or expired beyond resending — issue a
            // fresh one and re-point the placeholder at it.
            $result = $this->sendInvitation($workspace, $actor, $email, $context);
            if ($result['status'] !== 'invited') {
                return ['status' => $result['status'], 'email' => $email];
            }

            $existing->forceFill([
                'invited_role' => $role,
                'workspace_invitation_id' => $result['invitation']?->id,
                'invited_at' => now(),
            ])->save();

            return ['status' => 'invited', 'email' => $email, 'member' => $existing];
        }

        $result = $this->sendInvitation($workspace, $actor, $email, $context);

        /*
         * ALREADY INVITED to this workspace, but not to this project.
         *
         * `WorkspaceInviter` refuses a second pending invitation for one address, which is
         * right for the workspace and useless here: the admin asked for that person to be on
         * THIS project, and the email they already have says nothing about it. Refusing left
         * them with an invitation naming a different project, no membership for this one, and
         * an admin told "already invited" — which is how "the email shows the wrong project"
         * and a 403 on the link happen.
         *
         * So the outstanding invitation is RESENT carrying this project's context, and a
         * placeholder for this project is created against it. One invitation, one seat, one
         * acceptance — and it now grants every project the address was invited to.
         *
         * This is the same answer `WorkspaceInviter::resend()` already documents for a Help
         * Center Space, for the same reason.
         */
        if ($result['status'] === 'already_invited') {
            $pending = WorkspaceInvitation::query()
                ->where('email', $email)
                ->where('status', WorkspaceInvitation::STATUS_PENDING)
                ->latest('id')
                ->first();

            if ($pending && $this->inviter->resend($workspace, $actor, $pending, $context)) {
                $result = ['status' => 'invited', 'invitation' => $pending];
            }
        }

        if ($result['status'] !== 'invited') {
            return ['status' => $result['status'], 'email' => $email];
        }

        $member = DB::transaction(fn () => ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => null,
            'email' => $email,
            'added_by' => $actor->id,
            // `role` carries the eventual role too, so every reader that does not know about
            // invitations still sees something sensible; `invited_role` is what the acceptance
            // applies, and what says this row is still an invitation.
            'role' => $role,
            'invited_role' => $role,
            'workspace_invitation_id' => $result['invitation']?->id,
            'invited_at' => now(),
        ]));

        return ['status' => 'invited', 'email' => $email, 'member' => $member];
    }

    /**
     * Send the workspace invitation and hand back the row it created.
     *
     * `WorkspaceInviter::invite()` reports a per-recipient STATUS rather than the model, so the
     * invitation is looked up afterwards by the only thing that identifies it: the address, in
     * this workspace, still pending.
     *
     * @param  array{title:string, line:string, label:string, value:string}  $context
     * @return array{status:string, invitation:?WorkspaceInvitation}
     */
    private function sendInvitation(
        Workspace $workspace,
        User $actor,
        string $email,
        array $context,
    ): array {
        $results = $this->inviter->invite($workspace, $actor, [['email' => $email, 'role' => 'member']], $context);
        $status = $results[0]['status'] ?? 'failed';

        if ($status !== 'invited') {
            return ['status' => $status, 'invitation' => null];
        }

        // No nested run(): inviteWithin() is already inside this workspace's context, so the
        // tenant scope confines this to the right workspace on its own.
        $invitation = WorkspaceInvitation::query()
            ->where('email', $email)
            ->where('status', WorkspaceInvitation::STATUS_PENDING)
            ->latest('id')
            ->first();

        return ['status' => 'invited', 'invitation' => $invitation];
    }

    /**
     * "You have been added to a project" — for somebody who has nothing to accept.
     *
     * Delivery failure is logged and swallowed: they ARE a member, the screen already says so,
     * and throwing here would report a successful add as a failure. The same rule
     * `WorkspaceInviter` applies to its own send.
     */
    private function sendAddedEmail(Project $project, Workspace $workspace, User $actor, User $user, string $role): void
    {
        try {
            Mail::to($user->email)->send(new ProjectMemberAddedMail(
                project: $project,
                workspaceName: (string) ($workspace->name ?? ''),
                inviterName: $actor->displayName(),
                recipientName: $user->displayName(),
                role: $role,
            ));
        } catch (\Throwable $e) {
            Log::warning('project.member.added_email_failed', [
                'project_id' => $project->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * What the invitation email says this is about.
     *
     * The workspace invitation's own copy would say "join a workspace", which is not what
     * happened from the recipient's side — they were invited to a PROJECT, and an email that
     * never names it reads like a mis-sent message.
     *
     * @return array{title:string, line:string, label:string, value:string}
     */
    private function context(Project $project, string $workspaceName, User $actor): array
    {
        return [
            'title' => 'You have been invited to a project',
            'line' => $actor->displayName().' has invited you to the "'.$project->name
                .'" project in the '.$workspaceName.' workspace. Accept the invitation to get '
                .'access — you will land straight in the project.',
            'label' => 'Project',
            'value' => (string) $project->name,
        ];
    }
}
