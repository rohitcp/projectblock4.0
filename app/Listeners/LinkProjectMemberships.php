<?php

namespace App\Listeners;

use App\Events\WorkspaceInvitationAccepted;
use App\Models\ProjectMember;
use Illuminate\Support\Facades\Log;

/**
 * Finish a project membership when its invitation is accepted
 * (docs/features/project-member-invitations.md).
 *
 * Inviting somebody to a project by email leaves a `project_members` row with `user_id` null —
 * a placeholder that says "this address is expected here". Accepting the workspace invitation
 * is the moment that address becomes a person, and this is what turns the placeholder into a
 * membership. Without it the invitee would join the workspace and land nowhere near the project
 * they were actually invited to.
 *
 * A LISTENER and not a call inside the acceptance service, for the reason CLAUDE.md §6 gives
 * and `LinkHelpCenterSpaceMemberships` already demonstrates: the invitation flow has no
 * business knowing what projects are. Both listeners answer the same event and neither knows
 * about the other.
 *
 * Matched by INVITATION first and by address second. The invitation link is exact; the address
 * fallback catches a placeholder whose invitation could not be linked at send time —
 * `WorkspaceInviter` reports per-recipient statuses, and an address it refused has no
 * invitation row to point at.
 */
class LinkProjectMemberships
{
    /**
     * Where the just-joined project id is left for the post-acceptance screen.
     *
     * Public because it is a contract between this listener and the screen that reads it —
     * two files that deliberately do not otherwise know about each other.
     */
    public const SESSION_PROJECT_KEY = 'projects.joined_project_id';

    public function handle(WorkspaceInvitationAccepted $event): void
    {
        $email = mb_strtolower((string) $event->invitation->email);

        /*
         * Inside the workspace's own tenancy context.
         *
         * `ProjectMember` is tenant-scoped, and acceptance runs from a public invitation link
         * where no tenancy is established — without this the query finds nothing and fails
         * silently, which is precisely the bug this listener exists to prevent.
         */
        $event->workspace->run(function () use ($event, $email) {
            $rows = ProjectMember::query()
                ->whereNull('user_id')
                ->where(function ($q) use ($event, $email) {
                    $q->where('workspace_invitation_id', $event->invitation->id)
                        ->orWhere('email', $email);
                })
                // Newest first. One workspace invitation can hold placeholders for SEVERAL
                // projects — inviting the same address to a second project resends the
                // outstanding invitation rather than issuing a new one — and the project they
                // are meant to land on is the one the email they just clicked was about, which
                // is the most recent. Taking whichever row came back first sent people to a
                // different project than the message that brought them.
                ->orderByDesc('invited_at')
                ->orderByDesc('id')
                ->get();

            foreach ($rows as $row) {
                // Already on the project by some other route — the placeholder is redundant and
                // would violate unique(project_id, user_id) if it were filled in.
                $already = ProjectMember::query()
                    ->where('project_id', $row->project_id)
                    ->where('user_id', $event->user->id)
                    ->exists();

                if ($already) {
                    $row->delete();

                    continue;
                }

                $row->forceFill([
                    'user_id' => $event->user->id,
                    // The role the invitation promised becomes the real one. `invited_role` and
                    // `email` are cleared because a membership must have ONE answer to "who is
                    // this" and "what are they" — keeping copies is how the two drift.
                    'role' => $row->invited_role ?: $row->role,
                    'invited_role' => null,
                    'email' => null,
                ])->save();
            }

            if ($rows->isNotEmpty()) {
                /*
                 * Remember which project this acceptance was really about, so the
                 * "Welcome to {Workspace}" screen can send them into it rather than to a
                 * workspace home that says nothing about why they were invited.
                 */
                // `$rows` is newest-first, and a row that was already a membership has been
                // dropped from consideration above — so this is the project of the most recent
                // invitation that this acceptance actually granted.
                $landing = $rows->first(fn (ProjectMember $r) => $r->user_id !== null) ?? $rows->first();
                session([self::SESSION_PROJECT_KEY => $landing->project_id]);

                Log::info('project.invitation.linked', [
                    'invitation_id' => $event->invitation->id,
                    'user_id' => $event->user->id,
                    'projects' => $rows->pluck('project_id')->all(),
                ]);
            }
        });
    }
}
