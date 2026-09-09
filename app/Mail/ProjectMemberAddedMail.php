<?php

namespace App\Mail;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "You've been added to {Project}" (docs/features/project-member-invitations.md).
 *
 * The other half of a project invitation. Somebody who is already in the workspace has nothing
 * to accept — they have an account and the membership exists the moment it is written — so
 * sending them an acceptance link would be a link that logs them into an account they are
 * already logged into. They get told, and given the way in.
 *
 * Queued per CLAUDE.md §11. Everything it needs is a SCALAR, not an Eloquent model: a queued
 * mailable is rebuilt by a worker with no tenancy context, and re-resolving a tenant-scoped
 * project there would come back empty. The project's id and name are read here, in the
 * request, while the context still exists.
 *
 * ## NOT queued, deliberately — this is the regression that kept coming back
 *
 * It used to be `ShouldQueue`. With `QUEUE_CONNECTION=database` and no worker running, the
 * send succeeded, a row went into `jobs`, and nothing was ever delivered — while the screen
 * said "notified by email" and meant it. Nothing failed loudly, so the bug looked like it had
 * reappeared on its own every time somebody worked without a worker.
 *
 * `WorkspaceInviter` already applies the right rule to its own invitation: "an invitation
 * nobody receives is an invitation that did not happen, and it must not depend on a queue
 * worker being up." Being added to a project is the same kind of message, and now follows the
 * same rule. The cost is that adding a member waits on the mail transport; the alternative is
 * a notification that silently is not one.
 */
class ProjectMemberAddedMail extends Mailable
{
    use Queueable, SerializesModels;

    public readonly string $projectName;

    public readonly string $projectUrl;

    public readonly string $roleLabel;

    public function __construct(
        Project $project,
        public readonly string $workspaceName,
        public readonly string $inviterName,
        public readonly string $recipientName,
        string $role,
        /** Who added them — their photo, or their initials (§16). */
        public readonly ?EmailActor $actor = null,
    ) {
        $this->projectName = (string) $project->name;
        $this->projectUrl = route('projects.work-items', $project);
        // The roles config is a map of key => ['label' => …, 'description' => …], not
        // key => label. Reading the entry itself gave "Array to string conversion" and an
        // email that said "Array" where the role should be.
        $this->roleLabel = (string) (config('projects.roles.'.$role.'.label') ?? ucfirst($role));
    }

    public function envelope(): Envelope
    {
        // Names the project, because that is what the reader is deciding whether to care
        // about — and an inbox truncates everything after the first few words.
        return new Envelope(subject: "You've been added to {$this->projectName}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.project-member-added');
    }
}
