{{--
  The short supporting line under the heading (§6), and the general-purpose paragraph.

  `muted` for the quieter trailing notes — expiry, "ignore this if it wasn't you" — so those
  do not compete with the sentence that explains why the email arrived.
--}}
@props(['muted' => false])

<p style="margin:0 0 20px;font-family:Inter,Arial,Helvetica,sans-serif;font-size:{{ $muted ? '13px' : '15px' }};line-height:1.6;color:{{ $muted ? '#9ca3af' : '#6b7280' }};">{{ $slot }}</p>
