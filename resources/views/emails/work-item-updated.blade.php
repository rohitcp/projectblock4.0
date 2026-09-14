{{-- Work item updated (§1 → "Work Item Updated where notification is required").

     The changes are the point of this email, so they are the card: a "something changed"
     notification with no before/after is one that always has to be opened. --}}
<x-email.layout preheader="{{ $updatedBy }} updated {{ $identifier }} {{ $title }}.">

  <x-email.icon name="assigned" />

  <x-email.heading>A work item you follow was updated</x-email.heading>
  <x-email.text>{{ $updatedBy }} made changes in {{ $projectName }}.</x-email.text>

  @if (!empty($actor))
    <x-email.actor :name="$actor->name" :url="$actor->avatarUrl" :initial="$actor->initial" :id="$actor->id" :line="$updatedAt" />
  @endif

  <x-email.card :eyebrow="$identifier" :title="$title">
    <x-email.row label="Project" :value="$projectName" />
    @foreach ($changes as $change)
      {{-- "Medium → High" on one line: the pair is the information, and splitting it across
           two rows makes the reader assemble it themselves. --}}
      <x-email.row :label="$change['label'] ?? 'Changed'"
                   :value="trim(($change['from'] ?? '—').' → '.($change['to'] ?? '—'))" />
    @endforeach
  </x-email.card>

  <x-email.button :url="$url">View Work Item</x-email.button>
  <x-email.fallback-link :url="$url" />
</x-email.layout>
