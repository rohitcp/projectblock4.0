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
 * "You have been removed from a project" — the counterpart to ProjectMemberAddedMail.
 *
 * Being added was already announced; being removed was silent, so somebody's project simply
 * disappeared from their sidebar with no explanation and nothing to ask about. Telling them is
 * the same courtesy, and it is the version that does not read as a bug.
 *
 * Queued (CLAUDE.md §11), and everything is a scalar rather than an Eloquent model: the member
 * row is DELETED by the time a worker runs this, so anything resolved lazily would resolve to
 * nothing. The project is captured here for the same reason the added mail does it — its name
 * and the workspace link are read now, not later.
 */
class ProjectMemberRemovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public readonly string $projectName;

    /**
     * Where the CTA goes.
     *
     * NOT the project. They cannot open it any more, so a button pointing there would answer
     * a 404 that reads like the email was wrong. Their workspace is what they still have, and
     * it is where their remaining projects are.
     */
    public readonly string $workspaceUrl;

    public function __construct(
        Project $project,
        public readonly string $workspaceName,
        public readonly string $removedByName,
        public readonly string $recipientName,
        /** Who did it — their photo, or their initials (§16). */
        public readonly ?EmailActor $actor = null,
    ) {
        $this->projectName = (string) $project->name;
        $this->workspaceUrl = route('projects.index');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You've been removed from {$this->projectName} on Project Block");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.project-member-removed');
    }
}
