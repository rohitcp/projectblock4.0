<?php

/*
|--------------------------------------------------------------------------
| Wiki & Knowledge Management (docs/features/wiki.md)
|--------------------------------------------------------------------------
| The navigation the Wiki presents once a workspace enables it. Declared here rather
| than in the view so the sidebar, the home screen and the routes that fill them in
| later all read one list.
*/

return [

    'sections' => [
        ['key' => 'home', 'label' => 'Home', 'icon' => 'house',
            'blurb' => 'Start from one central place and reach the knowledge that matters to you.'],
        ['key' => 'collections', 'label' => 'Collections', 'icon' => 'folder',
            'blurb' => 'Browse every collection you have permission to open.'],
        ['key' => 'shared', 'label' => 'Shared', 'icon' => 'users',
            'blurb' => 'Knowledge other people have shared with you.'],
        ['key' => 'private', 'label' => 'Private', 'icon' => 'lock',
            'blurb' => 'The private collections and pages available to you.'],
        ['key' => 'archived', 'label' => 'Archived', 'icon' => 'box-archive',
            'blurb' => 'Retired knowledge, out of the way but still readable.'],
    ],

    /*
     * What a Cover Page section card can wear (docs/features/wiki-cover-page.md, FR-WC-007).
     *
     * A shortlist, not the whole of App\Support\IconRegistry: a landing page wants a handful of
     * recognisable shapes, and a grid of a hundred is a decision nobody wants to make. Anything
     * outside the registry is refused whatever this list says, so growing it is a one-line change
     * rather than a migration.
     */
    /*
     * Images uploaded from the Wiki page editor (docs/features/wiki-lexical-editor.md).
     *
     * Stored on the private disk and streamed through an authorized route, so these limits are
     * the only thing standing between the editor and the filesystem. Stated here rather than
     * read from config('projects.media') so the Wiki's limits can move without moving a
     * project's — the two modules answer to different people.
     */
    'media' => [
        'max_kb' => 5120,
        // GIF is in the list on purpose: the editor's Insert GIF is an image, not a new node.
        'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'],
    ],

    'cover_icons' => [
        'folder', 'file-lines', 'house', 'users', 'gear', 'lock', 'globe',
        'lightbulb', 'star', 'gem', 'key', 'clock', 'calendar', 'tag',
        'inbox', 'grid', 'list-ul', 'table', 'link', 'play', 'circle-info',
    ],
];
