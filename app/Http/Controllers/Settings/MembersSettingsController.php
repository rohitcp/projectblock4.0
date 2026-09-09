<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\UpdateMemberRoleRequest;
use App\Http\Requests\Workspace\StoreInvitesRequest;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceInviter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Administration > Members (spec §5). People + Pending Invites, invite/revoke/role/remove.
 *
 * Memberships are CENTRAL (not tenant-scoped) so they are queried by workspace_id directly
 * and every mutation re-checks the target belongs to the active workspace. Invitations are
 * tenant-scoped, so their queries/route-binding are auto-confined. The Owner role is
 * protected: never assignable, never editable, never removable through this controller.
 */
class MembersSettingsController extends SettingsController
{
    /** GET /settings/members */
    public function show(): View
    {
        $this->guardManage();

        return $this->page('members', [
            'people' => $this->people(),
            'pending' => $this->pendingInvites(),
            'roleLabels' => config('workspace.roles'),
            'inviteRoles' => config('workspace.invite_roles'),
            'currentUserId' => Auth::id(),
            'endpoints' => [
                'invite' => route('settings.members.invite'),
                'role' => route('settings.members.role', ['membership' => '__ID__']),
                'remove' => route('settings.members.remove', ['membership' => '__ID__']),
                'revoke' => route('settings.members.revoke', ['invitation' => '__ID__']),
                'resend' => route('settings.members.resend', ['invitation' => '__ID__']),
            ],
        ]);
    }

    /** POST /settings/members/invite */
    public function invite(StoreInvitesRequest $request, WorkspaceInviter $inviter): JsonResponse
    {
        $this->guardManage();

        $results = $inviter->invite($this->workspace(), Auth::user(), $request->inviteRows());
        $sent = collect($results)->where('status', 'invited')->count();

        return response()->json([
            'ok' => true,
            'sent' => $sent,
            'results' => $results,
            'pending' => $this->pendingInvites(),
        ]);
    }

    /** PATCH /settings/members/{membership}/role */
    public function updateRole(UpdateMemberRoleRequest $request, WorkspaceMembership $membership): JsonResponse
    {
        $this->guardManage();
        $this->assertBelongsToWorkspace($membership);

        // Owner is protected — its role can never be changed here (SET-M-008).
        abort_if($membership->isOwner(), 422, 'The workspace owner role cannot be changed.');

        $membership->forceFill(['role' => $request->validated('role')])->save();

        return response()->json(['ok' => true, 'people' => $this->people()]);
    }

    /** DELETE /settings/members/{membership} */
    public function remove(WorkspaceMembership $membership): JsonResponse
    {
        $this->guardManage();
        $this->assertBelongsToWorkspace($membership);

        // Never remove the owner (SET-M-009 owner safeguard).
        abort_if($membership->isOwner(), 422, 'The workspace owner cannot be removed.');

        $membership->delete();

        return response()->json(['ok' => true, 'people' => $this->people()]);
    }

    /** DELETE /settings/members/invites/{invitation} — revoke a pending invite (SET-M-006). */
    public function revoke(WorkspaceInvitation $invitation): JsonResponse
    {
        $this->guardManage();

        if ($invitation->isPending()) {
            $invitation->forceFill(['status' => WorkspaceInvitation::STATUS_REVOKED])->save();
        }

        return response()->json(['ok' => true, 'pending' => $this->pendingInvites()]);
    }

    /**
     * POST /settings/members/invites/{invitation}/resend — send a pending invitation again.
     *
     * The counterpart to Revoke, and the answer to the only other thing that goes wrong with an
     * invitation: it never arrived. Without it the sole remedy was to revoke and re-invite,
     * which is two destructive-looking steps for "try that email again".
     *
     * `WorkspaceInviter::resend()` issues a FRESH token and re-stamps the expiry — only the
     * hash of the old one was ever stored, so the original link cannot be re-sent, and a
     * resend deliberately extends the window rather than mailing a link about to expire.
     * It returns false for an invitation that is no longer acceptable (already accepted,
     * revoked, or expired past recovery), which is a 422 rather than a silent success.
     */
    public function resend(WorkspaceInvitation $invitation, WorkspaceInviter $inviter): JsonResponse
    {
        $this->guardManage();
        $this->assertInvitationBelongsToWorkspace($invitation);

        abort_unless(
            $inviter->resend($this->workspace(), Auth::user(), $invitation),
            422,
            'That invitation can no longer be resent. Revoke it and invite them again.',
        );

        return response()->json([
            'ok' => true,
            'pending' => $this->pendingInvites(),
            'message' => 'Invitation sent again to '.$this->inviteeLabel($invitation).'.',
        ]);
    }

