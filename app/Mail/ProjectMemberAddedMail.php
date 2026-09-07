<?php

namespace App\Mail;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
 */
class ProjectMemberAddedMail extends Mailable implements ShouldQueue
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
        return new Envelope(subject: "You've been added to {$this->projectName} on Project Block");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.project-member-added');
    }
}
