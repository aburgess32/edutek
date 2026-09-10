<?php
/**
 * Read-only folder-level catalogs for the global search API.
 *
 * This file intentionally does not write to content_meta, does not invoke
 * the video indexer, and does not recursively scan audiobook/music tracks.
 * It mirrors the currently working encrypted route contracts used by:
 *   - books.php
 *   - audiobooks.php
 *   - music.php
 */

/**
 * Case-insensitive text normalization with an mbstring fallback.
 */
function unifiedSearchLower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

/**
 * True when a catalog title contains the user query.
 */
function unifiedSearchMatches(string $title, string $queryLower): bool
{
    return $queryLower === ''
        || strpos(unifiedSearchLower($title), $queryLower) !== false;
}

/**
 * Encrypt an existing route parameter in the same legacy URL-safe format
 * used by the public catalog pages.
 */
function unifiedSearchEncryptParam(
    string $value,
    string $cipher,
    string $encryptionKey,
    int $options,
    string $iv
): string {
    $encrypted = openssl_encrypt(
        $value,
        $cipher,
        $encryptionKey,
        $options,
        $iv
    );

    return str_replace(
        '=',
        '[equal]',
        base64_encode($encrypted)
    );
}

/**
 * Generate the four legacy encrypted query-key names used by the Books and
 * Audio player/listing pages.
 *
 * @return array<string, string>
 */
function unifiedSearchLegacyKeys(
    string $cipher,
    string $encryptionKey,
    int $options,
    string $iv
): array {
    return [
        'videolink1' => unifiedSearchEncryptParam(
            'videolink1',
            $cipher,
            $encryptionKey,
            $options,
            $iv
        ),
        'videoname1' => unifiedSearchEncryptParam(
            'videoname1',
            $cipher,
            $encryptionKey,
            $options,
            $iv
        ),
        'videolink' => unifiedSearchEncryptParam(
            'videolink',
            $cipher,
            $encryptionKey,
            $options,
            $iv
        ),
        'videoname' => unifiedSearchEncryptParam(
            'videoname',
            $cipher,
            $encryptionKey,
            $options,
            $iv
        ),
    ];
}

/**
 * Build the existing encrypted Book-category route used by books.php.
 */
function unifiedSearchBookCategoryUrl(
    string $folderFsPath,
    string $folderName,
    array $keys,
    string $cipher,
    string $encryptionKey,
    int $options,
    string $iv
): string {
    $encryptedFolder = unifiedSearchEncryptParam(
        $folderFsPath,
        $cipher,
        $encryptionKey,
        $options,
        $iv
    );

    $encryptedFolderName = unifiedSearchEncryptParam(
        $folderName,
        $cipher,
        $encryptionKey,
        $options,
        $iv
    );

    return 'listofbooks.php?&'
        . $keys['videolink1'] . '=&'
        . $keys['videoname1'] . '=&'
        . $keys['videolink'] . '=' . $encryptedFolder . '&'
        . $keys['videoname'] . '=' . $encryptedFolderName;
}

/**
 * Build the existing encrypted folder route used by audiobooks.php/music.php.
 *
 * Audiobooks and Music use the legacy BF-CBC listener contract. Do not use
 * the API/video CONTENT_CIPHER values here.
 */
function unifiedSearchAudioFolderUrl(
    string $folderFsPath,
    string $folderName,
    array $keys,
    string $cipher,
    string $encryptionKey,
    int $options,
    string $iv
): string {
    $audioCipher = 'BF-CBC';
    $audioEncryptionKey = 'hfjfydjnvhbjfi';
    $audioIv = '91011121';
    $audioOptions = 0;

    $audioKeys = unifiedSearchLegacyKeys(
        $audioCipher,
        $audioEncryptionKey,
        $audioOptions,
        $audioIv
    );

    $encryptedFolder = unifiedSearchEncryptParam(
        rtrim($folderFsPath, '/') . '/',
        $audioCipher,
        $audioEncryptionKey,
        $audioOptions,
        $audioIv
    );

    $encryptedFolderName = unifiedSearchEncryptParam(
        $folderName,
        $audioCipher,
        $audioEncryptionKey,
        $audioOptions,
        $audioIv
    );

    return 'listen.php?&'
        . $audioKeys['videolink1'] . '=&'
        . $audioKeys['videoname1'] . '=&'
        . $audioKeys['videolink'] . '=' . $encryptedFolder . '&'
        . $audioKeys['videoname'] . '=' . $encryptedFolderName;
}

/**
 * Find matching Book category folders. Checks only immediate PDF files to
 * exclude empty categories; it does not enumerate/search all PDF titles.
 *
 * @return array<int, array<string, mixed>>
 */
