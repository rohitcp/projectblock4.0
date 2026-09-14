<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Mail\EmailActor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * Renders every transactional email against sample data, at /dev/emails (local only).
 *
 * The shared shell (docs/features/transactional-emails.md, §20) is only worth having if a
 * change to it can be checked against every email at once. Without this the alternative is
 * triggering nine real workflows and reading the mailbox, which is slow enough that in
 * practice the shell gets changed and only one email gets looked at.
 *
 * Local only, and dev-only in the same sense the email log viewer beside it is: it exposes
 * nothing real, but a page that renders arbitrary notification templates has no place on a
 * deployed host.
 */
class EmailPreviewController extends Controller
{
    /** Every preview, keyed by the slug in its URL. */
    public static function samples(): array
    {
        $actor = new EmailActor('Rohit Philip', null, 'R', 1);
        $url = rtrim(config('app.url'), '/');

        return [
            'login-code' => [
                'label' => 'Verification / one-time code',
                'view' => 'emails.login-code',
                'data' => ['code' => '482194', 'ttlMinutes' => 10],
            ],
            'backoffice-code' => [
                'label' => 'Back Office code',
                'view' => 'emails.backoffice-code',
                'data' => ['code' => '739155', 'ttlMinutes' => 10],
            ],
            'password-reset' => [
                'label' => 'Reset password',
                'view' => 'emails.password-reset',
                'data' => [
                    'resetUrl' => $url.'/backoffice/reset-password/sample-token',
                    'ttlMinutes' => 60,
                    'recipientName' => 'Rohit Philip',
                ],
            ],
            'workspace-invitation' => [
                'label' => 'Workspace invitation',
                'view' => 'emails.workspace-invitation',
                'data' => [
                    'workspaceName' => 'ProjectBlock Development',
                    'inviterName' => 'Rohit Philip',
                    'invitedEmail' => 'mike@example.com',
                    'roleLabel' => 'Member',
                    'acceptUrl' => $url.'/invitations/sample-token',
                    'expiresOn' => 'September 15, 2026',
                    'workspaceLogoUrl' => null,
                    'contextTitle' => null,
                    'contextLine' => null,
                    'contextLabel' => null,
                    'contextValue' => null,
                    'actor' => $actor,
                ],
            ],
            'project-member-added' => [
                'label' => 'Added to a project',
                'view' => 'emails.project-member-added',
                'data' => [
                    'projectName' => 'Website Redesign',
                    'workspaceName' => 'ProjectBlock Development',
                    'inviterName' => 'Rohit Philip',
                    'recipientName' => 'Mike',
                    'roleLabel' => 'Contributor',
                    'projectUrl' => $url.'/projects/1/work-items',
                    'actor' => $actor,
                ],
            ],
            'project-member-removed' => [
                'label' => 'Removed from a project',
                'view' => 'emails.project-member-removed',
                'data' => [
                    'projectName' => 'Website Redesign',
                    'workspaceName' => 'ProjectBlock Development',
                    'removedByName' => 'Rohit Philip',
                    'recipientName' => 'Mike',
                    'workspaceUrl' => $url.'/projects',
                    'actor' => $actor,
                ],
            ],
            'work-item-assigned' => [
                'label' => 'Work item assigned',
                'view' => 'emails.work-item-assigned',
                'data' => [
                    'identifier' => 'PB-128',
                    'title' => 'Fix project member invitation flow',
                    'projectName' => 'Website Redesign',
                    'assignerName' => 'Rohit Philip',
                    'assigneeName' => 'Mike',
                    'url' => $url.'/projects/1/work-items?item=128',
                    'description' => 'The invite-by-email half of the members screen is being removed; only workspace coworkers can be added.',
                    'actor' => $actor,
                ],
            ],
            'work-item-updated' => [
                'label' => 'Work item updated',
                'view' => 'emails.work-item-updated',
                'data' => [
                    'identifier' => 'PB-128',
                    'title' => 'Fix project member invitation flow',
                    'projectName' => 'Website Redesign',
                    'updatedBy' => 'Rohit Philip',
                    'updatedAt' => 'Mon, Sep 7, 2026 2:14 PM',
                    'url' => $url.'/projects/1/work-items?item=128',
                    'changes' => [
                        ['label' => 'Priority', 'from' => 'Medium', 'to' => 'High'],
                        ['label' => 'Due date', 'from' => 'Sep 20, 2026', 'to' => 'Sep 15, 2026'],
                    ],
                    'actor' => $actor,
                ],
            ],
            'work-item-status-changed' => [
                'label' => 'Work item status changed',
                'view' => 'emails.work-item-status-changed',
                'data' => [
                    'identifier' => 'PB-128',
                    'title' => 'Fix project member invitation flow',
                    'projectName' => 'Website Redesign',
                    'actorName' => 'Rohit Philip',
                    'fromState' => 'Todo',
                    'toState' => 'In Progress',
                    'url' => $url.'/projects/1/work-items?item=128',
                    'actor' => $actor,
                ],
            ],
            'work-item-blocked' => [
                'label' => 'Work item blocked',
                'view' => 'emails.work-item-blocked',
                'data' => [
                    'identifier' => 'PB-128',
                    'title' => 'Fix project member invitation flow',
                    'projectName' => 'Website Redesign',
                    'actorName' => 'Rohit Philip',
                    'blockers' => [
                        ['identifier' => 'PB-96', 'title' => 'Members API returns stale roles'],
                    ],
                    'url' => $url.'/projects/1/work-items?item=128',
                    'actor' => $actor,
                ],
            ],
            'comment-posted' => [
                'label' => 'New comment',
                'view' => 'emails.comment-posted',
                'data' => [
                    'identifier' => 'PB-128',
                    'title' => 'Fix project member invitation flow',
                    'projectName' => 'Website Redesign',
                    'actorName' => 'Rohit Philip',
                    'excerpt' => 'Please update the project member invitation flow before the next release — the email half is going away.',
                    'url' => $url.'/projects/1/work-items?item=128&tab=comments',
                    'actor' => $actor,
                ],
            ],
            'mentioned' => [
                'label' => 'Mentioned in a comment',
                'view' => 'emails.mentioned',
                'data' => [
                    'identifier' => 'PB-128',
                    'title' => 'Fix project member invitation flow',
                    'projectName' => 'Website Redesign',
                    'actorName' => 'Rohit Philip',
                    'where' => 'a comment',
                    'excerpt' => '@Mike, can you review the updated invitation flow?',
                    'url' => $url.'/projects/1/work-items?item=128&tab=comments',
                    'description' => 'Remove invite-by-email from Project Settings → Members.',
                    'actor' => $actor,
                ],
            ],
        ];
    }

    public function index(): View
    {
        abort_unless(app()->environment('local'), 404);

        return view('dev.emails.index', ['samples' => static::samples()]);
    }

    public function show(string $slug): Response
    {
        abort_unless(app()->environment('local'), 404);

        $sample = static::samples()[$slug] ?? abort(404);

        return response(view($sample['view'], $sample['data'])->render());
    }
}
