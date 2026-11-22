<?php
/**
 * Pictures added while writing a post.
 *
 * Every upload is checked as a real image, shrunk to a sensible width and saved again from scratch,
 * which also strips any camera or location data. The file name never comes from the upload itself.
 */
class Images
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_WIDTH = 1400;
    private const TYPES     = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    public static function dir(): string
    {
        return Store::root() . '/images/blog';
    }

    /** @return array{url?: string, width?: int, height?: int, error?: string} */
    public static function store(array $file, string $slug): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['error' => 'That image is over 8 MB. Please save a smaller version and try again.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return ['error' => 'The upload did not finish. Please try again.'];
        }

        $info = @getimagesize($file['tmp_name']);
        if (!$info || !isset(self::TYPES[$info[2]])) {
            return ['error' => 'Only JPG, PNG and WebP pictures can be used.'];
        }
        [$width, $height] = [$info[0], $info[1]];
        if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000) {
            return ['error' => 'That image is an unusual size. Please use one under 8000 pixels wide.'];
        }

        $extension = self::TYPES[$info[2]];
        $name      = (Store::slugify($slug) ?: 'post') . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0775, true)) {
            return ['error' => 'The images folder could not be created on the server.'];
        }

        $target = self::dir() . '/' . $name;
        $saved  = self::resize($file['tmp_name'], $target, $info[2], $width, $height);
        if ($saved === null) {
            if (!@move_uploaded_file($file['tmp_name'], $target)) {
                return ['error' => 'The image could not be saved on the server.'];
            }
            $saved = [$width, $height];
        }

        return ['url' => '/images/blog/' . $name, 'width' => $saved[0], 'height' => $saved[1]];
    }

    /**
     * Re-saves the picture at no more than MAX_WIDTH. Returns the new size, or null when the
     * server has no image library, in which case the caller keeps the original file.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function resize(string $source, string $target, int $type, int $width, int $height): ?array
    {
        if (!function_exists('imagecreatetruecolor')) return null;

        $readers = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
        $reader  = $readers[$type] ?? null;
        if (!$reader || !function_exists($reader)) return null;

        $image = @$reader($source);
        if (!$image) return null;

        $newWidth  = min($width, self::MAX_WIDTH);
        $newHeight = (int) round($height * ($newWidth / $width));
        $canvas    = imagecreatetruecolor($newWidth, $newHeight);
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
        }
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $ok = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($canvas, $target, 82),
            IMAGETYPE_PNG  => imagepng($canvas, $target, 6),
            IMAGETYPE_WEBP => imagewebp($canvas, $target, 82),
            default        => false,
        };
        imagedestroy($canvas);
        imagedestroy($image);

        return $ok ? [$newWidth, $newHeight] : null;
    }
}
