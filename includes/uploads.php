<?php
declare(strict_types=1);

/**
 * Validates and saves an uploaded image safely:
 *  - checks the REAL file content type (finfo), not the client-supplied
 *    MIME string or file extension, which are trivially spoofable
 *  - re-encodes the image via GD rather than copying the uploaded bytes,
 *    which strips ALL metadata (EXIF, GPS geotags, camera/device info)
 *  - writes under a random filename so nothing about the original name
 *    or origin leaks
 *
 * Returns the saved filename on success, or throws a RuntimeException
 * with a user-safe message on failure.
 */
function handle_image_upload(array $file, string $destDir, int $maxBytes = 8 * 1024 * 1024): string
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Please try again.');
    }
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('Image is too large (max 8MB).');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
    ];

    if (!isset($allowed[$realMime])) {
        throw new RuntimeException('Only JPEG, PNG, or WEBP images are allowed.');
    }

    $createFn = $allowed[$realMime];
    $srcImage = @$createFn($file['tmp_name']);
    if ($srcImage === false) {
        throw new RuntimeException('That file is not a valid image.');
    }

    // Re-encoding (rather than move_uploaded_file) is what strips metadata -
    // GD only reads pixel data, so EXIF/GPS tags never make it into the output.
    $filename = generate_public_id('img') . '.jpg';
    $destPath = rtrim($destDir, '/') . '/' . $filename;

    // Flatten to a white background in case of PNG transparency, then save as JPEG.
    $width = imagesx($srcImage);
    $height = imagesy($srcImage);
    $flattened = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($flattened, 255, 255, 255);
    imagefill($flattened, 0, 0, $white);
    imagecopy($flattened, $srcImage, 0, 0, 0, 0, $width, $height);

    imagejpeg($flattened, $destPath, 85);

    imagedestroy($srcImage);
    imagedestroy($flattened);

    return $filename;
}
