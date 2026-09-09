<?php

namespace Tests\Feature\Project;

use App\Mail\ProjectMemberAddedMail;
use App\Models\ProjectMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * Adding somebody to a project emails them — and keeps doing so.
 *
 * ## Why this file exists
 *
 * This notification has broken and been fixed more than once, always silently: the send
 * succeeded, nothing threw, and the screen said "notified by email" while no email arrived.
 * Two causes, both of which these tests would have caught the day they appeared:
 *
 *   1. The mailable was `ShouldQueue` on a database queue with no worker running, so the mail
 *      became a row in `jobs` and stopped there.
 *   2. Whatever the invitation path was refactored into stopped calling the send at all.
 *
 * Cause 2 is caught by the outcome tests below. Cause 1 is NOT — `Mail::assertSent` passes for
 * a mailable that was only queued, which is measured, not assumed: re-adding `ShouldQueue` was
 * tried, and four of these five tests stayed green. That is exactly why
 * `test_the_mail_is_not_queued` exists as its own assertion rather than being left implicit.
 *
 * So these assert the OUTCOME — one mail, to the right person, carrying the right project —
 * and not the plumbing that happens to produce it today. A future refactor is free to move
 * the send anywhere it likes; it is not free to lose it.
 */
class ProjectMemberInvitationEmailTest extends ProjectTestCase
{
    use RefreshDatabase;

    /** The regression itself: Add Member → membership created → email sent, once, correctly. */
    public function test_adding_a_member_to_a_project_sends_them_one_email(): void
    {
        Mail::fake();

        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $sarah = $this->member($ws, 'member', 'sarah@example.com');

        $this->actingAs($owner)->postJson(route('projects.settings.members.store', $project), [
            'user_id' => $sarah->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ])->assertOk();

        // The membership really exists — an email about a member who was not added would be
        // the other half of this bug.
        $this->assertTrue($ws->run(fn () => ProjectMember::query()
            ->where('project_id', $project->id)->where('user_id', $sarah->id)->exists()));

        Mail::assertSent(ProjectMemberAddedMail::class, function (ProjectMemberAddedMail $mail) use ($sarah, $project, $owner) {
            return $mail->hasTo($sarah->email)
                && $mail->projectName === $project->name
                && $mail->inviterName === $owner->displayName()
                // The CTA must open THIS project, which is the acceptance criterion that a
                // hardcoded or stale URL would quietly fail.
                && str_contains($mail->projectUrl, '/projects/'.$project->id.'/');
        });

        // Exactly one. Two sends is its own bug, and a duplicate is invisible in a mailbox
        // until somebody complains about it.
        Mail::assertSent(ProjectMemberAddedMail::class, 1);
    }

    /** The subject the requirement asks for, named so a template edit cannot drift from it. */
    public function test_the_subject_names_the_project(): void
    {
        Mail::fake();

        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB', 'name' => 'Website Redesign']);
        $sarah = $this->member($ws, 'member', 'sarah@example.com');

        $this->actingAs($owner)->postJson(route('projects.settings.members.store', $project), [
            'user_id' => $sarah->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ])->assertOk();

        Mail::assertSent(ProjectMemberAddedMail::class, function (ProjectMemberAddedMail $mail) {
            return $mail->envelope()->subject === "You've been added to Website Redesign";
        });
    }

    /**
     * The email carries everything §"Email should include" lists, and the rendered body
     * actually shows it — a variable passed to a template nobody prints is not "included".
     */
    public function test_the_email_carries_member_project_workspace_role_and_inviter(): void
    {
        Mail::fake();

        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB', 'name' => 'Website Redesign']);
        $sarah = $this->member($ws, 'member', 'sarah@example.com');

        $this->actingAs($owner)->postJson(route('projects.settings.members.store', $project), [
            'user_id' => $sarah->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ])->assertOk();

        Mail::assertSent(ProjectMemberAddedMail::class, function (ProjectMemberAddedMail $mail) use ($owner, $sarah, $ws) {
            $html = $mail->render();

            return str_contains($html, $sarah->displayName())      // member name
                && str_contains($html, 'Website Redesign')          // project
                && str_contains($html, $ws->name)                   // workspace
                && str_contains($html, 'Contributor')               // project role
                && str_contains($html, $owner->displayName())       // who added them
                && str_contains($html, 'Open Project')              // the CTA
                && str_contains($html, $mail->projectUrl);          // the link itself
        });
    }

    /**
     * A refused add sends nothing.
     *
     * "The email is sent only after the Project membership is successfully created" — so the
     * failure path is part of the requirement, not an edge case. Adding somebody who is
     * already on the project is the cheapest way to reach it.
     */
    public function test_a_refused_add_sends_no_email(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $sarah = $this->member($ws, 'member', 'sarah@example.com');

        $this->actingAs($owner)->postJson(route('projects.settings.members.store', $project), [
            'user_id' => $sarah->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ])->assertOk();

        // Faked only NOW, so the legitimate first email above is not what we are counting.
        Mail::fake();

        $this->actingAs($owner)->postJson(route('projects.settings.members.store', $project), [
            'user_id' => $sarah->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    /**
     * The mailable must not go back on the queue.
     *
     * The only test here that catches that. `Mail::assertSent` is satisfied by a mailable that
     * was merely pushed onto a queue, so every other test in this file stays green while the
     * email silently stops arriving — which is precisely how this bug kept coming back.
     */
    public function test_the_mail_is_not_queued(): void
    {
        $this->assertNotInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new ProjectMemberAddedMail(
                // Unsaved and unpersisted: the constructor only reads the name and builds the
                // URL, and this assertion is about the CLASS, not about any real project.
                project: tap(new \App\Models\Project, fn ($p) => $p->forceFill(['id' => 1, 'name' => 'X'])),
                workspaceName: 'W',
                inviterName: 'A',
                recipientName: 'B',
                role: ProjectMember::ROLE_CONTRIBUTOR,
            ),
            'ProjectMemberAddedMail must not be queued: with no worker running the mail becomes '
            .'a row in `jobs` and is never delivered, which is the regression this suite exists for.',
        );
    }
}
