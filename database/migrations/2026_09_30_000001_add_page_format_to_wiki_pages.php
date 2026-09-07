<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The page format a wiki page is written on (docs/features/wiki-page-format.md).
 *
 * The editor used to be A4 and nothing else — the sheet was hardcoded in the editor's own
 * stylesheet, so there was no format to store. Now that a page can be A4, Letter, Legal or
 * paperless, the choice belongs to the page: it is how the document is laid out, not a
 * preference of whoever happens to be reading it, so it is stored beside the content and
 * comes back the same way on every screen that opens it.
 *
 * A string rather than an enum: the set of paper sizes is the kind of list that grows, and a
 * new size should be a line in the model's PAGE_FORMATS, not a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wiki_pages', function (Blueprint $table) {
            // 'a4' is the default the requirement asks for, and it is also what every page
            // written before this column existed was already displayed as — so backfilling
            // is the default, and nothing that exists changes appearance.
            $table->string('page_format', 20)->default('a4')->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('wiki_pages', function (Blueprint $table) {
            $table->dropColumn('page_format');
        });
    }
};
