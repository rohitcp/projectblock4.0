{{--
  The ProjectBlock transactional email shell (docs/features/transactional-emails.md).

  ONE shell for every system email. A notification supplies its heading, its context and its
  single call to action; everything around that — the frame, the wordmark, the footer, the
  responsive rules — lives here and is never restated. Before this, fourteen templates each
  carried their own copy of the same markup, which is why they had drifted to three container
  widths and no footer at all.

  ## Written the way email has to be written

  Tables and inline styles, because a transactional email is read in clients that never
  implemented the last twenty years of CSS. Outlook (Word engine) ignores `border-radius` and
  `max-width`, so the frame degrades to square corners at a fixed width rather than breaking.
  The `<style>` block carries ONLY the small-screen overrides: clients that drop it (Gmail's
  clipped view, older Outlook) still get the desktop layout, which is the correct fallback.

  ## Props

  - `preheader`  the line shown beside the subject in an inbox list. Set it on every email:
                 left empty, clients quote the first words of the body instead, which for a
                 code email is the word "Your".
  - `footerNote` an extra line under the standard footer — used by security emails for
                 "If you did not perform this action…".
--}}
@props([
    'preheader' => '',
    'footerNote' => '',
])

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>{{ config('app.name') }}</title>
  <style>
    /* Small screens only. Everything the layout needs on a desktop client is inline. */
    @media only screen and (max-width: 620px) {
      .pb-shell   { width: 100% !important; }
      .pb-card    { width: 100% !important; }
      .pb-pad     { padding-left: 20px !important; padding-right: 20px !important; }
      /* The CTA becomes full width: a 46px-tall button spanning the column is the easiest
         thing to hit on a phone, and it is the only action in the email.
         `box-sizing` is load-bearing — the button carries 32px of horizontal padding, so
         `width:100%` alone made it 64px WIDER than the card it sits in and pushed a
         horizontal scrollbar into the email. */
      .pb-btn     { display: block !important; width: 100% !important; box-sizing: border-box !important; text-align: center !important; }
      .pb-btn-td  { width: 100% !important; }
      /* Label above value rather than beside it, so neither has to wrap mid-word. */
      .pb-row-l, .pb-row-v { display: block !important; width: 100% !important; text-align: left !important; padding-right: 24px !important; }
      .pb-row-v   { padding-top: 2px !important; padding-bottom: 10px !important; }
      .pb-code    { font-size: 30px !important; letter-spacing: 8px !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Inter,Arial,Helvetica,sans-serif;color:#23272f;-webkit-font-smoothing:antialiased;">

  {{-- Preheader: pulled into the inbox preview, invisible in the body itself. The trailing
       run of zero-width characters stops the client filling the rest of the preview with the
       first line of the email, which repeats what is already on screen.

       LITERAL characters, not the `&#847;&zwnj;&nbsp;` entities this used to emit. Anything
       that reads the mail as text rather than as HTML — a plain-text part, an inbox preview
       that strips tags without decoding entities — printed those entities verbatim, so the
       preview read "…in styledesk. &#847;&zwnj;&nbsp;&#847;&zwnj;…". As real code points they
       are invisible whether they are decoded or not. --}}
  <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
    {{ $preheader }}
    {{ str_repeat("\u{034F}\u{200C}\u{00A0}", 60) }}
  </div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;">
    <tr>
      <td align="center" style="padding:32px 12px;">

        <table role="presentation" class="pb-shell" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">

          {{-- Header: the logo and nothing else (§4).

               The MARK is an image and the NAME is live text, beside each other. That split is
               deliberate: most clients block remote images by default, so a logo that is
               entirely an image is an empty box for a large share of readers — and one that is
               entirely text has no logo in it. This way a blocked image costs the mark and
               keeps the name.

               PNG rather than the app's SVG: Gmail and Outlook both drop inline <svg>. Drawn at
               96px and shown at 24px so it stays sharp on a retina client. --}}
          <tr>
            <td class="pb-pad" style="padding:0 8px 20px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td width="24" valign="middle" style="width:24px;padding-right:9px;">
                    <img src="{{ rtrim(config('app.url'), '/') }}/assets/img/email-logo.png"
                         alt="" width="24" height="24"
                         style="display:block;width:24px;height:24px;border:0;" />
                  </td>
                  <td valign="middle" style="font-family:Inter,Arial,Helvetica,sans-serif;font-size:17px;font-weight:700;color:#0f0f10;letter-spacing:-.01em;">Project Block</td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td>
              {{-- No border and no radius: the frame existed to separate a white card from a
                   grey page, and on a white page it is a box drawn around nothing. The
                   content area keeps its generous padding, which is what actually gives the
                   email its shape now. --}}
              <table role="presentation" class="pb-card" width="100%" cellpadding="0" cellspacing="0" border="0"
                     style="background:#ffffff;">
                <tr>
                  <td class="pb-pad" style="padding:8px 8px 32px;">
                    {{ $slot }}
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Footer (§17): identical in every ProjectBlock system email.

               The hairline above it does the job the card's border used to. On a white page
               the footer had nothing separating it from the message, so the legal small print
               read as one more line of the notification. --}}
          <tr>
            <td class="pb-pad" style="padding:0 8px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><td style="height:1px;line-height:1px;font-size:0;background:#e5e7eb;">&nbsp;</td></tr>
              </table>
            </td>
          </tr>
          <tr>
            <td class="pb-pad" style="padding:20px 8px 0;">
              <p style="margin:0 0 8px;font-size:12px;line-height:1.6;color:#9ca3af;">
                You’re receiving this email because this activity occurred in your Project Block account.
              </p>
              @if ($footerNote)
                <p style="margin:0 0 8px;font-size:12px;line-height:1.6;color:#9ca3af;">{{ $footerNote }}</p>
              @endif
              <p style="margin:0 0 10px;font-size:12px;line-height:1.6;color:#9ca3af;">
                <a href="{{ rtrim(config('app.url'), '/') }}/help" style="color:#6b7280;text-decoration:underline;">Help &amp; Support</a>
                <span style="color:#d1d5db;"> &middot; </span>
                <a href="{{ rtrim(config('app.url'), '/') }}/privacy" style="color:#6b7280;text-decoration:underline;">Privacy</a>
                <span style="color:#d1d5db;"> &middot; </span>
                <a href="{{ rtrim(config('app.url'), '/') }}/security" style="color:#6b7280;text-decoration:underline;">Security</a>
              </p>
              <p style="margin:0;font-size:12px;color:#9ca3af;">&copy; {{ date('Y') }} Project Block</p>
            </td>
          </tr>

        </table>

      </td>
    </tr>
  </table>
</body>
</html>
