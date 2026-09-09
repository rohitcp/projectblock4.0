<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectMember;
use App\Models\User;
use App\Mail\EmailActor;
use App\Mail\ProjectMemberRemovedMail;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Adds, re-roles and removes project members (Project Member Management §11/§13/§14).
 *
 * The rules live here rather than in the controller because they are the feature: every
 * mutation validates workspace containment (§26), rejects duplicates (§25), protects the
 * last remaining Project Admin (§20), and writes an audit event (§29) — in one transaction,
 * so a membership change and its audit record are never separated.
 */
class ProjectMemberManager
{
    /**
     * Add an existing, active workspace coworker to the project.
     *
     * @throws ValidationException when the user is not an eligible coworker or is already on
     *                             the project — the two rules §26 and §25 demand server-side.
     */
    public function add(Project $project, int $userId, string $role, ?User $actor): ProjectMember
    {
        $this->assertWorkspaceCoworker($project, $userId);

        if (ProjectMember::query()->where('project_id', $project->id)->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'That coworker is already a member of this project.',
            ]);
        }

        return DB::transaction(function () use ($project, $userId, $role, $actor) {
            /** @var ProjectMember $member */
            $member = ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $userId,
                'added_by' => $actor?->id,
                'role' => $role,
            ]);

            $this->log($project, $actor, ProjectActivity::EVENT_MEMBER_ADDED, $member, null, $role);

            return $member->fresh(['user', 'addedBy']);
        });
    }

    /**
     * Change a member's project role (§13).
     *
     * @throws ValidationException when it would leave the project without an Admin (§20).
     */
    public function updateRole(Project $project, ProjectMember $member, string $role, ?User $actor): ProjectMember
    {
        $previous = $member->role;

        if ($previous === $role) {
            return $member;
        }

        if ($member->isAdmin() && $role !== ProjectMember::ROLE_ADMIN) {
            $this->assertNotLastAdmin($project);
        }

        return DB::transaction(function () use ($project, $member, $role, $previous, $actor) {
            $member->forceFill(['role' => $role])->save();
            $this->log($project, $actor, ProjectActivity::EVENT_MEMBER_ROLE_CHANGED, $member, $previous, $role);

            return $member->fresh(['user', 'addedBy']);
        });
    }

    /**
     * Remove someone from the project (§14). Workspace membership is untouched — §14 is
     * explicit that this must not remove them from the workspace.
     *
     * @throws ValidationException when they are the last Admin (§20).
     */
    public function remove(Project $project, ProjectMember $member, ?User $actor): void
    {
        if ($member->isAdmin()) {
            $this->assertNotLastAdmin($project);
        }

        // Read BEFORE the delete. Afterwards the row is gone and `$member->user` resolves to
        // nothing, so the email would have no one to name and nowhere to go.
        $removed = $member->user;

        DB::transaction(function () use ($project, $member, $actor) {
            // A removed member cannot stay on as project lead.
            if ($member->user_id === $project->lead_user_id) {
                $project->forceFill(['lead_user_id' => null])->save();
            }

            $this->log($project, $actor, ProjectActivity::EVENT_MEMBER_REMOVED, $member, $member->role, null);
            $member->delete();
        });

        // AFTER the commit, never inside it: a mail queued in the transaction would still be
        // sent if the transaction then rolled back, telling somebody they had lost access they
        // still have.
        $this->sendRemovedEmail($project, $actor, $removed);
    }

    /**
     * Tell somebody their project access was withdrawn — the counterpart to the email they got
     * when they were added.
     *
     * Three cases are deliberately silent:
     *
     *   - a PENDING invitation, which has no user behind it. Withdrawing an invitation somebody
     *     never accepted is not a change to anything they had, and "you have been removed from
     *     a project" would be the first they heard of the project at all;
     *   - somebody removing THEMSELVES, who already knows;
     *   - a user row with no address.
     *
     * Delivery failure is logged and swallowed, on the same rule the add email uses: they ARE
     * removed, the screen already says so, and throwing here would report a completed removal
     * as a failure.
     */
    private function sendRemovedEmail(Project $project, ?User $actor, ?User $removed): void
    {
        if (! $removed || ! $removed->email) {
            return;
        }

        if ($actor && (int) $actor->id === (int) $removed->id) {
            return;
        }

        try {
            $workspace = Workspace::query()->withoutGlobalScopes()->find($project->tenant_id);

            Mail::to($removed->email)->send(new ProjectMemberRemovedMail(
                project: $project,
                workspaceName: (string) ($workspace->name ?? ''),
                removedByName: $actor?->displayName() ?? 'An administrator',
                recipientName: $removed->displayName(),
                actor: EmailActor::fromUser($actor, 'An administrator'),
            ));
        } catch (\Throwable $e) {
            Log::warning('project.member.removed_email_failed', [
                'project_id' => $project->id,
                'user_id' => $removed->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * §26: the target must be an active member of the project's OWN workspace. This is the
     * check that stops a user from another workspace being added.
     */
    private function assertWorkspaceCoworker(Project $project, int $userId): void
    {
        $eligible = WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->where('user_id', $userId)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->exists();

        if (! $eligible) {
            throw ValidationException::withMessages([
                'user_id' => 'Choose an active coworker from this workspace.',
            ]);
        }
    }

    /** §20: a project must always retain at least one Project Admin. */
    private function assertNotLastAdmin(Project $project): void
    {
        $admins = ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('role', ProjectMember::ROLE_ADMIN)
            ->count();

        if ($admins <= 1) {
            throw ValidationException::withMessages([
                'role' => 'This project must have at least one Project Admin. Assign another Admin before changing this member’s role.',
            ]);
        }
    }

    /** §29: actor, action, target member, project, previous role, new role, timestamp. */
    private function log(
        Project $project,
        ?User $actor,
        string $event,
        ProjectMember $member,
        ?string $oldRole,
        ?string $newRole,
    ): void {
        ProjectActivity::create([
            'project_id' => $project->id,
            'actor_id' => $actor?->id,
            'event' => $event,
            'target_user_id' => $member->user_id,
            // Denormalized so the entry still reads correctly if the user is later deleted.
            'target_name' => $member->user?->displayName(),
            'old_role' => $oldRole,
            'new_role' => $newRole,
        ]);
    }
}
