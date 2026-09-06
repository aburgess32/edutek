<?php
ob_start();

include_once __DIR__ . '/navbar.php';

$cipher = CONTENT_CIPHER;
$options = 0;
$iv = CONTENT_CIPHER_IV;
$encryption_key = CONTENT_CIPHER_KEY;

$searchQuery = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$searchQueryLower = function_exists('mb_strtolower')
    ? mb_strtolower($searchQuery, 'UTF-8')
    : strtolower($searchQuery);

function booksLowercase($value)
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function booksEncryptParameter($value, $cipher, $encryptionKey, $options, $iv)
{
    return str_replace(
        '=',
        '[equal]',
        base64_encode(
            openssl_encrypt($value, $cipher, $encryptionKey, $options, $iv)
        )
    );
}
?>
<link href="css/saved.css" rel="stylesheet">

<style>
.books-search {
    clear: both;
    max-width: 760px;
    margin: 18px auto 24px;
    padding: 16px;
    border: 1px solid #ddd6fe;
    border-radius: 10px;
    background: #faf9ff;
    font-family: Arial, sans-serif;
}

.books-search__label {
    display: block;
    margin-bottom: 8px;
    color: #312e81;
    font-size: 16px;
    font-weight: 700;
}

.books-search__input {
    box-sizing: border-box;
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #a5b4fc;
    border-radius: 6px;
    font: inherit;
}

.books-search__status {
    min-height: 20px;
    margin: 8px 0 0;
    color: #475569;
    font-size: 14px;
}

.books-search__clear {
    display: inline-block;
    margin-top: 10px;
    color: #5b21b6;
    font-size: 14px;
}
</style>

<?php
$videolink1 = booksEncryptParameter(
    'videolink1',
    $cipher,
    $encryption_key,
    $options,
    $iv
);

$videoname1 = booksEncryptParameter(
    'videoname1',
    $cipher,
    $encryption_key,
    $options,
    $iv
);

$videolink = booksEncryptParameter(
    'videolink',
    $cipher,
    $encryption_key,
    $options,
    $iv
);

$videoname = booksEncryptParameter(
    'videoname',
    $cipher,
    $encryption_key,
    $options,
    $iv
);

$booksFsPath = '/content/Books';

if (!is_dir($booksFsPath)) {
    $alternateBooksPath = __DIR__ . '/videos/Books';

    if (is_dir($alternateBooksPath)) {
        $booksFsPath = $alternateBooksPath;
    }
}
?>

<div class="SaveWrapper">
    <span class="fa fa-fw fa-bookmark"></span>Books
</div>

<form class="books-search" method="get" action="books.php" role="search">
    <label class="books-search__label" for="books-search-input">
        Search all book categories
    </label>

    <input
        class="books-search__input"
        id="books-search-input"
        type="search"
        name="q"
        value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
        placeholder="Search book categories"
        autocomplete="off"
    >

    <p class="books-search__status" id="books-search-status" aria-live="polite"></p>

    <?php if ($searchQuery !== ''): ?>
        <a class="books-search__clear" href="books.php">Clear search</a>
    <?php endif; ?>
</form>

