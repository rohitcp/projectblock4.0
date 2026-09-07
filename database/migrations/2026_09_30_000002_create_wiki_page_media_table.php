<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Images uploaded from the Wiki page editor (docs/features/wiki-lexical-editor.md).
 *
 * Its own table rather than a column on the page: one page holds many images, an image outlives
 * the paragraph it was dropped into, and the row is what lets the file be served behind a
 * permission check instead of from a public URL.
 *
 * Deliberately a near-copy of `work_item_media`. The shape is the same problem — a private file
 * belonging to a tenant, streamed through an ability check — and two tables that agree are
 * easier to reason about than one clever shared one that has to know which module it is in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_page_media', function (Blueprint $table) {
            $table->id();
            // A string, matching `tenants.id` — the workspace key is a UUID here, not an
            // integer (CLAUDE.md §7 / D5). Foreign key below, as wiki_pages does it.
            $table->string('tenant_id');
            $table->foreignId('wiki_collection_id')->constrained('wiki_collections')->cascadeOnDelete();
            // The page is NULLABLE on purpose: permission to see the file is the COLLECTION's
            // to grant, and an image must keep resolving after the page it was written into is
            // deleted — a stale <img> should 404 on the file, not 500 on a missing row.
            $table->foreignId('wiki_page_id')->nullable()->constrained('wiki_pages')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            // RECORDED, not assumed: show() streams from whatever this says, so rows written
            // before a disk switch keep resolving to their old home.
            $table->string('disk', 40);
            $table->string('path');
            $table->string('name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // The editor asks for one collection's images at a time, newest first.
            $table->index(['wiki_collection_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_page_media');
    }
};
