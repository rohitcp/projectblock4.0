{{-- Workspace invitation (§11, §21 → "Join Workspace").

     `contextTitle` / `contextLine` / `contextLabel` / `contextValue` are how an invitation
     raised somewhere other than Settings → Members describes itself — a Help Center Space
     passes its own wording in. Left null, the email keeps the plain workspace wording. --}}
<x-email.layout preheader="{{ $inviterName }} invited you to join {{ $workspaceName }} on Project Block.">

  <x-email.icon name="invite" />

  <x-email.heading>{{ $contextTitle ?? "You’ve been invited to a workspace" }}</x-email.heading>

  @if ($contextLine)
    <x-email.text>{{ $contextLine }}</x-email.text>
  @else
    <x-email.text>{{ $inviterName }} invited you to join <strong style="color:#23272f;">{{ $workspaceName }}</strong> on Project Block.</x-email.text>
  @endif

  {{-- Who sent it, with their face (§16). Falls back to the plain name for an invitation
       raised before the mailable carried an actor. --}}
  @if (!empty($actor))
    <x-email.actor :name="$actor->name" :url="$actor->avatarUrl" :initial="$actor->initial" :id="$actor->id" line="invited you" />
  @endif

  <x-email.card :title="$workspaceName">
    @if ($contextLabel && $contextValue)
      <x-email.row :label="$contextLabel" :value="$contextValue" />
      <x-email.row label="Workspace" :value="$workspaceName" />
    @endif
    <x-email.row label="Role" :value="$roleLabel" />
    <x-email.row label="Invited" :value="$invitedEmail" />
  </x-email.card>

  <x-email.button :url="$acceptUrl">Join Workspace</x-email.button>

  @if ($expiresOn)
    <x-email.text muted>This invitation expires on {{ $expiresOn }}.</x-email.text>
  @endif

  <x-email.fallback-link :url="$acceptUrl" />
</x-email.layout>
