<?php

namespace App\Http\Controllers\Wiki;

use App\Http\Controllers\Controller;
use App\Models\WikiCollection;
use App\Models\WikiPageMedia;
use App\Services\WorkspaceApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Images for the Wiki page editor (docs/features/wiki-lexical-editor.md).
 *
 * The request and response shapes are the editor's: files arrive as `file-N` and the answer is
 * `{"result": [{url, name, size}]}`, or `{"errorMessage": "…"}` on failure. That is the same
 * contract `WorkItemMediaController` answers, deliberately — one shape for every uploader in
 * the app means the client-side upload code is written once.
 *
 * Files go on the PRIVATE disk and are streamed through `show()` behind the collection's own
 * read check. The public disk would hand out a URL that works for anyone who receives it,
 * regardless of workspace or collection membership (CLAUDE.md §7).
 */
class PageMediaController extends Controller
{
    public function __construct(private readonly WorkspaceApps $apps) {}

    /** POST /wiki/collections/{collection}/pages/media — the editor's upload target. */
    public function store(Request $request, WikiCollection $collection): JsonResponse
    {
        // Uploading is a WRITE. Whoever may edit the collection's pages may put images in
        // them; nobody else can turn this endpoint into workspace file storage.
        if (! $this->apps->isEnabled(Auth::user()->currentWorkspace, 'wiki')
            || ! $collection->writableBy(Auth::user())) {
            return $this->failure('You do not have permission to upload images here.', 403);
        }

        $files = collect($request->allFiles())
            ->filter(fn ($file, $key) => str_starts_with((string) $key, 'file-'))
            ->flatten();

        if ($files->isEmpty()) {
            return $this->failure('No image was received.');
        }

        $rules = [
            'file' => [
                'required', 'file', 'image',
                'mimes:'.implode(',', config('wiki.media.mimes')),
                'max:'.(int) config('wiki.media.max_kb'),
            ],
        ];

        $pageId = $request->integer('page_id') ?: null;
        $disk = (string) config('filesystems.media_disk', 'local');
        $result = [];

        foreach ($files as $file) {
            $validator = Validator::make(['file' => $file], $rules);
            if ($validator->fails()) {
                // One bad file fails the batch: a half-inserted set of images would leave the
                // author guessing which one did not make it.
                return $this->failure($validator->errors()->first());
            }

            // 'private' is explicit: some disks default writes to public-read, and inheriting
            // that would publish a workspace's pages to an unauthenticated URL.
            try {
                $path = $file->store(
                    "wiki-page-media/{$collection->tenant_id}/{$collection->id}",
                    ['disk' => $disk, 'visibility' => 'private'],
                );
            } catch (\Throwable $e) {
                Log::error('Wiki page media upload failed', [
                    'disk' => $disk,
                    'collection_id' => $collection->id,
                    'file' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ]);

                return $this->failure("Upload failed: could not write to the '{$disk}' disk.");
            }

            if ($path === false) {
                Log::error('Wiki page media upload returned no path', [
                    'disk' => $disk, 'collection_id' => $collection->id,
                ]);

                return $this->failure("Upload failed: the '{$disk}' disk rejected the file without an error.");
            }

            /** @var WikiPageMedia $media */
            $media = WikiPageMedia::create([
                'wiki_collection_id' => $collection->id,
                'wiki_page_id' => $pageId,
                'uploaded_by' => Auth::id(),
                'disk' => $disk,
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize() ?: 0,
            ]);

            $result[] = [
                'url' => $media->url(),
                'name' => $media->name,
                'size' => $media->size,
            ];
        }

        return response()->json(['result' => $result]);
    }

    /**
     * GET /wiki/collections/{collection}/pages/media/{media} — stream one image.
     *
     * READ, not write: anyone who may open the collection may see the images inside its pages,
     * including a reader who cannot edit. 404 rather than 403 on a foreign collection's media,
     * so the response cannot confirm that a file exists somewhere the caller cannot reach.
     */
    public function show(WikiCollection $collection, WikiPageMedia $media): StreamedResponse
    {
        abort_unless($this->apps->isEnabled(Auth::user()->currentWorkspace, 'wiki'), 404);
        abort_unless((int) $media->wiki_collection_id === (int) $collection->id, 404);
        abort_unless($collection->openableBy(Auth::user()), 404);

        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->path), 404);

        return $disk->response($media->path, $media->name, [
            'Content-Type' => $media->mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** The editor shows `errorMessage` to the author; anything else looks like silence. */
    private function failure(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['errorMessage' => $message], $status);
    }
}
