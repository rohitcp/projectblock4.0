# Transactional Emails

## Requirement

One reusable Project Block email shell used by every system-generated message. Only the
content, the contextual icon, the CTA and the supporting information change between
notifications. Simple, modern, minimal, one clear purpose per email, one strong CTA, and
consistent branding.

## User Roles

Every user who receives a system email: workspace members, project members, invitees who do
not yet have an account, and Back Office administrators.

## Architecture

Blade components under `resources/views/components/email/`. Each notification supplies its own
content into the shared shell; nothing restates the frame.

```
x-email.layout          the shell: frame, wordmark, footer, responsive rules, preheader
 ├─ x-email.icon        small contextual mark above the heading
 ├─ x-email.heading     one action-focused headline
 ├─ x-email.text        supporting line; `muted` for trailing notes
 ├─ x-email.actor       who triggered it — avatar + name + line
 │   └─ x-email.avatar  uploaded photo, else initials tile
 ├─ x-email.card        context card
 │   └─ x-email.row     label / value line (stacks on mobile)
 ├─ x-email.code        verification / one-time code
 ├─ x-email.quote       comment preview (truncates and strips tags)
 ├─ x-email.button      the single primary CTA (bulletproof, VML for Outlook)
 └─ x-email.fallback-link  the CTA address in plain text
```

`App\Mail\EmailActor` carries the person an email is about (name, avatar URL, initial, id) as
plain scalars, so a queued mailable rebuilt by a worker with no tenancy context still renders
the right face.

`pb_avatar_color()` in `app/helpers.php` is a PHP twin of `avatarColor()` in
`assets/js/settings/app.js`. It must stay a twin: the same person appears as an avatar in the
app and again in the email the app sends, and two independent colour choices give them two
identities. Verified to agree on numeric ids and on string keys.

## Template matrix

| Notification | View | CTA |
|---|---|---|
| Verification / one-time code | `emails.login-code` | none — the code is the action |
| Back Office code | `emails.backoffice-code` | none |
| Reset password | `emails.password-reset` | Reset Password |
| Workspace invitation | `emails.workspace-invitation` | Join Workspace |
| Added to a project | `emails.project-member-added` | Open Project |
| Work item assigned | `emails.work-item-assigned` | View Work Item |
| Work item updated | `emails.work-item-updated` | View Work Item |
| Work item status changed | `emails.work-item-status-changed` | View Work Item |
| Work item blocked | `emails.work-item-blocked` | View Work Item |
| New comment | `emails.comment-posted` | View Comment |
| Mentioned in a comment | `emails.mentioned` | View Mention |

## Business Rules

- **One CTA per email.** Secondary actions are text links or nothing.
- **Profile images (§16).** Show the uploaded photo when there is one; initials only when there
  is not. Never initials over a photo that exists.
- **Codes are not pre-formatted.** A code is emitted exactly as issued and only letter-spaced
  visually, because a code shown as `482 194` is copied with the space in it.
- **Comment previews are truncated in the component**, not at the call site, and tags are
  stripped before the cut — so no caller can forget, and stored HTML can never style the email
  around it.
- **Deep links point at the subject of the notification.** The project email opens that
  project; the work item email opens that work item.

## Acceptance Criteria

- Every email above renders from the shared shell with the standard footer.
- Container is 600px on desktop and full width below 620px, with side padding kept.
- CTA is full width on mobile and does not overflow its card.
- Verification codes do not wrap.
- Context card rows stack label-above-value on mobile.
- A user with a photo shows the photo; a user without shows a coloured initials tile whose
  colour matches the app.

## Previewing

`/dev/emails` (local only) lists every template rendered against sample data;
`/dev/emails/{slug}` renders one. This exists so a change to the shell can be checked against
every email at once rather than by triggering nine workflows.

## Not in this slice

- **Help Centre and wiki-guest emails** (`agent-reply`, `ticket-confirmation`,
  `ticket-template`, `inbound-test`, `wiki-guest-invitation`) still use their own markup. They
  go to people outside the workspace — a ticket confirmation is read by a customer who has no
  Project Block account — so whether they should carry the same product chrome is a product
  decision, not a formatting one.
- **New comment emails have no trigger.** `emails.comment-posted` is complete and reachable from
  the preview index, but nothing raises it. Deciding when a comment should email somebody —
  every comment, subscribers only, or a digest — is a separate product question.
- **User-facing forgot-password.** Only the Back Office has a reset flow today; the customer app
  signs in with a code. `emails.password-reset` is written against whichever flow adopts it.