<?php
if (!is_dir($booksFsPath)) {
    echo '<div class="SavedWrapper">'
        . '<div class="rcontents">'
        . '<p><b class="rtitle">Books folder not found</b></p>'
        . '<label class="rdesc"><small>'
        . htmlspecialchars($booksFsPath, ENT_QUOTES, 'UTF-8')
        . '</small></label>'
        . '</div>'
        . '</div>';
} else {
    $folders = glob($booksFsPath . '/*', GLOB_ONLYDIR) ?: [];

    $folders = array_values(array_filter(
        $folders,
        static function ($folderFsPath) {
            return strncmp(basename($folderFsPath), '_', 1) !== 0;
        }
    ));

    if ($searchQueryLower !== '') {
        $folders = array_values(array_filter(
            $folders,
            static function ($folderFsPath) use ($searchQueryLower) {
                return strpos(
                    booksLowercase(basename($folderFsPath)),
                    $searchQueryLower
                ) !== false;
            }
        ));
    }

    natcasesort($folders);
    $folders = array_values($folders);

    $renderedCount = 0;

    foreach ($folders as $folderFsPath) {
        $pdfFiles = glob($folderFsPath . '/*.pdf') ?: [];
        $fileCount = count($pdfFiles);

        if ($fileCount === 0) {
            continue;
        }

        $folderName = basename($folderFsPath);

        $encryptedFolder = booksEncryptParameter(
            $folderFsPath,
            $cipher,
            $encryption_key,
            $options,
            $iv
        );

        $encryptedFolderName = booksEncryptParameter(
            $folderName,
            $cipher,
            $encryption_key,
            $options,
            $iv
        );

        $bookListUrl = 'listofbooks.php?&'
            . $videolink1 . '=&'
            . $videoname1 . '=&'
            . $videolink . '=' . $encryptedFolder . '&'
            . $videoname . '=' . $encryptedFolderName;

        $safeFolderName = htmlspecialchars(
            $folderName,
            ENT_QUOTES,
            'UTF-8'
        );

        $safeBookListUrl = htmlspecialchars(
            $bookListUrl,
            ENT_QUOTES,
            'UTF-8'
        );

        $renderedCount++;

        $bookThumbnailUrl = 'book_thumb.php?folder='
    . rawurlencode($folderName);

$safeBookThumbnailUrl = htmlspecialchars(
    $bookThumbnailUrl,
    ENT_QUOTES,
    'UTF-8'
);

echo '<div class="SavedWrapper book-category-card">'
    . '<div class="rcontents">'
    . '<a class="book-category-card__link" href="' . $safeBookListUrl . '">'
    . '<img'
    . ' class="book-category-card__image"'
    . ' src="' . $safeBookThumbnailUrl . '"'
    . ' alt="' . $safeFolderName . ' category"'
    . ' loading="lazy"'
    . ' onerror="this.classList.add(\'book-category-card__image--missing\');"'
    . '>'
    . '<div class="book-category-card__body">'
    . '<p><b class="rtitle">' . $safeFolderName . '</b></p>'
    . '<label class="rdesc"><small>('
    . number_format($fileCount)
    . ' PDF' . ($fileCount === 1 ? '' : 's')
    . ')</small></label>'
    . '</div>'
    . '</a>'
    . '</div>'
    . '</div>';
    }

    if ($renderedCount === 0) {
        $noResultsTitle = $searchQuery !== ''
            ? 'No book categories match "' . $searchQuery . '".'
            : 'No book folders found';

        echo '<div class="SavedWrapper">'
            . '<div class="rcontents">'
            . '<p><b class="rtitle">'
            . htmlspecialchars($noResultsTitle, ENT_QUOTES, 'UTF-8')
            . '</b></p>'
            . '</div>'
            . '</div>';
    }
}
?>

<script>
(function () {
    const input = document.getElementById('books-search-input');
    const status = document.getElementById('books-search-status');

    if (!input || !status) {
        return;
    }

    let timerId = null;
    const currentQuery = <?php echo json_encode($searchQuery); ?>;

    function runSearch() {
        const query = input.value.trim();

        if (query.length === 1) {
            status.textContent = 'Type one more character to search.';
            return;
        }

        if (query === currentQuery) {
            status.textContent = '';
            return;
        }

        status.textContent = 'Finding matches...';

        const url = new URL(window.location.href);

        if (query === '') {
            url.searchParams.delete('q');
        } else {
            url.searchParams.set('q', query);
        }

        window.location.assign(url.toString());
    }

    input.addEventListener('input', function () {
        window.clearTimeout(timerId);

        const query = input.value.trim();

        if (query.length === 1) {
            status.textContent = 'Type one more character to search.';
            return;
        }

        status.textContent = 'Finding matches...';
        timerId = window.setTimeout(runSearch, 400);
    });

    input.form.addEventListener('submit', function (event) {
        event.preventDefault();
        window.clearTimeout(timerId);
        runSearch();
    });
}());
</script>

<?php
include_once __DIR__ . '/footer.php';
?>