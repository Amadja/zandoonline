<?php
declare(strict_types=1);

function save_product_image(array $upload): ?string
{
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('The image upload did not complete. Please try again.');
    }

    $size = (int) ($upload['size'] ?? 0);
    if ($size < 1 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('Product images must be smaller than 5 MB.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $dimensions = getimagesize($upload['tmp_name']);
    if (!isset($extensions[$mimeType]) || $dimensions === false || $dimensions[0] > 6000 || $dimensions[1] > 6000) {
        throw new RuntimeException('Upload a valid JPEG, PNG, or WebP image up to 6000 by 6000 pixels.');
    }

    $directory = ROOT_PATH . '/public/assets/uploads/products';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The product image folder could not be created.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file($upload['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('The product image could not be saved.');
    }

    return 'assets/uploads/products/' . $filename;
}

function delete_product_image(?string $imagePath): void
{
    $prefix = 'assets/uploads/products/';
    if ($imagePath === null || !str_starts_with($imagePath, $prefix)) {
        return;
    }

    $filename = basename($imagePath);
    $path = ROOT_PATH . '/public/assets/uploads/products/' . $filename;
    if (is_file($path)) {
        unlink($path);
    }
}