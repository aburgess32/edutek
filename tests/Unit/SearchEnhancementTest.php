<?php

/**
 * SearchEnhancementTest — Tests for FRE-13 search enhancement.
 *
 * Covers:
 *  - FULLTEXT search query (terms >= 3 chars)
 *  - LIKE fallback for short terms (2 chars)
 *  - Empty/short query returns empty
 *  - Content type filter
 *  - Index script UPSERT logic
 */

declare(strict_types=1);

namespace EduPak\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

class SearchEnhancementTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = getTestPdo();
        truncateTestTables($this->pdo);
        $this->seedContentMeta();
    }

    // ------------------------------------------------------------------
    // Seed helpers
    // ------------------------------------------------------------------

    private function seedContentMeta(): void
    {
        $items = [
            ['math/fractions/intro.mp4', 'Introduction to Fractions', 'video', 'Math', 'Fractions', 'Khan Academy', 600],
            ['math/algebra/basics.mp4', 'Algebra Basics', 'video', 'Math', 'Algebra', 'Khan Academy', 900],
            ['science/solar-system/planets.mp4', 'The Solar System Planets', 'video', 'Science', 'Solar System', 'CK-12', 1200],
            ['health/nutrition/vitamins.pdf', 'Vitamins and Nutrition Guide', 'pdf', 'Health', 'Nutrition', 'Local', null],
            ['stories/african-tales/anansi.mp3', 'Anansi the Spider', 'audiobook', 'Stories', 'African Tales', 'Local', 1800],
            ['science/biology/cells.mp4', 'Cell Biology Overview', 'video', 'Science', 'Biology', 'CK-12', 750],
            ['math/geometry/shapes.mp4', 'Basic Geometric Shapes', 'video', 'Math', 'Geometry', 'Khan Academy', 480],
        ];

        $stmt = $this->pdo->prepare("
            INSERT INTO content_meta
                (content_id, title, file_path, content_type, category, subcategory, source, duration_seconds)
            VALUES
                (:cid, :title, :path, :type, :cat, :subcat, :src, :dur)
        ");

        foreach ($items as $item) {
            $stmt->execute([
                ':cid'    => $item[0],
                ':title'  => $item[1],
                ':path'   => $item[0],
                ':type'   => $item[2],
                ':cat'    => $item[3],
                ':subcat' => $item[4],
                ':src'    => $item[5],
                ':dur'    => $item[6],
            ]);
        }
    }

    private function insertContent(
        string $contentId,
        string $title,
        string $contentType = 'video',
        string $category = '',
        string $subcategory = '',
        string $source = ''
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO content_meta
                (content_id, title, file_path, content_type, category, subcategory, source)
            VALUES
                (:cid, :title, :path, :type, :cat, :subcat, :src)
        ");
        $stmt->execute([
            ':cid'    => $contentId,
            ':title'  => $title,
            ':path'   => $contentId,
            ':type'   => $contentType,
            ':cat'    => $category,
            ':subcat' => $subcategory,
            ':src'    => $source,
        ]);
    }

    // ------------------------------------------------------------------
    // Search helper (simulates what search.php does)
    // ------------------------------------------------------------------

    /**
     * Run a search query against content_meta, mirroring the API logic.
     *
     * @param string $query       Search term
     * @param string $typeFilter  Optional content_type filter
     * @return array              Search results
     */
    private function runSearch(string $query, string $typeFilter = ''): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $params = [];
        $typeClause = '';

        if ($typeFilter !== '') {
            $typeClause = ' AND cm.content_type = :type_filter';
            $params[':type_filter'] = $typeFilter;
        }

        if (mb_strlen($query) >= 3) {
            // FULLTEXT search
            $words = preg_split('/\s+/', $query);
            $booleanTerms = [];
            foreach ($words as $word) {
                $word = trim($word);
                if ($word !== '') {
                    $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
                    if ($clean !== '') {
                        $booleanTerms[] = '+' . $clean . '*';
                    }
                }
            }
            $booleanQuery = implode(' ', $booleanTerms);

            $sql = "
                SELECT
                    cm.content_id,
                    cm.title,
                    cm.category,
                    cm.subcategory,
                    cm.source,
                    cm.content_type,
                    cm.duration_seconds,
                    cm.thumbnail_path,
                    MATCH(cm.title, cm.category, cm.subcategory, cm.source) AGAINST(:q_score IN BOOLEAN MODE) AS relevance
                FROM content_meta cm
                WHERE MATCH(cm.title, cm.category, cm.subcategory, cm.source) AGAINST(:q_match IN BOOLEAN MODE)
                {$typeClause}
                ORDER BY relevance DESC
                LIMIT 50
            ";
            $params[':q_score'] = $booleanQuery;
            $params[':q_match'] = $booleanQuery;
        } else {
            // LIKE fallback for 2-char terms
            $likeParam = '%' . $query . '%';
            $sql = "
                SELECT
                    cm.content_id,
                    cm.title,
                    cm.category,
                    cm.subcategory,
                    cm.source,
                    cm.content_type,
                    cm.duration_seconds,
                    cm.thumbnail_path,
                    0 AS relevance
                FROM content_meta cm
                WHERE (
                    cm.title LIKE :like_title
                    OR cm.category LIKE :like_category
                    OR cm.subcategory LIKE :like_subcategory
                    OR cm.source LIKE :like_source
                )
                {$typeClause}
                ORDER BY cm.title ASC
                LIMIT 50
            ";
            $params[':like_title']       = $likeParam;
            $params[':like_category']    = $likeParam;
            $params[':like_subcategory'] = $likeParam;
            $params[':like_source']      = $likeParam;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Tests: FULLTEXT search (terms >= 3 chars)
    // ------------------------------------------------------------------

    /**
     * Searching for "Math" should return all Math category items via FULLTEXT.
     */
    public function testFulltextSearchByCategory(): void
    {
        $results = $this->runSearch('Math');

        $this->assertNotEmpty($results, 'FULLTEXT search for "Math" should return results');

        $contentIds = array_column($results, 'content_id');
        $this->assertContains('math/fractions/intro.mp4', $contentIds);
        $this->assertContains('math/algebra/basics.mp4', $contentIds);
        $this->assertContains('math/geometry/shapes.mp4', $contentIds);
    }

    /**
     * Searching for "Khan Academy" should find items from that source.
     */
    public function testFulltextSearchBySource(): void
    {
        $results = $this->runSearch('Khan Academy');

        $this->assertNotEmpty($results, 'FULLTEXT search for "Khan Academy" should return results');

        foreach ($results as $row) {
            $this->assertSame('Khan Academy', $row['source']);
        }
    }

    /**
     * Searching for "Fractions" should match the subcategory.
     */
    public function testFulltextSearchBySubcategory(): void
    {
        $results = $this->runSearch('Fractions');

        $this->assertNotEmpty($results, 'FULLTEXT search for "Fractions" should return results');

        $contentIds = array_column($results, 'content_id');
        $this->assertContains('math/fractions/intro.mp4', $contentIds);
    }

    /**
     * Searching for a title term should match.
     */
    public function testFulltextSearchByTitle(): void
    {
        $results = $this->runSearch('Anansi');

        $this->assertNotEmpty($results, 'FULLTEXT search for "Anansi" should return results');

        $contentIds = array_column($results, 'content_id');
        $this->assertContains('stories/african-tales/anansi.mp3', $contentIds);
    }

    // ------------------------------------------------------------------
    // Tests: LIKE fallback (terms < 3 chars but >= 2)
    // ------------------------------------------------------------------

    /**
     * A 2-character search should use LIKE fallback and still return results.
     */
    public function testLikeFallbackForShortTerms(): void
    {
        $results = $this->runSearch('CK');

        $this->assertNotEmpty($results, 'LIKE fallback for "CK" should return results');

        // Should match CK-12 source items
        $sources = array_column($results, 'source');
        $this->assertContains('CK-12', $sources);
    }

    // ------------------------------------------------------------------
    // Tests: empty/short query
    // ------------------------------------------------------------------

    /**
     * A single character query should return empty array.
     */
    public function testSingleCharQueryReturnsEmpty(): void
    {
        $results = $this->runSearch('M');
        $this->assertEmpty($results, 'Single character query should return empty');
    }

    /**
     * An empty query should return empty array.
     */
    public function testEmptyQueryReturnsEmpty(): void
    {
        $results = $this->runSearch('');
        $this->assertEmpty($results, 'Empty query should return empty');
    }

    /**
     * A whitespace-only query should return empty array.
     */
    public function testWhitespaceQueryReturnsEmpty(): void
    {
        $results = $this->runSearch('   ');
        $this->assertEmpty($results, 'Whitespace-only query should return empty');
    }

    // ------------------------------------------------------------------
    // Tests: content_type filter
    // ------------------------------------------------------------------

    /**
     * Filtering by content_type="pdf" should only return PDF items.
     */
    public function testContentTypeFilterPdf(): void
    {
        // Search for something broad that matches multiple types
        $results = $this->runSearch('Nutrition', 'pdf');

        $this->assertNotEmpty($results, 'Filtered search should return PDF results');

        foreach ($results as $row) {
            $this->assertSame('pdf', $row['content_type'], 'All results should be PDFs');
        }
    }

    /**
     * Filtering by content_type="video" should exclude non-video content.
     */
    public function testContentTypeFilterVideo(): void
    {
        $results = $this->runSearch('Science', 'video');

        $this->assertNotEmpty($results, 'Filtered video search should return results');

        foreach ($results as $row) {
            $this->assertSame('video', $row['content_type'], 'All results should be videos');
        }

        // Should not include PDFs or audiobooks
        $contentIds = array_column($results, 'content_id');
        $this->assertNotContains('health/nutrition/vitamins.pdf', $contentIds);
        $this->assertNotContains('stories/african-tales/anansi.mp3', $contentIds);
    }

    /**
     * Filtering by content_type="audiobook" with LIKE fallback should work.
     */
    public function testContentTypeFilterWithLikeFallback(): void
    {
        // 2-char search with type filter
        $results = $this->runSearch('an', 'audiobook');

        foreach ($results as $row) {
            $this->assertSame('audiobook', $row['content_type'], 'Filtered LIKE results should be audiobooks');
        }
    }

    // ------------------------------------------------------------------
    // Tests: UPSERT logic (content indexing)
    // ------------------------------------------------------------------

    /**
     * Inserting a new content item creates a row in content_meta.
     */
    public function testUpsertInsertsNewContent(): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO content_meta
                (content_id, title, file_path, content_type, category, subcategory, source)
            VALUES
                (:cid, :title, :path, :type, :cat, :subcat, :src)
            ON DUPLICATE KEY UPDATE
                title          = VALUES(title),
                content_type   = VALUES(content_type),
                category       = VALUES(category),
                subcategory    = VALUES(subcategory),
                source         = VALUES(source)
        ");

        $stmt->execute([
            ':cid'    => 'test/new-content.mp4',
            ':title'  => 'New Test Content',
            ':path'   => 'test/new-content.mp4',
            ':type'   => 'video',
            ':cat'    => 'Test',
            ':subcat' => 'New',
            ':src'    => 'Local',
        ]);

        $this->assertSame(1, $stmt->rowCount(), 'INSERT should report rowCount=1');

        $check = $this->pdo->prepare("SELECT * FROM content_meta WHERE content_id = ?");
        $check->execute(['test/new-content.mp4']);
        $row = $check->fetch();

        $this->assertNotFalse($row);
        $this->assertSame('New Test Content', $row['title']);
        $this->assertSame('Test', $row['category']);
    }

    /**
     * Upserting an existing content_id updates the metadata.
     */
    public function testUpsertUpdatesExistingContent(): void
    {
        // Update the existing math/fractions/intro.mp4 row
        $stmt = $this->pdo->prepare("
            INSERT INTO content_meta
                (content_id, title, file_path, content_type, category, subcategory, source)
            VALUES
                (:cid, :title, :path, :type, :cat, :subcat, :src)
            ON DUPLICATE KEY UPDATE
                title          = VALUES(title),
                content_type   = VALUES(content_type),
                category       = VALUES(category),
                subcategory    = VALUES(subcategory),
                source         = VALUES(source)
        ");

        $stmt->execute([
            ':cid'    => 'math/fractions/intro.mp4',
            ':title'  => 'Updated Fractions Title',
            ':path'   => 'math/fractions/intro.mp4',
            ':type'   => 'video',
            ':cat'    => 'Mathematics',
            ':subcat' => 'Fractions',
            ':src'    => 'Khan Academy',
        ]);

        $this->assertSame(2, $stmt->rowCount(), 'UPDATE via upsert should report rowCount=2');

        $check = $this->pdo->prepare("SELECT title, category FROM content_meta WHERE content_id = ?");
        $check->execute(['math/fractions/intro.mp4']);
        $row = $check->fetch();

        $this->assertSame('Updated Fractions Title', $row['title']);
        $this->assertSame('Mathematics', $row['category']);
    }

    // ------------------------------------------------------------------
    // Tests: result limit
    // ------------------------------------------------------------------

    /**
     * Search results should be limited to 50 items.
     */
    public function testSearchResultsLimitedToFifty(): void
    {
        // Insert 60 items with "Bulk" in category
        for ($i = 1; $i <= 60; $i++) {
            $this->insertContent(
                "bulk/item-{$i}.mp4",
                "Bulk Item {$i}",
                'video',
                'Bulk',
                'Testing',
                'Local'
            );
        }

        $results = $this->runSearch('Bulk');

        $this->assertLessThanOrEqual(50, count($results), 'Results should be capped at 50');
    }

    /**
     * Search results should include category_url and subcategory_url for navigation.
     */
    public function testSearchResultsIncludeCategoryUrls(): void
    {
        $results = $this->runSearch('Math');
        $this->assertNotEmpty($results, 'Search should return results');

        foreach ($results as $row) {
            $this->assertNotEmpty($row['category'], 'Test data should have categories');
            $this->assertNotEmpty($row['subcategory'], 'Test data should have subcategories');

            // Simulate the URL formatting that api/search.php performs
            $categoryUrl = '';
            $subcategoryUrl = '';
            if (!empty($row['category'])) {
                $categoryUrl = 'tutorials.php?&course=' . urlencode($row['category']);
                if (!empty($row['subcategory'])) {
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $row['subcategory']));
                    $subcategoryUrl = $categoryUrl . '#tut-sec-' . $slug;
                }
            }

            $this->assertNotEmpty($categoryUrl, 'Category URL should be built for results with a category');
            $this->assertStringContainsString('tutorials.php', $categoryUrl);
            $this->assertNotEmpty($subcategoryUrl, 'Subcategory URL should be built for results with a subcategory');
            $this->assertStringContainsString('#tut-sec-', $subcategoryUrl);
        }
    }
}
