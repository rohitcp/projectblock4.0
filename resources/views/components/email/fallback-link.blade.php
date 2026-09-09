{{--
  The CTA's address in plain text, under the button.

  Kept because a button is not always clickable: some corporate clients strip the anchor, and
  a reader forwarding the mail to a phone often needs the address itself. `break-all` so a long
  signed URL does not widen the card past the frame.
--}}
@props(['url' => ''])

<p style="margin:0 0 4px;font-family:Inter,Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:#9ca3af;">
  If the button doesn’t work, paste this into your browser:
</p>
<p style="margin:0;font-family:Inter,Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:#9ca3af;word-break:break-all;">{{ $url }}</p>
