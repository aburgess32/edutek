<?php
if (!ob_get_level()) {
    ob_start();
}

include_once __DIR__ . '/navbar.php';

$cipher = CONTENT_CIPHER;
$options = 0;
$iv = CONTENT_CIPHER_IV;
$encryptionKey = CONTENT_CIPHER_KEY;

function comicBooksEncrypt(
    string $value,
    string $cipher,
    string $encryptionKey,
    int $options,
    string $iv
): string {
    return str_replace(
        '=',
        '[equal]',
        base64_encode(
            openssl_encrypt(
                $value,
                $cipher,
                $encryptionKey,
                $options,
                $iv
            )
        )
    );
}

function comicBooksLowercase(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function comicBooksMatchesPdf(string $filename): bool
{
    $title = comicBooksLowercase(
        pathinfo($filename, PATHINFO_FILENAME)
    );

    $keywordMatches = [
        'comic',
        'manga',
        'superhero',
        'superheroes',
        'supervillain',
        'supervillains',
    ];

    foreach ($keywordMatches as $keyword) {
        if (strpos($title, $keyword) !== false) {
            return true;
        }
    }

    return preg_match('/\bmarvel\b/u', $title) === 1;
}

function comicBooksBuildUrl(string $page, array $parameters): string
{
    return $page . '?' . http_build_query(
        $parameters,
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

function comicBooksLoadCache(string $cachePath, int $cacheSeconds): ?array
{
    if (
        !is_file($cachePath) ||
        !is_readable($cachePath) ||
        (time() - filemtime($cachePath)) > $cacheSeconds
    ) {
        return null;
    }

    $rawCache = file_get_contents($cachePath);

    if ($rawCache === false) {
        return null;
    }

    $cacheData = json_decode($rawCache, true);

    if (
        !is_array($cacheData) ||
        !isset($cacheData['items']) ||
        !is_array($cacheData['items'])
    ) {
        return null;
    }

    return $cacheData['items'];
}

function comicBooksWriteCache(string $cachePath, array $comicPdfs): void
{
    $cacheDirectory = dirname($cachePath);

    if (!is_dir($cacheDirectory)) {
        @mkdir($cacheDirectory, 0775, true);
    }

    if (!is_dir($cacheDirectory) || !is_writable($cacheDirectory)) {
        return;
    }

    $cacheData = [
        'generated_at' => date(DATE_ATOM),
        'items' => $comicPdfs,
    ];

    $encodedCache = json_encode(
        $cacheData,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($encodedCache === false) {
        return;
    }

    $temporaryPath = $cachePath . '.tmp';

    if (@file_put_contents($temporaryPath, $encodedCache, LOCK_EX) !== false) {
        @rename($temporaryPath, $cachePath);
    }
}

function comicBooksScanLibrary(string $booksFsPath): array
{
    $comicPdfs = [];

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $booksFsPath,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            if (strtolower($fileInfo->getExtension()) !== 'pdf') {
                continue;
            }

            if (!comicBooksMatchesPdf($fileInfo->getFilename())) {
                continue;
            }

            $comicPdfs[] = [
                'pdf_path' => $fileInfo->getPathname(),
                'pdf_name' => $fileInfo->getFilename(),
                'title' => pathinfo(
                    $fileInfo->getFilename(),
                    PATHINFO_FILENAME
                ),
                'folder_path' => $fileInfo->getPath(),
                'folder_name' => basename($fileInfo->getPath()),
            ];
        }
    } catch (UnexpectedValueException $exception) {
        return [];
    }

    usort(
        $comicPdfs,
        static function (array $left, array $right): int {
            return strnatcasecmp($left['title'], $right['title']);
        }
    );

    return $comicPdfs;
}

$booksFsPath = '/content/Books';

if (!is_dir($booksFsPath)) {
    $legacyBooksPath = __DIR__ . '/videos/Books';

    if (is_dir($legacyBooksPath)) {
        $booksFsPath = $legacyBooksPath;
    }
}

$cachePath = __DIR__ . '/storage/comic-books-cache.json';
$cacheSeconds = 600;

$comicPdfs = [];
$comicPdfsFromCache = false;

if (is_dir($booksFsPath)) {
    $cachedComicPdfs = comicBooksLoadCache(
        $cachePath,
        $cacheSeconds
    );

    if ($cachedComicPdfs !== null) {
        $comicPdfs = $cachedComicPdfs;
        $comicPdfsFromCache = true;
    } else {
        $comicPdfs = comicBooksScanLibrary($booksFsPath);
        comicBooksWriteCache($cachePath, $comicPdfs);
    }
}

$videolink1 = comicBooksEncrypt(
    'videolink1',
    $cipher,
    $encryptionKey,
    $options,
    $iv
);

$videoname1 = comicBooksEncrypt(
    'videoname1',
    $cipher,
    $encryptionKey,
    $options,
    $iv
);

$videolink = comicBooksEncrypt(
    'videolink',
    $cipher,
    $encryptionKey,
    $options,
    $iv
);

$videoname = comicBooksEncrypt(
    'videoname',
    $cipher,
    $encryptionKey,
    $options,
    $iv
);

$comicThumbnailKey = comicBooksEncrypt(
    'file',
    $cipher,
    $encryptionKey,
    $options,
    $iv
);
?>
<link href="css/saved.css" rel="stylesheet">

<style>
.comic-books-page {
    max-width: 1200px;
    margin: 0 auto;
    padding: 18px 14px 32px;
}

.comic-books-intro {
    margin: 0 0 18px;
    padding: 14px 18px;
    border: 1px solid #dbeafe;
    border-radius: 10px;
    background: #f8fbff;
    color: #1e3a5f;
}

.comic-books-intro p {
    margin: 0;
}

.comic-books-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 14px;
}

.comic-book-card {
    display: flex;
    min-height: 185px;
    overflow: hidden;
    border: 1px solid #f0b7d0;
    border-radius: 4px;
    background: #ffffff;
}

.comic-book-cover-link {
    display: block;
    width: 126px;
    min-width: 126px;
    height: 185px;
    background: #312e81;
}

.comic-book-cover {
    display: block;
    width: 126px;
    height: 185px;
    object-fit: cover;
}

.comic-book-details {
    display: flex;
    flex: 1;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
    padding: 16px;
}

.comic-book-title {
    margin: 0;
    font-size: 16px;
    line-height: 1.35;
}

.comic-book-title a {
    color: #172554;
    text-decoration: none;
}

.comic-book-title a:hover,
.comic-book-title a:focus {
    color: #4338ca;
    text-decoration: underline;
}

.comic-book-category {
    margin: 8px 0 0;
    color: #64748b;
    font-family: Arial, sans-serif;
    font-size: 14px;
}

.comic-book-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: auto;
    padding-top: 14px;
}

