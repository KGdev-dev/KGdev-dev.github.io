<?php

try {
    require_once __DIR__ . '/db_connect.php';

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            reviewer_name VARCHAR(255) NOT NULL,
            rating INT NOT NULL,
            review_text TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_reviews_product
                FOREIGN KEY (product_id) REFERENCES products(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE,
            CONSTRAINT chk_reviews_rating
                CHECK (rating BETWEEN 1 AND 5)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS saved_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(255) NOT NULL,
            user_id INT NULL,
            product_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_saved_items_session_id (session_id),
            INDEX idx_saved_items_user_id (user_id),
            INDEX idx_saved_items_product_id (product_id),
            CONSTRAINT fk_saved_items_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE SET NULL
                ON UPDATE CASCADE,
            CONSTRAINT fk_saved_items_product
                FOREIGN KEY (product_id) REFERENCES products(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $productStatement = $pdo->prepare(
        'SELECT id, title FROM products
         WHERE LOWER(title) LIKE :exact_title
            OR (LOWER(title) LIKE :grade_8_title AND LOWER(title) LIKE :blazer_title)
         ORDER BY id ASC
         LIMIT 1'
    );
    $productStatement->execute([
        ':exact_title' => '%grade 8 blazer%',
        ':grade_8_title' => '%grade 8%',
        ':blazer_title' => '%blazer%',
    ]);
    $sampleProduct = $productStatement->fetch();

    $insertedReviews = 0;
    $selectedProductTitle = null;

    if ($sampleProduct !== false) {
        $selectedProductTitle = (string) ($sampleProduct['title'] ?? 'Grade 8 blazer');
        $sampleReviews = [
            [
                'reviewer_name' => 'Thandi M.',
                'rating' => 5,
                'review_text' => 'The blazer looks neat, fits well, and arrived in excellent condition. Perfect for school wear.',
            ],
            [
                'reviewer_name' => 'Jordan P.',
                'rating' => 4,
                'review_text' => 'Good quality fabric and the stitching is solid. Exactly what we needed for Grade 8.',
            ],
            [
                'reviewer_name' => 'Aisha K.',
                'rating' => 5,
                'review_text' => 'Clean, smart, and true to size. It gives the blazer a proper polished look.',
            ],
        ];

        $checkReview = $pdo->prepare(
            'SELECT id FROM reviews
             WHERE product_id = :product_id
               AND reviewer_name = :reviewer_name
               AND rating = :rating
               AND review_text = :review_text
             LIMIT 1'
        );

        $insertReview = $pdo->prepare(
            'INSERT INTO reviews (product_id, reviewer_name, rating, review_text)
             VALUES (:product_id, :reviewer_name, :rating, :review_text)'
        );

        foreach ($sampleReviews as $review) {
            $checkReview->execute([
                ':product_id' => (int) $sampleProduct['id'],
                ':reviewer_name' => $review['reviewer_name'],
                ':rating' => $review['rating'],
                ':review_text' => $review['review_text'],
            ]);

            if ($checkReview->fetch() !== false) {
                continue;
            }

            $insertReview->execute([
                ':product_id' => (int) $sampleProduct['id'],
                ':reviewer_name' => $review['reviewer_name'],
                ':rating' => $review['rating'],
                ':review_text' => $review['review_text'],
            ]);

            $insertedReviews++;
        }
    }

    $successMessage = 'Marketplace extras were created successfully.';
    $detailsMessage = $sampleProduct === false
        ? 'The reviews table was created, but no Grade 8 blazer product was found yet, so sample reviews were not inserted.'
        : sprintf(
            '%d sample review%s added for %s.',
            $insertedReviews,
            $insertedReviews === 1 ? '' : 's',
            $selectedProductTitle
        );
} catch (PDOException $exception) {
    $errorMessage = $exception->getMessage();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Kasi Exchange | Marketplace Extras</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
    <main class="container py-5" style="max-width: 760px;">
        <div class="alert alert-danger shadow-sm" role="alert">
            <h1 class="h5 mb-2">Seed failed</h1>
            <div><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </main>
    </body>
    </html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Marketplace Extras</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 760px;">
    <div class="alert alert-success shadow-sm" role="alert">
        <h1 class="h5 mb-2">Marketplace extras seeded successfully</h1>
        <p class="mb-2"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <p class="mb-0"><?= htmlspecialchars($detailsMessage, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
</main>
</body>
</html>