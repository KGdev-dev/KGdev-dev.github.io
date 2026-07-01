<?php

$required_roles = ['seller'];
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_connect.php';

if (!function_exists('kasi_exchange_csrf_token')) {
    function kasi_exchange_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

function kasi_exchange_upload_directory(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
}

function kasi_exchange_ensure_upload_directory(): void
{
    $uploadDirectory = kasi_exchange_upload_directory();

    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Unable to create uploads directory.');
    }
}

function kasi_exchange_normalize_decimal(string $value): ?string
{
    $value = trim($value);

    if ($value === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
        return null;
    }

    return number_format((float) $value, 2, '.', '');
}

function kasi_exchange_upload_error_message(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE => 'UPLOAD_ERR_INI_SIZE (1): The uploaded file exceeds the upload_max_filesize directive in php.ini.',
        UPLOAD_ERR_FORM_SIZE => 'UPLOAD_ERR_FORM_SIZE (2): The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.',
        UPLOAD_ERR_PARTIAL => 'UPLOAD_ERR_PARTIAL (3): The uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'UPLOAD_ERR_NO_FILE (4): No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'UPLOAD_ERR_NO_TMP_DIR (6): Missing a temporary folder on the server.',
        UPLOAD_ERR_CANT_WRITE => 'UPLOAD_ERR_CANT_WRITE (7): Failed to write the uploaded file to disk.',
        UPLOAD_ERR_EXTENSION => 'UPLOAD_ERR_EXTENSION (8): A PHP extension stopped the file upload.',
        default => 'Unknown upload error code (' . $errorCode . ').',
    };
}

function kasi_exchange_allowed_image_mime_types(): array
{
    return ['image/jpeg', 'image/pjpeg', 'image/png'];
}

function kasi_exchange_allowed_image_extensions(): array
{
    return ['jpg', 'jpeg', 'png'];
}

function kasi_exchange_create_image_resource(string $filePath, int $imageType)
{
    return match ($imageType) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($filePath),
        IMAGETYPE_PNG => imagecreatefrompng($filePath),
        default => false,
    };
}

function kasi_exchange_resize_and_save_image(string $sourcePath, string $destinationPath, int $imageType): void
{
    $sourceImage = kasi_exchange_create_image_resource($sourcePath, $imageType);

    if ($sourceImage === false) {
        throw new RuntimeException('Unable to process the uploaded image.');
    }

    $sourceWidth = imagesx($sourceImage);
    $sourceHeight = imagesy($sourceImage);

    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
        imagedestroy($sourceImage);
        throw new RuntimeException('Invalid image dimensions.');
    }

    $scale = min(800 / $sourceWidth, 800 / $sourceHeight, 1);
    $targetWidth = max(1, (int) round($sourceWidth * $scale));
    $targetHeight = max(1, (int) round($sourceHeight * $scale));

    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

    $white = imagecolorallocate($targetImage, 255, 255, 255);
    imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $white);
    imagecopyresampled($targetImage, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

    if (!imagejpeg($targetImage, $destinationPath, 65)) {
        imagedestroy($sourceImage);
        imagedestroy($targetImage);
        throw new RuntimeException('Unable to save the optimized image.');
    }

    imagedestroy($sourceImage);
    imagedestroy($targetImage);
}

$errors = [];
$successMessage = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_success']);

