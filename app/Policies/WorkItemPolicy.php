<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkspaceMembership;

/**
 * Authorizes Work Items (Phase 5, requirements §7).
 *
 * Two layers, both enforced server-side and never only hidden in the UI:
 *
 * 1. **Reach** — a user may only touch work items of a project they can open, so every
 *    ability defers to ProjectPolicy@view. A project they cannot see 404s before this runs.
 * 2. **Read-only vs. contributor** — workspace Owner/Admin/Member may create and mutate;
 *    Viewer and Guest may open the list and detail but cannot change anything. This mirrors
 *    ProjectPolicy@create's role split, so "who can add work" reads the same everywhere.
 */
class WorkItemPolicy
{
    /** Can the user open a project's Work Items list? */
    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('view', $project);
    }

    /**
     * Can the user open this specific work item?
     *
     * Reach first, then §10's Work Item View: a project set to "assigned work items only"
     * shows an ordinary member just the items they are an assignee of. Enforced here rather
     * than by hiding rows, so a hand-typed URL or a direct API call is refused too (§10
     * Security Requirement) — 404 at the controller, which never confirms the item exists.
     */
    public function view(User $user, WorkItem $item): bool
    {
        if (! $user->can('view', $item->project)) {
            return false;
        }

        return $this->canSeeEveryItem($user, $item->project) || $this->isAssignee($user, $item);
    }

    /**
     * Who keeps sight of every work item regardless of the setting (§10): workspace
     * owners/admins, this project's admins, and the project lead — the people who have to
     * administer the project rather than only work in it.
     */
    public function canSeeEveryItem(User $user, Project $project): bool
    {
        if (! $project->restrictsToAssigned()) {
            return true;
        }

        if ((int) $project->lead_user_id === (int) $user->id) {
            return true;
        }

        if ($this->administersWorkspace($user, $project->tenant_id)) {
            return true;
        }

        return ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->exists();
    }

    private function isAssignee(User $user, WorkItem $item): bool
    {
        return $item->assignees()->whereKey($user->id)->exists();
    }

    /**
     * Can the user add a work item to this project?
     * Passed as `$user->can('create', [WorkItem::class, $project])`.
     */
    public function create(User $user, Project $project): bool
    {
        if (! $user->can('view', $project)) {
            return false;
        }

        if ($this->isProjectAdmin($user, $project)) {
            return true;
        }

        return $this->projectRole($user, $project) === self::ROLE_CONTRIBUTOR
            && (bool) config('projects.role_permissions.contributor_can_create');
    }

    /* ==========================================================================================
     * The work item permission matrix (docs/features/project-role-permissions.md §8)
     * ==========================================================================================
     *
     * ## What changed, and why it is more than a tightening
     *
     * `update` used to be the ONE ability behind every mutation — editing a title, changing a
     * status, deleting the item, and posting a comment all asked the same question, and it
     * answered "are you an Admin or a Contributor?". Two consequences followed from that, and
     * both are bugs against the matrix rather than merely coarse rules:
     *
     *   - a Commenter could not comment, because commenting required `update`;
     *   - a Contributor could edit, archive and DELETE any work item in the project, including
     *     ones belonging to somebody else.
     *
     * So the abilities below are per-ACTION, and most of them are also per-ITEM: the matrix
     * distinguishes "Contributor Assigned" from "Contributor Other", which a project-wide
     * answer cannot express.
     *
     * ## The shape of every rule
     *
     * Admin first (always yes), then the role, then — where the matrix says so — assignment.
     * Workspace Owner/Admin count as project Admins throughout (§18), which is why every rule
     * starts from `isProjectAdmin` rather than from the project membership row.
     *
     * ## Where the "Optional" cells live
     *
     * In config, NOT here — `projects.role_permissions`. Every Yes and every No in the matrix
     * is a rule in this file, because a permission somebody can quietly flip is not a
     * permission model; only the cells the requirement itself marks Optional are settings.
     */

    /** Roles, named once so a typo is a fatal error rather than a silently false permission. */
    private const ROLE_ADMIN = 'admin';

    private const ROLE_CONTRIBUTOR = 'contributor';

    private const ROLE_COMMENTER = 'commenter';

    private const ROLE_GUEST = 'guest';

    /**
     * Edit the work item itself — its title and description.
     *
     * Admin on anything; Contributor only on what is assigned to them. This is the ability the
     * rest of the application already asks for by name, so narrowing it here is what stops a
     * Contributor editing a colleague's item through any endpoint that has not been revisited.
     */
    public function update(User $user, WorkItem $item): bool
    {
        return $this->allows($user, $item, function (string $role, bool $assigned) {
            return $role === self::ROLE_CONTRIBUTOR && $assigned;
        });
    }

    /**
     * Delete — Admin only.
     *
     * It used to mirror `update`, on a reading of an older matrix that gave Delete to
     * Contributors. §8 does not: Delete is Yes for Admin and No in every other column,
     * including Contributor Assigned. Destroying somebody else's record of work is not part of
     * being able to do the work.
     */
    public function delete(User $user, WorkItem $item): bool
    {
        return $this->isProjectAdmin($user, $item->project);
    }

    /** Archive / restore — Admin only, for the same reason as delete. */
    public function archive(User $user, WorkItem $item): bool
    {
        return $this->isProjectAdmin($user, $item->project);
    }

    /**
     * Status, and "Mark as Done".
     *
     * The one mutation an assigned GUEST may perform (§5): a contractor given a task can say
     * they have finished it without being promoted to Contributor, which is the whole point of
     * that row in the matrix.
     */
    public function changeStatus(User $user, WorkItem $item): bool
    {
        return $this->allows($user, $item, function (string $role, bool $assigned) {
            return $assigned && in_array($role, [self::ROLE_CONTRIBUTOR, self::ROLE_GUEST], true);
        });
    }

    /** Priority — same rule as status. */
    public function changePriority(User $user, WorkItem $item): bool
    {
        return $this->changeStatus($user, $item);
    }

    /** Start and due dates. Optional for an assigned Guest, off by default. */
    public function changeDates(User $user, WorkItem $item): bool
    {
        return $this->allows($user, $item, function (string $role, bool $assigned) {
            if (! $assigned) {
                return false;
            }

            return $role === self::ROLE_CONTRIBUTOR
                || ($role === self::ROLE_GUEST && (bool) config('projects.role_permissions.guest_can_change_dates'));
        });
    }

    /** Labels. Optional for an assigned Guest, off by default. */
    public function manageLabels(User $user, WorkItem $item): bool
    {
        return $this->allows($user, $item, function (string $role, bool $assigned) {
            if (! $assigned) {
                return false;
            }

            return $role === self::ROLE_CONTRIBUTOR
                || ($role === self::ROLE_GUEST && (bool) config('projects.role_permissions.guest_can_manage_labels'));
        });
    }

    /**
     * Assign or reassign — Admin ONLY, deliberately, including for the assignee themselves.
     *
     * §2 is explicit: "Contributor should normally not be able to reassign their Work Item to
     * another user." Handing work on is a scheduling decision, not part of doing it, and a
     * Contributor who could reassign could also quietly make their own work somebody else's.
     */
    public function changeAssignee(User $user, WorkItem $item): bool
    {
        return $this->isProjectAdmin($user, $item->project);
    }

    /**
     * Cycle, Module, Epic, relations, sub-tasks, links, pages — the item's place in the
     * project's structure. Admin; Contributors only on their own items, and only when the
     * project turns it on.
     */
    public function manageStructure(User $user, WorkItem $item): bool
    {
        return $this->allows($user, $item, function (string $role, bool $assigned) {
            return $role === self::ROLE_CONTRIBUTOR
                && $assigned
                && (bool) config('projects.role_permissions.contributor_manages_structure');
        });
    }

    /**
     * Comment, reply and @mention.
     *
     * The widest of these abilities, and the one the old model got backwards: it required
     * `update`, so the Commenter role — whose entire purpose is commenting — could not.
     * Contributors may comment on ANY item, assigned or not (§2, "Other Work Items → View +
     * Comment"). A Guest may on their own assigned item, and elsewhere only if the project
     * allows it.
     */
    public function comment(User $user, WorkItem $item): bool
    {
        return $this->allows($user, $item, function (string $role, bool $assigned) {
            return match ($role) {
                self::ROLE_CONTRIBUTOR, self::ROLE_COMMENTER => true,
                self::ROLE_GUEST => $assigned || (bool) config('projects.role_permissions.guest_can_comment'),
                default => false,
            };
        });
    }

    /**
     * Attach a file TO THE WORK ITEM.
     *
     * Narrower than `comment` on purpose. The matrix reads "Comment only" for a Contributor on
     * somebody else's item and for a Commenter: they may attach to their own comment, which is
     * part of writing it, but not to the item, which is editing it.
     */
    public function attach(User $user, WorkItem $item): bool
    {
        return $this->allows($user, $item, function (string $role, bool $assigned) {
            return $assigned && in_array($role, [self::ROLE_CONTRIBUTOR, self::ROLE_GUEST], true);
        });
    }

    /** Activity and history. Optional for a Guest who is not assigned. */
    public function viewHistory(User $user, WorkItem $item): bool
    {
        return $this->allows($user, $item, function (string $role, bool $assigned) {
            return match ($role) {
                self::ROLE_CONTRIBUTOR, self::ROLE_COMMENTER => true,
                self::ROLE_GUEST => $assigned || (bool) config('projects.role_permissions.guest_can_view_history'),
                default => false,
            };
        });
    }

    /**
     * The shared shape of every rule above: must be able to see it, Admin always may, and
     * otherwise the caller decides from the role and whether they are the assignee.
     *
     * One place so the three preconditions cannot drift apart across a dozen abilities — and
     * so `isAssignee`, which costs a query, is evaluated only when a rule asks for it.
     *
     * @param  callable(string, bool): bool  $rule  ($projectRole, $isAssignee)
     */
    private function allows(User $user, WorkItem $item, callable $rule): bool
    {
        $project = $item->project;

        if (! $project || ! $user->can('view', $item)) {
            return false;
        }

        if ($this->isProjectAdmin($user, $project)) {
            return true;
        }

        $role = $this->projectRole($user, $project);

        if ($role === null) {
            return false;
        }

        return $rule($role, $this->isAssignee($user, $item));
    }

    /** Project Admin, or a Workspace Owner/Admin acting across every project (§18). */
    private function isProjectAdmin(User $user, Project $project): bool
    {
        return $this->administersWorkspace($user, $project->tenant_id)
            || $this->projectRole($user, $project) === self::ROLE_ADMIN;
    }

    /** This user's role on this project, or null when they hold no membership row. */
    private function projectRole(User $user, Project $project): ?string
    {
        return ProjectMember::roleFor($user->id, $project->id);
    }

    /**
     * ---- Drafts (docs/features/drafts.md §"User Roles") ----------------------------------
     *
     * A draft has no project, so none of the abilities above apply to one: they all start from
     * ProjectPolicy@view and a draft has nothing to view. These five answer the two questions
     * a draft actually raises — may this user keep drafts at all, and is this draft theirs.
     */

    /**
     * May the user keep drafts in the active workspace?
     *
     * Everyone except Viewer and Guest, who cannot create work items anywhere (§7) — for them
     * a draft would be a note they could never publish, so the screen is refused outright
     * rather than offered as a dead end (D-D6).
     */
    public function createDraft(User $user): bool
    {
        return in_array($this->workspaceRole($user, (string) $user->current_workspace_id), [
            WorkspaceMembership::ROLE_OWNER, 'admin', 'manager', 'member',
        ], true);
    }

    /**
     * May the user open this draft? Only its author — drafts are private (§4), and that holds
     * against workspace Owners and Admins too. The controller 404s rather than 403s, so a
     * refusal never confirms the draft exists.
     */
    public function viewDraft(User $user, WorkItem $draft): bool
    {
        return $draft->isDraft()
            && (int) $draft->created_by === (int) $user->id
            && $this->createDraft($user);
    }

    public function updateDraft(User $user, WorkItem $draft): bool
    {
        return $this->viewDraft($user, $draft);
    }

    public function deleteDraft(User $user, WorkItem $draft): bool
    {
        return $this->viewDraft($user, $draft);
    }

    /**
     * May the user turn this draft into a work item in this project?
     *
     * Both halves: it must be their draft, and the project must be one they could have created
     * the item in directly. Publishing is not a way around §16.
     */
    public function publishDraft(User $user, WorkItem $draft, Project $project): bool
    {
        return $this->viewDraft($user, $draft) && $this->create($user, $project);
    }

    /** Workspace Owner/Admin keep administrative access across every project (§18). */
    private function administersWorkspace(User $user, string $workspaceId): bool
    {
        return in_array($this->workspaceRole($user, $workspaceId), [
            WorkspaceMembership::ROLE_OWNER, 'admin',
        ], true);
    }

    private function workspaceRole(User $user, string $workspaceId): ?string
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->value('role');
    }
}
