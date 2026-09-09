{{-- Somebody replied to your comment (§ "Reply" → "View Reply").

     The reply itself is quoted, not summarised: an email that only says somebody answered you
     makes you open the app to find out whether it needed you, which is the thing a
     notification is supposed to save. --}}
<x-email.layout preheader="{{ $actorName }} replied to your comment on {{ $identifier }} {{ $title }}.">

  <x-email.icon name="comment" />

  <x-email.heading>{{ $actorName }} replied to your comment</x-email.heading>
  <x-email.text>On {{ $identifier }} in {{ $projectName }}.</x-email.text>

  @if (!empty($actor))
    <x-email.actor :name="$actor->name" :url="$actor->avatarUrl" :initial="$actor->initial" :id="$actor->id" line="replied to you" />
  @endif

  <x-email.card :eyebrow="$identifier" :title="$title">
    <x-email.row label="Project" :value="$projectName" />
    <x-email.row label="Replied by" :value="$actorName" />
  </x-email.card>

  <x-email.quote>{{ $excerpt }}</x-email.quote>

  {{-- The URL carries the reply's own anchor, so this opens the thread at the reply rather
       than at the top of a long comment list. --}}
  <x-email.button :url="$url">View Reply</x-email.button>
  <x-email.fallback-link :url="$url" />
</x-email.layout>
