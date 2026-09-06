<?php

namespace App\Services;

use App\Enums\ImageStatus;
use App\Models\Recipe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The one place a recipe's picture is written.
 *
 * There are three ways one arrives — photographed, chosen from a device, or
 * pasted as a link — plus the scraper, and they differ only in where the bytes
 * come from. Keeping the writing, the validation and the replacing together
 * means a fix like checking whether the write actually succeeded lands once
 * rather than in four places.
 */
class RecipeImageStore
{
    private const MAX_BYTES = 10_000_000;

    /** @var array<string, string> */
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function storeUploaded(Recipe $recipe, UploadedFile $file): bool
    {
        $extension = self::TYPES[(string) $file->getMimeType()] ?? null;

        if (! $extension) {
            throw new RuntimeException('That file is not an image the app can read.');
        }

        return $this->put($recipe, (string) $file->get(), $extension, ImageStatus::Uploaded);
    }

    /**
     * Fetch a picture from a link.
     *
     * A link the household pasted counts as theirs, so it defaults to the
     * user-provided status and is protected from being overwritten by a later
     * scrape; the scraper passes its own status to say otherwise.
     */
    public function storeFromUrl(
        Recipe $recipe,
        string $url,
        ImageStatus $status = ImageStatus::Uploaded,
    ): bool {
        try {
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; WhatsForDinner/1.0)'])
                ->get($url);
        } catch (Throwable $e) {
            throw new RuntimeException('That address could not be reached.', 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException('That address did not return an image ('.$response->status().').');
        }

        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $extension = self::TYPES[$contentType] ?? null;

        if (! $extension) {
            // Often a link to the page the picture sits on rather than to the
            // picture itself, which is worth saying plainly.
            throw new RuntimeException('That link points at '.($contentType ?: 'something').', not an image.');
        }

        $body = $response->body();

        if ($body === '') {
            throw new RuntimeException('That image was empty.');
        }

        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('That image is too large.');
        }

        return $this->put($recipe, $body, $extension, $status, $url);
    }

    public function remove(Recipe $recipe): void
    {
        if ($recipe->image_path) {
            Storage::disk('public')->delete($recipe->image_path);
        }

        $recipe->update([
            'image_path' => null,
            'image_source_url' => null,
            'image_status' => ImageStatus::None,
        ]);
    }

    /**
     * Checked, not assumed: an unwritable directory once made this return false
     * while the recipe was updated anyway, leaving it pointing at a file that
     * had never been created.
     */
    private function put(
        Recipe $recipe,
        string $bytes,
        string $extension,
        ImageStatus $status,
        ?string $sourceUrl = null,
    ): bool {
        $suffix = $status === ImageStatus::Scraped ? '' : '-photo';
        $path = 'recipes/'.$recipe->id.$suffix.'.'.$extension;

        if (! Storage::disk('public')->put($path, $bytes)) {
            Log::warning('Could not write a recipe image', ['recipe' => $recipe->id, 'path' => $path]);

            return false;
        }

        $previous = $recipe->image_path;

        $recipe->update([
            'image_path' => $path,
            'image_source_url' => $sourceUrl,
            'image_status' => $status,
        ]);

        // Otherwise the replaced file lingers, one per change of mind.
        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return true;
    }
}
