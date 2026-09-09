# Project Roles — Work Item Permission Model

## Requirement

Project roles control what a member may do to Work Items. Admin has full control; Contributor
manages what is assigned to them and can view/comment elsewhere; Commenter discusses without
changing anything; Guest is read-only unless assigned, in which case they get limited task
execution.

## What changed

`update` used to be the ONE ability behind every mutation — editing a title, changing a status,
deleting the item, and posting a comment all asked the same question, and it answered "are you
an Admin or a Contributor?". Two consequences followed, both bugs against the matrix rather
than merely coarse rules:

- a **Commenter could not comment**, because commenting required `update`;
- a **Contributor could edit, archive and delete any work item** in the project, including
  somebody else's.

Abilities are now per-action, and most are also per-item, because the matrix distinguishes
"Contributor Assigned" from "Contributor Other" — a project-wide answer cannot express that.

## Abilities

`App\Policies\WorkItemPolicy` is the only place the matrix lives.

| Ability | Admin | Contributor assigned | Contributor other | Commenter | Guest assigned | Guest other |
|---|---|---|---|---|---|---|
| `view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `update` (title/description) | ✓ | ✓ | — | — | — | — |
| `changeStatus` | ✓ | ✓ | — | — | ✓ | — |
| `changePriority` | ✓ | ✓ | — | — | ✓ | — |
| `changeDates` | ✓ | ✓ | — | — | *optional* | — |
| `manageLabels` | ✓ | ✓ | — | — | *optional* | — |
| `changeAssignee` | ✓ | — | — | — | — | — |
| `manageStructure` (cycle/module/epic/relations/links/pages/estimate) | ✓ | *optional* | — | — | — | — |
| `comment` | ✓ | ✓ | ✓ | ✓ | ✓ | *optional* |
| `attach` (to the item) | ✓ | ✓ | — | — | ✓ | — |
| `delete` | ✓ | — | — | — | — | — |
| `archive` | ✓ | — | — | — | — | — |
| `viewHistory` | ✓ | ✓ | ✓ | ✓ | ✓ | *optional* |

Workspace Owner/Admin count as project Admins throughout.

### The optional cells

`config('projects.role_permissions')`. Only the cells the requirement itself marks *Optional*
are settings — every Yes and every No is a rule in the policy, because a permission somebody
can quietly flip is not a permission model.

| Key | Default | Why |
|---|---|---|
| `contributor_can_create` | **true** | What the app already did; turning it off would remove a capability as a side effect of adding config |
| `contributor_manages_structure` | false | Optional means off |
| `guest_can_change_dates` | false | §5 asks for the Guest extras to be off by default |
| `guest_can_manage_labels` | false | ” |
| `guest_can_comment` | false | An *assigned* Guest may always comment; this is the other case |
| `guest_can_view_history` | false | ” |

## Enforcement

**Server first.** §9 is explicit that hiding a control is not enforcement.

- **`PATCH /work-items/{item}` is authorized field by field.** Every chip PATCHes this one
  endpoint and its fields belong to different people — a single `can('update')` would have
  handed the Guest's status change to nobody and the Admin's reassignment to every
  Contributor. `WorkItemController::fieldAbilities()` maps field → ability; the first field the
  user does not hold refuses the **whole** request, because silently saving four of somebody's
  five changes is worse than saving none and saying which was refused.
- Archive/restore → `archive`. Delete → `delete`. Both Admin-only.
- Comments → `comment` (was `update`). Ownership rules for edit/delete are unchanged; an Admin
  may still moderate.
- Item attachments → `attach`, and removal additionally requires being the uploader or an
  Admin.
- Sub-tasks, relations, links, pages, and cycle/module/epic membership → `manageStructure`.
- Duplicating requires `view` on the source plus `create` on the project — copying is creating.

**Then the UI.** Each row payload carries an `abilities` map, and the chips read it per row:
`canEdit` was a single screen-wide boolean, which cannot be right about two rows that
legitimately disagree. A chip the viewer cannot use renders as plain text rather than a button
that would 403. Archive and Delete are hidden rather than disabled — a permanently greyed
Delete on every row is a standing reminder of something you may never do.

## Verified

Every cell of the matrix asserted against the database for all four roles × assigned/unassigned,
and the enforcement exercised through the real controllers:

- `title` — allowed only for Admin and assigned Contributor
- `assignee_ids` — denied for every non-Admin, including the assignee
- `priority` — allowed for assigned Guest; `due_date` and `label_ids` denied for them
- `comment` — allowed for Commenter and for Contributor on another's item; denied for an
  unassigned Guest

## Not covered

- **Board, Search results and Notifications** were not re-checked surface by surface. They read
  the same payload and the same policy, so they inherit the rules, but I have not opened each
  one.
- **Updates and Worklogs** still require `update`. They are not in the matrix; the effect is
  that only Admins and assigned Contributors may post them, which is defensible but is my
  reading rather than the requirement's.
- **Project-level settings UI** for the optional cells — they are config keys today, not a
  screen.
