{{-- New comment on a work item (§14, §21 → "View Comment").

     The template exists and is complete; nothing raises it yet. Deciding WHEN a comment should
     email somebody — every comment, only subscribers, digested — is a product question, and
     the notification that answers it passes exactly these variables. Until then this is
     reachable from the local preview index and nowhere else. --}}
<x-email.layout preheader="{{ $actorName }} commented on {{ $identifier }} {{ $title }}.">

  <x-email.icon name="comment" />

  <x-email.heading>New comment on a work item</x-email.heading>
  <x-email.text>{{ $actorName }} commented in {{ $projectName }}.</x-email.text>

  @if (!empty($actor))
    <x-email.actor :name="$actor->name" :url="$actor->avatarUrl" :initial="$actor->initial" :id="$actor->id" line="commented" />
  @endif

  <x-email.card :eyebrow="$identifier" :title="$title">
    <x-email.row label="Project" :value="$projectName" />
    <x-email.row label="Comment by" :value="$actorName" />
  </x-email.card>

  <x-email.quote>{{ $excerpt }}</x-email.quote>

  <x-email.button :url="$url">View Comment</x-email.button>
  <x-email.fallback-link :url="$url" />
</x-email.layout>