$title = '';
$description = '';
$price = '';
$size = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Invalid form submission. Please try again.';
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $priceInput = trim((string) ($_POST['price'] ?? ''));
    $size = trim((string) ($_POST['size'] ?? ''));

    if ($title === '') {
        $errors[] = 'Title is required.';
    } elseif (mb_strlen($title) > 150) {
        $errors[] = 'Title must be 150 characters or fewer.';
    }

    if ($description === '') {
        $errors[] = 'Description is required.';
    } elseif (mb_strlen($description) > 1000) {
        $errors[] = 'Description must be 1000 characters or fewer.';
    }

    $price = kasi_exchange_normalize_decimal($priceInput) ?? '';
    if ($price === '' || (float) $price <= 0) {
        $errors[] = 'Price must be a valid decimal amount in ZAR.';
    }

    if ($size === '') {
        $errors[] = 'Size is required.';
    } elseif (mb_strlen($size) > 50) {
        $errors[] = 'Size must be 50 characters or fewer.';
    }

    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        $errors[] = 'A uniform image is required.';
    } else {
        $imageFile = $_FILES['image'];
        $uploadError = (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($uploadError !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed: ' . kasi_exchange_upload_error_message($uploadError);
        }

        $tmpName = (string) ($imageFile['tmp_name'] ?? '');
        $originalName = (string) ($imageFile['name'] ?? '');
        $originalExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $declaredMimeType = strtolower(trim((string) ($imageFile['type'] ?? '')));

        if ($uploadError === UPLOAD_ERR_OK) {
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                $errors[] = 'Please upload a valid image file.';
            } elseif (!in_array($originalExtension, kasi_exchange_allowed_image_extensions(), true)) {
                $errors[] = 'Only JPG, JPEG, and PNG file extensions are allowed.';
            } elseif (!in_array($declaredMimeType, kasi_exchange_allowed_image_mime_types(), true)) {
                $errors[] = 'Only image/jpeg, image/pjpeg, and image/png MIME types are allowed.';
            } else {
                $imageInfo = @getimagesize($tmpName);

                if ($imageInfo === false || !in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                    $errors[] = 'Only JPEG and PNG images are allowed.';
                }
            }
        }
    }

    if ($errors === [] && isset($imageFile, $imageInfo)) {
        try {
            kasi_exchange_ensure_upload_directory();

            $uniqueFilename = uniqid('uniform_', true) . '.jpg';
            $relativeImagePath = 'uploads/' . $uniqueFilename;
            $absoluteImagePath = __DIR__ . DIRECTORY_SEPARATOR . $relativeImagePath;

            kasi_exchange_resize_and_save_image($tmpName, $absoluteImagePath, $imageInfo[2]);

            $insert = $pdo->prepare(
                'INSERT INTO products (seller_id, title, description, price, size, image_path, status) VALUES (:seller_id, :title, :description, :price, :size, :image_path, :status)'
            );
            $insert->execute([
                ':seller_id' => (int) $_SESSION['user_id'],
                ':title' => $title,
                ':description' => $description,
                ':price' => $price,
                ':size' => $size,
                ':image_path' => $relativeImagePath,
                ':status' => 'available',
            ]);

            $_SESSION['flash_success'] = 'Product uploaded successfully.';
            header('Location: ' . kasi_exchange_url('upload_product.php'));
            exit;
        } catch (Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }
    }
}

$csrfToken = kasi_exchange_csrf_token();
$logoutUrl = kasi_exchange_url('logout.php');

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Upload Product</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 760px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <p class="text-uppercase text-muted small mb-1">Seller Tools</p>
            <h1 class="h4 mb-0">Upload Uniform</h1>
        </div>
        <a href="<?= htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark btn-sm">Log Out</a>
    </div>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success" role="alert"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4 p-md-5">
            <form method="post" action="<?= htmlspecialchars(kasi_exchange_url('upload_product.php'), ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" class="form-control" id="title" name="title" value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>" maxlength="150" required>
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="4" maxlength="1000" required><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="price" class="form-label">Price (ZAR)</label>
                        <input type="text" class="form-control" id="price" name="price" inputmode="decimal" placeholder="0.00" value="<?= htmlspecialchars($price, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="size" class="form-label">Size</label>
                        <input type="text" class="form-control" id="size" name="size" placeholder="Age 9-10 or Small" value="<?= htmlspecialchars($size, ENT_QUOTES, 'UTF-8') ?>" maxlength="50" required>
                    </div>
                </div>

                <div class="mb-4 mt-3">
                    <label for="image" class="form-label">Uniform Photo (JPEG or PNG)</label>
                    <input type="file" class="form-control" id="image" name="image" accept="image/jpeg,image/png" required>
                </div>

                <button type="submit" class="btn btn-cta w-100">Upload Product</button>
            </form>
        </div>
    </div>
</main>
</body>
</html>
