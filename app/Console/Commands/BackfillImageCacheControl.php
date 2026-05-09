<?php

namespace App\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One-shot: walk every public-asset prefix in our Spaces bucket and
 * stamp Cache-Control: public, max-age=31536000, immutable onto each
 * object's response metadata.
 *
 * Why: Spaces uploads done before we added the CacheControl field to
 * putFileAs() shipped with no Cache-Control header at all, so
 * browsers cache them heuristically (or not). Setting a one-year
 * immutable header takes ~2 MB of repeat-download weight off /shop
 * for every Mexican shopper.
 *
 * How: S3 CopyObject with MetadataDirective=REPLACE rewrites the
 * object's metadata in place — no actual file content is re-uploaded
 * (it's a server-side copy on the same bucket). Cheap and fast.
 *
 * Run on prod:
 *   php artisan boxly:backfill-image-cache-control                 # dry run
 *   php artisan boxly:backfill-image-cache-control --apply         # do it
 *   php artisan boxly:backfill-image-cache-control --apply --prefix=products/
 */
class BackfillImageCacheControl extends Command
{
    protected $signature = 'boxly:backfill-image-cache-control
                            {--apply : Actually rewrite metadata (default is dry-run)}
                            {--prefix= : Limit to a single prefix (e.g. products/, shop-heroes/)}';

    protected $description = 'Stamp Cache-Control: 1-year immutable onto every public asset in Spaces.';

    /**
     * Public-asset prefixes whose objects should be edge-cacheable
     * for a year. Admin-only assets (orders/, prs/) intentionally
     * excluded — they're not browsed publicly.
     */
    private const PUBLIC_PREFIXES = [
        'products/',
        'categories/',
        'genders/',
        'stores/',
        'shop-heroes/',
    ];

    private const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $only  = $this->option('prefix');

        if (! $apply) {
            $this->warn('DRY RUN — pass --apply to actually update metadata.');
            $this->newLine();
        }

        $prefixes = $only ? [rtrim($only, '/') . '/'] : self::PUBLIC_PREFIXES;

        $disk = Storage::disk('spaces');
        $bucket = config('filesystems.disks.spaces.bucket');

        // Pull the underlying S3 client so we can call CopyObject with
        // MetadataDirective=REPLACE (Laravel's filesystem doesn't
        // expose an in-place metadata update).
        $client = new S3Client([
            'version'                 => 'latest',
            'region'                  => config('filesystems.disks.spaces.region'),
            'endpoint'                => config('filesystems.disks.spaces.endpoint'),
            'use_path_style_endpoint' => false,
            'credentials' => [
                'key'    => config('filesystems.disks.spaces.key'),
                'secret' => config('filesystems.disks.spaces.secret'),
            ],
        ]);

        $touched = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($prefixes as $prefix) {
            $files = $disk->allFiles($prefix);
            $this->info("→ {$prefix} ({" . count($files) . "} objects)");

            foreach ($files as $key) {
                try {
                    if ($apply) {
                        $client->copyObject([
                            'Bucket'            => $bucket,
                            'Key'               => $key,
                            'CopySource'        => $bucket . '/' . $key,
                            'MetadataDirective' => 'REPLACE',
                            'CacheControl'      => self::CACHE_CONTROL,
                            'ACL'               => 'public-read',
                            // Preserve the inferred content-type — without
                            // this CopyObject would default it back to
                            // octet-stream.
                            'ContentType'       => $this->guessContentType($key),
                        ]);
                        $this->line("  ✓ {$key}");
                    } else {
                        $this->line("  · would update {$key}");
                    }
                    $touched++;
                } catch (\Exception $e) {
                    $failed++;
                    $this->warn("  ✗ {$key} — {$e->getMessage()}");
                    Log::warning('Cache-Control backfill failed', [
                        'key' => $key, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->newLine();
        $this->info("Done. Touched: {$touched}, Skipped: {$skipped}, Failed: {$failed}.");
        if (! $apply) {
            $this->warn('Dry run — re-run with --apply to commit.');
        }

        return self::SUCCESS;
    }

    private function guessContentType(string $key): string
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        return match ($ext) {
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }
}
