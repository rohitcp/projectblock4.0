{{-- Work item moved between states — a narrower case of "updated", kept separate because the
     subject and the one fact it carries are different. --}}
<x-email.layout preheader="{{ $actorName }} moved {{ $identifier }} to {{ $toState ?? 'a new state' }}.">

  <x-email.icon name="assigned" />

  <x-email.heading>A work item changed status</x-email.heading>
  <x-email.text>{{ $actorName }} moved this in {{ $projectName }}.</x-email.text>

  @if (!empty($actor))
    <x-email.actor :name="$actor->name" :url="$actor->avatarUrl" :initial="$actor->initial" :id="$actor->id" line="changed the status" />
  @endif

  <x-email.card :eyebrow="$identifier" :title="$title">
    <x-email.row label="Project" :value="$projectName" />
    <x-email.row label="Status" :value="trim(($fromState ?? 'No state').' → '.($toState ?? 'No state'))" />
    <x-email.row label="Changed by" :value="$actorName" />
  </x-email.card>

  <x-email.button :url="$url">View Work Item</x-email.button>
  <x-email.fallback-link :url="$url" />
</x-email.layout>
