{{--
  "Who triggered this" — a face beside a line of text (§16).

  Answers the second of the four questions in the final principle, and it is the one a reader
  uses to decide whether the email matters before reading anything else.

  `line` is the sentence beside the name ("invited you to join", "commented"); leave it out
  and the row is just the person.
--}}
@props([
    'name' => '',
    'url' => null,
    'initial' => null,
    'id' => null,
    'line' => '',
    'size' => 40,
])

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
  <tr>
    <td width="{{ (int) $size }}" valign="middle" style="width:{{ (int) $size }}px;padding-right:12px;">
      <x-email.avatar :name="$name" :url="$url" :initial="$initial" :id="$id" :size="$size" />
    </td>
    <td valign="middle" style="font-family:Inter,Arial,Helvetica,sans-serif;">
      <div style="font-size:15px;font-weight:600;color:#23272f;line-height:1.4;">{{ $name }}</div>
      @if ($line)
        <div style="font-size:13px;color:#6b7280;line-height:1.4;padding-top:2px;">{{ $line }}</div>
      @endif
    </td>
  </tr>
</table>
