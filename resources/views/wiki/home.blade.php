<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>{{ $heading }} — Wiki — {{ $workspace->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/wiki.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex flex-1 min-h-0">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 overflow-y-auto">
      {{-- Full width, no centred measure: the toolbar's border has to run the width of the
           screen the way the Projects index's does, so the padding lives on the rows inside it
           rather than on a wrapper around them. --}}
      <div id="wiki-root" data-bootstrap="{{ json_encode($bootstrap) }}">
        <div class="px-5 sm:px-8 py-10 text-[13px] text-sub">Loading…</div>
      </div>

      {{-- A fact about the WORKSPACE, not about a list, so it belongs on the Wiki's front door
           and nowhere else.

           An info CARD rather than the loose line of grey text this used to be: as bare text
           at the foot of the page it read as a leftover caption, and the one thing it exists
           to offer — the way to turn the feature off — was the least visible part of it.

           Neutral, not amber. The tinted-warning panel this borrows its shape from (see
           `epics.js`) means "something is switched OFF and you should know"; this says the
           opposite, and dressing good news as a warning is how people learn to ignore both. --}}
      @if ($section === 'home')
        {{-- `pt-4`: the Vue root above ends flush with its own card, so without a top gap
             the two cards touch and read as one panel with a line through it. --}}
        <div class="px-5 sm:px-8 pt-4 pb-8">
          <div class="flex items-start gap-3 rounded-lg border border-line bg-sel px-4 py-3">
            <span class="text-sub shrink-0 mt-px">{!! pb_icon('circle-info', 16) !!}</span>
            <p class="text-[13px] leading-relaxed text-ink">
              @if (auth()->user()->currentWorkspace && auth()->user()->can('manageSettings', auth()->user()->currentWorkspace))
                Wiki is enabled for this workspace. You can turn it off at any time in
                <a href="{{ route('settings.general') }}" class="text-brand font-semibold hover:underline">Settings &rarr; General</a>.
              @else
                Wiki is enabled for this workspace. A workspace owner or admin can turn it off in Settings.
              @endif
            </p>
          </div>
        </div>
      @endif
    </main>
  </div>
</body>
</html>
