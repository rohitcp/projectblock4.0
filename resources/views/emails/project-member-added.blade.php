{{-- "You've been added to {Project}" (docs/features/project-member-invitations.md).

     The same table-in-a-table skeleton and inline styles every other mail in this app uses —
     email clients strip <style> blocks, so the styling is on the elements or it does not
     survive. Read this beside emails/wiki-guest-invitation.blade.php; if one changes, both do. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;background:#f3f4f6;font-family:Inter,Arial,sans-serif;color:#23272f;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
    <tr><td align="center">
      <table role="presentation" width="440" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden;">
        <tr><td style="padding:28px 32px 8px;">
          <div style="font-size:18px;font-weight:700;color:#0f0f10;">Project Block</div>
        </td></tr>

        <tr><td style="padding:8px 32px 0;">
          <h1 style="font-size:20px;font-weight:700;color:#0f0f10;margin:12px 0 6px;">You've been added to a project</h1>
          <p style="font-size:14px;color:#6b7280;margin:0 0 20px;">
            Hello {{ $recipientName }} — <strong style="color:#23272f;">{{ $inviterName }}</strong> has added you to
            <strong style="color:#23272f;">{{ $projectName }}</strong> in the
            <strong style="color:#23272f;">{{ $workspaceName }}</strong> workspace.
          </p>
        </td></tr>

        {{-- The three facts the requirement asks the email to carry, stated rather than
             buried in the sentence above, because this is the part people scan for. --}}
        <tr><td style="padding:0 32px 4px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                 style="border:1px solid #e5e7eb;border-radius:8px;">
            <tr>
              <td style="padding:10px 14px;font-size:12px;color:#9ca3af;width:96px;">Project</td>
              <td style="padding:10px 14px;font-size:13px;color:#23272f;font-weight:600;">{{ $projectName }}</td>
            </tr>
            <tr>
              <td style="padding:10px 14px;font-size:12px;color:#9ca3af;border-top:1px solid #f1f3f5;">Workspace</td>
              <td style="padding:10px 14px;font-size:13px;color:#23272f;border-top:1px solid #f1f3f5;">{{ $workspaceName }}</td>
            </tr>
            <tr>
              <td style="padding:10px 14px;font-size:12px;color:#9ca3af;border-top:1px solid #f1f3f5;">Your role</td>
              <td style="padding:10px 14px;font-size:13px;color:#23272f;border-top:1px solid #f1f3f5;">{{ $roleLabel }}</td>
            </tr>
          </table>
        </td></tr>

        {{-- No acceptance step: they are already in the workspace, so this is the way IN and
             not a link that has to be redeemed. --}}
        <tr><td style="padding:20px 32px 8px;">
          <a href="{{ $projectUrl }}"
             style="display:block;text-align:center;background:#1b5f8a;color:#ffffff;text-decoration:none;
                    font-size:14px;font-weight:600;padding:12px 16px;border-radius:8px;">Open the project</a>
        </td></tr>

        <tr><td style="padding:8px 32px 28px;">
          <p style="font-size:12px;color:#9ca3af;margin:0;line-height:1.6;">
            If the button does not work, paste this into your browser:<br />
            <span style="color:#6b7280;word-break:break-all;">{{ $projectUrl }}</span>
          </p>
        </td></tr>
      </table>

      <p style="font-size:11px;color:#9ca3af;margin:16px 0 0;">Project Block</p>
    </td></tr>
  </table>
</body>
</html>
