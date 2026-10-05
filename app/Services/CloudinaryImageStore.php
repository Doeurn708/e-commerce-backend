<?php

namespace App\Services;

use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stores images on Cloudinary and hands back the two values a record needs:
 * the delivery URL to display and the public_id to destroy later.
 *
 * Credentials come from CLOUDINARY_URL (cloudinary://<key>:<secret>@<cloud_name>)
 * so the secret never appears in code; see config/cloudinary.php.
 *
 * MOUNT IT by injecting the class into a controller:
 *
 *     public function __construct(private CloudinaryImageStore $images) {}
 *
 *     $image = $this->images->resolve($request, 'image', folder: 'products');
 *     // ['url' => 'https://res.cloudinary.com/.../products/abc.jpg',
 *     //  'public_id' => 'products/abc']
 *
 * The folder is a per-call argument rather than a constructor one, so the same
 * instance serves products, categories and avatars.
 *
 * NOTE ON UPDATES: PHP only parses a multipart/form-data body for POST, so a
 * raw PUT/PATCH carrying a file silently arrives with an empty $request and the
 * old image is kept. Either send the update as `POST /api/products/{id}`, or
 * keep the PUT/PATCH verb and add `_method=PUT` to the form so Laravel's method
 * spoofing rewrites it.
 */
class CloudinaryImageStore
{
    /**
     * Formats accepted for upload. `image` is not used because it would also
     * accept bmp/gif/svg.
     */
    private const ALLOWED = 'mimes:jpg,jpeg,png,webp';

    /**
     * 2048 kilobytes, so 2MB.
     */
    private const MAX_KILOBYTES = 2048;

    /**
     * Resolve the image input into the URL and public_id to persist.
     *
     * - Uploaded file -> validated, uploaded to $folder, and the previous asset
     *   is destroyed so it is not orphaned in the cloud.
     * - String value  -> kept untouched, so records predating the Cloudinary
     *   move (relative local paths, external URLs) still work; the public_id is
     *   recovered from the URL when it is one of ours.
     * - Missing/empty -> the record keeps whatever it already has.
     *
     * @return array{url: ?string, public_id: ?string}
     */
    public function resolve(
        Request $request,
        string $field = 'image',
        string $folder = 'products',
        ?string $currentUrl = null,
        ?string $currentPublicId = null,
    ): array {
        $file = $request->file($field);

        if ($file instanceof UploadedFile) {
            $request->validate([
                $field => ['required', 'file', self::ALLOWED, 'max:'.self::MAX_KILOBYTES],
            ]);

            // Upload before deleting: if Cloudinary rejects the file the old
            // asset is still in place, so the record never loses its image.
            $upload = $this->upload($file, $folder);

            // Prefer the stored public_id: it is exact, whereas parsing the URL
            // has to guess where the transformation chain ends.
            $this->delete($currentUrl, $currentPublicId);

            return $upload;
        }

        $value = $request->input($field);

        if (is_string($value) && trim($value) !== '') {
            $url = trim($value);

            return [
                'url' => $url,
                'public_id' => $this->publicIdFromUrl($url) ?? $currentPublicId,
            ];
        }

        return [
            'url' => $currentUrl,
            'public_id' => $currentPublicId,
        ];
    }

    /**
     * URL-only shorthand for the single-column callers (categories, avatars).
     */
    public function resolveUrl(
        Request $request,
        string $field = 'image',
        string $folder = 'products',
        ?string $currentUrl = null,
    ): ?string {
        return $this->resolve($request, $field, $folder, $currentUrl)['url'];
    }

    /**
     * Push a validated upload to Cloudinary and return its URL and public_id.
     *
     * A Cloudinary failure (bad credentials, quota, network) is re-thrown as a
     * ValidationException on the image field. Left alone it escapes as a 500 and
     * the caller loses every other field it was saving along with the upload.
     *
     * @return array{url: string, public_id: string}
     */
    public function upload(UploadedFile $file, string $folder = 'products'): array
    {
        // The SDK wants a readable local path, not an UploadedFile.
        try {
            $result = Cloudinary::uploadApi()->upload($file->getRealPath(), [
                'folder' => $folder,
                'resource_type' => 'image',
            ]);
        } catch (Throwable $e) {
            // Credentials, quota or transport problem. The original detail goes
            // to the log; the caller only needs to know the field was rejected.
            Log::warning('Cloudinary upload failed.', [
                'folder' => $folder,
                'message' => $e->getMessage(),
            ]);

            // Reported on the image field so the API answers 422 like any other
            // validation failure instead of a 500 that discards the rest of the
            // payload.
            throw ValidationException::withMessages([
                'image' => 'Image upload failed. Check the Cloudinary credentials and try again.',
            ]);
        }

        $url = data_get($result, 'secure_url');
        $publicId = data_get($result, 'public_id');

        if (! is_string($url) || $url === '') {
            throw ValidationException::withMessages([
                'image' => 'Image upload failed. Check the Cloudinary credentials and try again.',
            ]);
        }

        // Without a public_id the asset can never be replaced or removed later,
        // so a partial response is treated as a failure rather than stored.
        if (! is_string($publicId) || $publicId === '') {
            throw ValidationException::withMessages([
                'image' => 'Image upload failed. Check the Cloudinary credentials and try again.',
            ]);
        }

        return [
            'url' => $url,
            'public_id' => $publicId,
        ];
    }

    /**
     * Delete the asset behind a stored Cloudinary URL.
     *
     * Never throws: a failed cleanup must not block the database operation that
     * triggered it (updating or deleting a product). Returns true when
     * Cloudinary reported the asset gone (or already absent).
     */
    public function delete(?string $url = null, ?string $publicId = null): bool
    {
        $pid = $publicId ?? $this->publicIdFromUrl($url);

        if ($pid === null) {
            return false;
        }

        try {
            $result = Cloudinary::uploadApi()->destroy($pid, ['invalidate' => true]);
        } catch (Throwable $e) {
            Log::warning('Cloudinary destroy failed.', [
                'public_id' => $pid,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        // "ok" means removed, "not found" means the CDN/asset was already gone.
        return in_array(data_get($result, 'result'), ['ok', 'not found'], true);
    }

    /**
     * Recover the public_id from a Cloudinary delivery URL.
     *
     * Delivery URLs look like:
     *   https://res.cloudinary.com/<cloud>/image/upload[/<transformations>][/v<version>]/<public_id>.<ext>
     * so the public_id is everything after the `upload` segment, minus the
     * transformation chain, the version and the file extension.
     *
     * Returns null when the URL is not a delivery URL for the cloud this app is
     * configured against, which keeps foreign or legacy local paths from being
     * sent to Cloudinary.
     */
    public function publicIdFromUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'], $parts['path'])) {
            return null;
        }

        if (! str_contains(strtolower($parts['host']), 'cloudinary.com')) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $parts['path']), static fn ($segment) => $segment !== ''));

        // <cloud>/<resource_type>/<delivery_type>/<public_id...>
        if (count($segments) < 4) {
            return null;
        }

        if (! $this->matchesConfiguredCloud($segments[0])) {
            return null;
        }

        // Everything the API hands back sits after `upload`.
        $segments = array_slice($segments, 3);

        // Transformations and the version form a contiguous run at the front of
        // what is left. Only leading segments are inspected, so a public_id that
        // itself contains an underscore (products/my_shirt.jpg) is left alone.
        while ($segments !== [] && $this->looksLikeTransformation($segments[0])) {
            array_shift($segments);
        }

        if (isset($segments[0]) && preg_match('/^v\d+$/i', $segments[0])) {
            array_shift($segments);
        }

        if ($segments === []) {
            return null;
        }

        $publicId = preg_replace(
            '/\.(jpe?g|png|webp|gif|avif|bmp|tiff?|svg|ico|heics?|jfif|mp4|webm|mov|m4a|mp3|pdf)$/i',
            '',
            implode('/', $segments)
        );

        return ($publicId !== null && $publicId !== '') ? $publicId : null;
    }

    /**
     * Whether a path segment is a transformation token (w_300, c_fill, $named)
     * rather than part of the public_id.
     */
    private function looksLikeTransformation(string $segment): bool
    {
        return (bool) preg_match('/^(\$[A-Za-z0-9_-]+|[a-z]+_[A-Za-z0-9._-]+(,[a-z]+_[A-Za-z0-9._-]+)*)$/', $segment);
    }

    /**
     * Guard against destroying assets in a different cloud: the first path
     * segment of a delivery URL must be the cloud name from CLOUDINARY_URL.
     */
    private function matchesConfiguredCloud(string $cloudName): bool
    {
        $configured = config('cloudinary.cloud_url');

        if (! is_string($configured) || $configured === '') {
            return false;
        }

        $host = parse_url($configured, PHP_URL_HOST);

        return is_string($host) && $host !== '' && strcasecmp($host, $cloudName) === 0;
    }
}
