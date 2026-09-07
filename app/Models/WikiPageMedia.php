<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * An image uploaded from the Wiki page editor (docs/features/wiki-lexical-editor.md).
 *
 * TENANT-SCOPED (CLAUDE.md §7): BelongsToTenant confines every query to the active workspace
 * and stamps `tenant_id` on insert. Collection scoping is applied on top, because one workspace
 * holds many collections and access is granted per collection, not per workspace.
 */
class WikiPageMedia extends Model
{
    use BelongsToTenant;

    protected $table = 'wiki_page_media';

    protected $fillable = [
        'tenant_id',
        'wiki_collection_id',
        'wiki_page_id',
        'uploaded_by',
        'disk',
        'path',
        'name',
        'mime',
        'size',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(WikiCollection::class, 'wiki_collection_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'wiki_page_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The authorized URL the editor embeds — never a direct path to the file on disk.
     *
     * Addressed by COLLECTION, not by page: this string is baked into the page's stored HTML at
     * upload time and has to keep resolving afterwards, including if the image is moved to
     * another page in the same collection.
     */
    public function url(): string
    {
        return route('wiki.pages.media.show', [
            'collection' => $this->wiki_collection_id,
            'media' => $this->id,
        ]);
    }
}