.comic-book-action {
    display: inline-block;
    padding: 7px 10px;
    border: 1px solid #c7d2fe;
    border-radius: 6px;
    background: #ffffff;
    color: #3730a3;
    font-family: Arial, sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
}

.comic-book-action:hover,
.comic-book-action:focus {
    background: #eef2ff;
    color: #312e81;
    text-decoration: none;
}

.comic-books-empty {
    padding: 22px;
    border: 1px solid #dbeafe;
    border-radius: 10px;
    background: #f8fbff;
    color: #475569;
}

@media (max-width: 560px) {
    .comic-books-grid {
        grid-template-columns: 1fr;
    }

    .comic-book-card {
        min-height: 150px;
    }

    .comic-book-cover-link,
    .comic-book-cover {
        width: 102px;
        min-width: 102px;
        height: 150px;
    }

    .comic-book-details {
        padding: 12px;
    }
}
</style>

<div class="SaveWrapper">
    <span class="fa fa-fw fa-bookmark"></span> Comic Books
</div>

<main class="comic-books-page">
    <div class="comic-books-intro">
        <p>
            Comic books, manga, and superhero titles available in the Books library.
        </p>
    </div>

    <?php if (!is_dir($booksFsPath)): ?>
        <div class="comic-books-empty">
            Books folder not found:
            <code>
                <?php echo htmlspecialchars(
                    $booksFsPath,
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>
            </code>
        </div>

    <?php elseif (empty($comicPdfs)): ?>
        <div class="comic-books-empty">
            No comic-related PDFs were found in the Books library.
        </div>

    <?php else: ?>
        <div class="comic-books-grid">
            <?php foreach ($comicPdfs as $comicPdf): ?>
                <?php
                $readUrl = comicBooksBuildUrl(
                    'readpdf.php',
                    [
                        $videolink => comicBooksEncrypt(
                            $comicPdf['folder_path'],
                            $cipher,
                            $encryptionKey,
                            $options,
                            $iv
                        ),
                        $videoname => comicBooksEncrypt(
                            $comicPdf['folder_name'],
                            $cipher,
                            $encryptionKey,
                            $options,
                            $iv
                        ),
                        $videolink1 => comicBooksEncrypt(
                            $comicPdf['pdf_path'],
                            $cipher,
                            $encryptionKey,
                            $options,
                            $iv
                        ),
                        $videoname1 => comicBooksEncrypt(
                            $comicPdf['pdf_name'],
                            $cipher,
                            $encryptionKey,
                            $options,
                            $iv
                        ),
                    ]
                );

                $thumbnailUrl = comicBooksBuildUrl(
                    'comic_thumb.php',
                    [
                        $comicThumbnailKey => comicBooksEncrypt(
                            $comicPdf['pdf_path'],
                            $cipher,
                            $encryptionKey,
                            $options,
                            $iv
                        ),
                    ]
                );

                $downloadUrl = 'content/'
                    . ltrim($comicPdf['pdf_path'], '/content/');

                $safeReadUrl = htmlspecialchars(
                    $readUrl,
                    ENT_QUOTES,
                    'UTF-8'
                );

                $safeThumbnailUrl = htmlspecialchars(
                    $thumbnailUrl,
                    ENT_QUOTES,
                    'UTF-8'
                );

                $safeDownloadUrl = htmlspecialchars(
                    $downloadUrl,
                    ENT_QUOTES,
                    'UTF-8'
                );

                $safeTitle = htmlspecialchars(
                    $comicPdf['title'],
                    ENT_QUOTES,
                    'UTF-8'
                );

                $safeFolderName = htmlspecialchars(
                    $comicPdf['folder_name'],
                    ENT_QUOTES,
                    'UTF-8'
                );

                $safePdfName = htmlspecialchars(
                    $comicPdf['pdf_name'],
                    ENT_QUOTES,
                    'UTF-8'
                );
                ?>

                <article class="comic-book-card">
                    <a
                        class="comic-book-cover-link"
                        href="<?php echo $safeReadUrl; ?>"
                        aria-label="Read <?php echo $safeTitle; ?>"
                    >
                        <img
                            class="comic-book-cover"
                            src="<?php echo $safeThumbnailUrl; ?>"
                            alt="<?php echo $safeTitle; ?> cover"
                            loading="lazy"
                        >
                    </a>

                    <div class="comic-book-details">
                        <h2 class="comic-book-title">
                            <a href="<?php echo $safeReadUrl; ?>">
                                <?php echo $safeTitle; ?>
                            </a>
                        </h2>

                        <p class="comic-book-category">
                            Category: <?php echo $safeFolderName; ?>
                        </p>

                        <div class="comic-book-actions">
                            <a
                                class="comic-book-action"
                                href="<?php echo $safeReadUrl; ?>"
                            >
                                Read
                            </a>

                            <a
                                class="comic-book-action"
                                href="<?php echo $safeDownloadUrl; ?>"
                                download="<?php echo $safePdfName; ?>"
                            >
                                Download
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php include_once __DIR__ . '/footer.php'; ?>