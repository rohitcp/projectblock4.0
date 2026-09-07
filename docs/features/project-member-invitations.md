# Project Member Invitations

Extends [Project Member Management](project-members.md) where that exists, and reuses the
workspace invitation flow entirely.

## Requirement

Inviting somebody to a project from **Project Settings → Members** sends them an email. The
email names the project, the workspace and the person who invited them, and carries a link that
gets them into the project — signing in if they have an account, through account setup if they
do not.

## The decision everything follows from

**A project invitation IS a workspace invitation carrying a project.**

Project membership is layered on workspace membership — you cannot be in a project without
being in the workspace. There is already an invitation system that does the hard parts: one
token (stored only as a hash), one expiry rule, one seat check, one acceptance screen, one
onboarding path for people with no account, one revoke, one resend.

So this feature adds no second invitation system. It sends the workspace invitation with the
project as its **context** — the same mechanism the Help Center uses for Spaces — and leaves a
placeholder behind that a listener completes on acceptance. "Guided through account setup"
comes free, because that is the path the workspace invitation already walks.

## One path, whichever half of the dialog you used

Picking a coworker from the workspace list and typing an address both go through
`ProjectInviter`, resolved to an email address first.

They used to diverge: the picker called `ProjectMemberManager` directly and added the person in
**silence**, so being put on a project told you nothing unless you happened to look. Only the
typed-address path emailed. Whether somebody was told depended on which half of the dialog the
admin happened to use — not a rule anybody could have guessed.

Eligibility (§26) and duplicate rejection (§25) are unchanged: `ProjectInviter` delegates the
actual add to the same `ProjectMemberManager`.

## Two people can be at that address

| | What happens | Email |
|---|---|---|
| **Already an active workspace member** | Added to the project immediately | "You've been added to **{Project}**", with a direct link to it |
| **Not in the workspace** | Workspace invitation sent, placeholder `project_members` row created | "You have been invited to a project", with the acceptance link |

An acceptance step for the first case would be a link that logs somebody into an account they
are already logged into. They get told, and given the way in.

## Data

`project_members` gained `email`, `invited_role`, `workspace_invitation_id`, `invited_at`, and
`user_id` became **nullable**. An invitation's row exists before the person does, so `email` is
what identifies them until they accept.

That is deliberately the same shape `help_center_space_members` already uses for the same
problem. Two tables that agree are easier to reason about than one clever shared one that has
to know which module it is in — and `LinkProjectMemberships` is the same idea as
`LinkHelpCenterSpaceMemberships`, answering the same event without either knowing about the
other.

`unique(project_id, email)` is what stops a second invitation to the same address. The existing
`unique(project_id, user_id)` cannot do that job: MySQL allows any number of NULLs in a unique
index, so every placeholder would slip straight past it.

## Acceptance

`LinkProjectMemberships` listens for `WorkspaceInvitationAccepted` and fills the placeholder in:
`user_id` is set, the promised `invited_role` becomes the real `role`, and `invited_role` and
`email` are cleared — a membership must have one answer to "who is this" and "what are they", and
keeping copies is how the two drift.

It runs inside the workspace's own tenancy context. `ProjectMember` is tenant-scoped and
acceptance arrives on a public link where no tenancy is established; without that the query
finds nothing and fails silently, which is exactly the bug the listener exists to prevent.

A placeholder for somebody who is already on the project by another route is deleted rather than
filled in — it would violate `unique(project_id, user_id)`.

## Invited to a second project in the same workspace

`WorkspaceInviter` refuses a second pending invitation for one address. Right for the
workspace, and useless here: the admin asked for that person to be on **this** project, and the
email they already have says nothing about it.

Refusing was the bug behind "the invitation email shows the wrong project". Inviting somebody to
Project A and then to Project B left them with:

- an email naming **Project A** — the only one ever sent;
- a placeholder for A and **none for B**;
- no membership for B, so any link to it answered **403**;
- and an admin told "already invited", which was true of the workspace and not of the project.

So the outstanding invitation is now **resent carrying this project's context**, and a
placeholder for this project is created against it. One invitation, one seat, one acceptance —
and it grants every project the address was invited to. Each email names the project it was
actually sent for.

That is the same answer `WorkspaceInviter::resend()` already documents for a Help Center Space.

### Where they land

`LinkProjectMemberships` orders placeholders **newest first** and leaves that project's id in
the session. One invitation can carry several projects, and the one they are meant to land on is
the one the email they just clicked was about — the most recent. Taking whichever row came back
first sent people to a different project than the message that brought them.

`PendingInvitationController` reads that id and points the "you've joined" screen's button at
the project. Without it an invitee lands on the workspace home and has to go and find what they
were invited to — and guessing at a project they were not invited to is a 403 that reads as the
invitation not having worked.

## Email failure, and resend

`WorkspaceInviter` reports rather than throws when delivery fails, and the placeholder is written
either way. So a failed send leaves an invitation that can be **resent**, which is the
requirement's own rule.

Re-inviting an address that already has a placeholder **resends on a fresh token** rather than
writing a second row. It has to be a fresh one: only the SHA-256 hash of the token was ever
stored, so "send the same link again" is not a thing that can be done — and reissuing is safer
anyway, because one working link exists at a time.

The Members grid marks those rows **Invited** and the row menu offers **Resend Invitation**.

## Permissions

Unchanged — `manageMembers` on the project (§17), re-checked server-side on every action (§24).
Inviting consumes a workspace seat, and the seat check is `WorkspaceInviter`'s, applied per
recipient at send time and revalidated at acceptance.

## Acceptance Criteria

1. Inviting a new address sends an email naming the project, the workspace and the inviter.
2. The link accepts the invitation and the invitee lands with project access at the invited role.
3. Somebody with no account is taken through account setup first, then into the project.
4. Somebody already in the workspace is added immediately and emailed a link to the project.
5. Inviting the same address twice resends and leaves **one** member row.
6. A pending row shows as **Invited** and can be resent from the row menu.
7. A failed email never rolls back the invitation.

## Verified

Driven directly against the local database with the mail captured by
`redberry/mailbox-for-laravel`:

| | Result |
|---|---|
| invite a new address | `invited`; placeholder written with role and invitation id |
| the email | subject, project name, workspace name, "invited you", accept link — all present |
| accept it | membership created at the invited role; `invited_role` and `email` cleared; **0** placeholders left |
| invite the same address twice | `invited` then `resent`; **1** row |
| invite somebody already on the project | `already_member`; no email |
| invite an existing workspace member | `added`; "You've been added to…" with a direct project link and the right role |
| add a coworker from the picker | `added`; one email sent — the path that used to be silent |
| invite one address to project 13 then project 10 | both `invited`; two placeholders on ONE invitation; each email names its own project |
| accept that invitation | member of **both** projects; lands on 10, the most recent email's project |
| click the same link again | accepted, no 403, memberships unchanged |
| a different user clicks that link | refused, `email_mismatch` |

## Files

| File | |
|---|---|
| `app/Services/ProjectInviter.php` | Decides add-vs-invite, sends, resends. |
| `app/Listeners/LinkProjectMemberships.php` | Completes the placeholder on acceptance. |
| `app/Mail/ProjectMemberAddedMail.php` + `emails/project-member-added.blade.php` | The "added" email. |
| `app/Http/Controllers/Project/ProjectMembersController.php` | `store` (add or invite) and `resend`. |
| `public/assets/js/projects/members.js` | The email field, the Invited badge, Resend. |
| `database/migrations/2026_09_30_000004_add_invitations_to_project_members_table.php` | The columns. |