    /**
     * The invited person's real name, or null when we do not have one.
     *
     * NOT `displayName()`: that falls back to the local part of the address, which is neither
     * a name nor an address and reads like a truncation. Null here means "we hold no name",
     * which is what lets the caller choose the address instead.
     */
    private function inviteeName(WorkspaceInvitation $invitation): ?string
    {
        $user = $invitation->user;
        $name = trim((string) ($user?->display_name ?: $user?->full_name ?: ''));

        return $name !== '' ? $name : null;
    }

    /** What to call the invited person in a confirmation message: their name, else the address. */
    private function inviteeLabel(WorkspaceInvitation $invitation): string
    {
        return $this->inviteeName($invitation) ?? (string) $invitation->email;
    }

    /**
     * Guard: the invitation must belong to the active workspace.
     *
     * `WorkspaceInvitation` is tenant-scoped (CLAUDE.md D6), so route-model binding already
     * confines it — this is the belt to that braces, matching what `revoke` relies on and what
     * `assertBelongsToWorkspace` does for the central membership table beside it.
     */
    private function assertInvitationBelongsToWorkspace(WorkspaceInvitation $invitation): void
    {
        abort_unless((string) $invitation->tenant_id === (string) $this->workspace()->id, 404);
    }

    /** Guard: the membership must belong to the active workspace (memberships are central). */
    private function assertBelongsToWorkspace(WorkspaceMembership $membership): void
    {
        abort_unless($membership->workspace_id === $this->workspace()->id, 404);
    }

    /** @return array<int, array<string, mixed>> Active member rows (SET-M-001). */
    private function people(): array
    {
        // Scoped to THIS workspace and to active members only — a freshly created workspace
        // therefore lists just its owner until invitations are accepted.
        return WorkspaceMembership::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->with('user.identities')
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->get()
            ->map(fn (WorkspaceMembership $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user?->displayName(),
                'display' => $m->user ? strtok((string) $m->user->email, '@') : '',
                'initial' => $m->user?->initial(),
                'email' => $m->user?->email,
                'role' => $m->role,
                'status' => ucfirst((string) ($m->status ?? 'active')),
                'billing' => in_array($m->role, ['owner', 'admin', 'member'], true) ? 'Billable' : 'Free',
                'auth' => $this->authMethod($m),
                'joined' => optional($m->joined_at ?? $m->created_at)->format('M d, Y'),
                'is_owner' => $m->isOwner(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> Pending invitation rows (SET-M-005). */
    private function pendingInvites(): array
    {
        return WorkspaceInvitation::query()
            ->where('status', WorkspaceInvitation::STATUS_PENDING)
            ->with(['inviter', 'user'])
            ->latest()
            ->get()
            ->map(fn (WorkspaceInvitation $i) => [
                'id' => $i->id,
                'email' => $i->email,
                // Their name when the invited address already has an account, null when it
                // does not — most invitations go to somebody who has not signed up yet, and
                // for them the address genuinely IS the only identity we hold.
                'name' => $this->inviteeName($i),
                'role' => $i->role,
                'invited' => optional($i->created_at)->format('M d, Y'),
                'by' => $i->inviter?->displayName() ?? '—',
            ])
            ->all();
    }

    /** Best-effort authentication-method label from the user's linked identities. */
    private function authMethod(WorkspaceMembership $m): string
    {
        $provider = $m->user?->identities->first()?->provider;
        $map = config('settings.auth_methods');

        return $map[$provider] ?? 'Magic code';
    }
}
