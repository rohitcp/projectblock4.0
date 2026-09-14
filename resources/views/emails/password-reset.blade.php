{{-- Reset password (§10, §21 → "Reset Password"). A security email, so it carries the extra
     footer note and says plainly what to do if the request was not theirs. --}}
<x-email.layout preheader="Reset your Project Block password."
                footerNote="If you did not perform this action, please contact your administrator.">

  <x-email.icon name="password" />

  <x-email.heading>Reset your password</x-email.heading>
  <x-email.text>We received a request to reset your Project Block password.</x-email.text>

  <x-email.button :url="$resetUrl">Reset Password</x-email.button>

  <x-email.text muted>This link expires in {{ $ttlMinutes }} minutes and can be used once.</x-email.text>
  <x-email.text muted>If you didn’t request a password reset, you can safely ignore this email — your password will not change.</x-email.text>

  <x-email.fallback-link :url="$resetUrl" />
</x-email.layout>