function unifiedSearchBooks(
    string $query,
    int $limit,
    string $cipher,
    string $encryptionKey,
    int $options,
    string $iv
): array {
    $booksFsPath = '/content/Books';

    if (!is_dir($booksFsPath)) {
        return [];
    }

    $queryLower = unifiedSearchLower($query);
    $keys = unifiedSearchLegacyKeys($cipher, $encryptionKey, $options, $iv);
    $folders = glob($booksFsPath . '/*', GLOB_ONLYDIR) ?: [];

    $items = [];

    foreach ($folders as $folderFsPath) {
        $folderName = basename($folderFsPath);

        if ($folderName === '' || strncmp($folderName, '_', 1) === 0) {
            continue;
        }

        if (!unifiedSearchMatches($folderName, $queryLower)) {
            continue;
        }

        $pdfFiles = glob($folderFsPath . '/*.pdf') ?: [];
        $pdfCount = count($pdfFiles);

        if ($pdfCount === 0) {
            continue;
        }

        $items[] = [
            'content_id'       => 'book-category:' . $folderName,
            'title'            => $folderName,
            'category'         => 'Books',
            'subcategory'      => '',
            'source'           => 'Local Books',
            'content_type'     => 'book',
            'duration_seconds' => null,
            'thumbnail_path'   => 'book_thumb.php?folder=' . rawurlencode($folderName),
            'file_path'        => null,
            'result_url'       => unifiedSearchBookCategoryUrl(
                $folderFsPath,
                $folderName,
                $keys,
                $cipher,
                $encryptionKey,
                $options,
                $iv
            ),
            'match_type'       => 'exact',
            'item_count'       => $pdfCount,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strnatcasecmp($a['title'], $b['title']);
    });

    return array_slice($items, 0, max(1, $limit));
}
/**
 * Find a cover image for one audiobook folder without scanning chapters.
 *
 * Returns a content-relative browser path. The frontend encodes the final
 * URL safely before using it in an image src attribute.
 */
function unifiedSearchAudiobookCoverPath(
    string $folderFsPath,
    string $folderName
): ?string {
    $coverCandidates = [
        $folderFsPath . '/' . $folderName . '.jpg',
        $folderFsPath . '/' . $folderName . '.jpeg',
        $folderFsPath . '/' . $folderName . '.png',
        $folderFsPath . '/cover.jpg',
        $folderFsPath . '/cover.jpeg',
        $folderFsPath . '/cover.png',
        $folderFsPath . '/folder.jpg',
        $folderFsPath . '/folder.jpeg',
        $folderFsPath . '/folder.png',
    ];

    foreach ($coverCandidates as $coverFsPath) {
        if (!is_file($coverFsPath)) {
            continue;
        }

        $relativePath = ltrim(
            substr($coverFsPath, strlen('/content/')),
            '/'
        );

        return 'content/' . $relativePath;
    }

    return null;
}
/**
 * Find matching Audiobook folders. One top-level folder is one audiobook
 * entry, matching the established audiobooks.php behavior.
 *
 * @return array<int, array<string, mixed>>
 */
function unifiedSearchAudiobooks(
    string $query,
    int $limit,
    string $cipher,
    string $encryptionKey,
    int $options,
    string $iv
): array {
    $audiobooksFsPath = '/content/Audiobooks';

    if (!is_dir($audiobooksFsPath)) {
        return [];
    }

    $queryLower = unifiedSearchLower($query);
    $keys = unifiedSearchLegacyKeys($cipher, $encryptionKey, $options, $iv);
    $folders = glob($audiobooksFsPath . '/*', GLOB_ONLYDIR) ?: [];

    $items = [];

    foreach ($folders as $folderFsPath) {
        $folderName = basename($folderFsPath);

        if ($folderName === '' || strncmp($folderName, '_', 1) === 0) {
            continue;
        }

        if (!unifiedSearchMatches($folderName, $queryLower)) {
            continue;
        }

        $items[] = [
            'content_id'       => 'audiobook:' . $folderName,
            'title'            => $folderName,
            'category'         => 'Audiobooks',
            'subcategory'      => '',
            'source'           => 'Local Audiobooks',
            'content_type'     => 'audiobook',
            'duration_seconds' => null,
			'thumbnail_path'   => unifiedSearchAudiobookCoverPath($folderFsPath,$folderName),
			'file_path'        => null,
            'result_url'       => unifiedSearchAudioFolderUrl(
                $folderFsPath,
                $folderName,
                $keys,
                $cipher,
                $encryptionKey,
                $options,
                $iv
            ),
            'match_type'       => 'exact',
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strnatcasecmp($a['title'], $b['title']);
    });

    return array_slice($items, 0, max(1, $limit));
}

/**
 * Find matching Music album/playlist folders. It deliberately searches only
 * the top-level folder titles. It does not recursively scan track files.
 *
 * @return array<int, array<string, mixed>>
 */
function unifiedSearchMusic(
    string $query,
    int $limit,
    string $cipher,
    string $encryptionKey,
    int $options,
    string $iv
): array {
    $musicFsPath = '/var/www/html/videos/Music';

    if (!is_dir($musicFsPath)) {
        return [];
    }

    $queryLower = unifiedSearchLower($query);
    $keys = unifiedSearchLegacyKeys($cipher, $encryptionKey, $options, $iv);
    $folders = glob($musicFsPath . '/*', GLOB_ONLYDIR) ?: [];

    $items = [];

    foreach ($folders as $folderFsPath) {
        $folderName = basename($folderFsPath);

        if ($folderName === '' || strncmp($folderName, '_', 1) === 0) {
            continue;
        }

        if (!unifiedSearchMatches($folderName, $queryLower)) {
            continue;
        }

        /*
         * Do not recurse here. We mirror the catalog unit (a playlist folder)
         * and defer audio-track discovery to listen.php after the user opens it.
         */
        $items[] = [
            'content_id'       => 'music:' . $folderName,
            'title'            => $folderName,
            'category'         => 'Music',
            'subcategory'      => '',
            'source'           => 'Local Music',
            'content_type'     => 'music',
            'duration_seconds' => null,
            'thumbnail_path'   => null,
            'file_path'        => null,
            'result_url'       => unifiedSearchAudioFolderUrl(
                $folderFsPath,
                $folderName,
                $keys,
                $cipher,
                $encryptionKey,
                $options,
                $iv
            ),
            'match_type'       => 'exact',
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strnatcasecmp($a['title'], $b['title']);
    });

    return array_slice($items, 0, max(1, $limit));
}