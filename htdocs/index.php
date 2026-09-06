<?php
include_once "includes/auth.php";
include_once "includes/tiles.php";
?>
<link href="css/index.css" rel="stylesheet">
<link href="css/login.css" rel="stylesheet">
<link href="css/teacher-assignments.css" rel="stylesheet">
<link href="css/student-assignments.css" rel="stylesheet">

<?php
include_once "navhome.php";
?>

<main class="home-page" id="main-content">
    <section class="home-hero" aria-labelledby="home-title">
        <div class="home-hero__content">
            <p class="home-hero__eyebrow">EduTek Global</p>
            <h1 class="home-hero__title" id="home-title">Explore Learning Resources</h1>
            <p class="home-hero__subtitle">Learn, teach, and explore — even without internet.</p>

            <form class="home-search" method="get" action="result.php" role="search">
                <label class="sr-only" for="home-search-query">Search the learning library</label>
                <input
                    id="home-search-query"
                    class="home-search__input"
                    type="search"
                    name="q"
                    placeholder="Search videos, books, skills, or subjects"
                    autocomplete="off"
                >
                <button class="home-search__button" type="submit">
                    <i class="fa fa-search" aria-hidden="true"></i>
                    <span>Search</span>
                </button>
            </form>
        </div>
    </section>

    <section class="resource-types" aria-labelledby="resource-types-title">
        <div class="home-section-heading">
            <div>
                <p class="home-section-heading__eyebrow">Start here</p>
                <h2 id="resource-types-title">Choose a Content Type</h2>
                <p>Choose the kind of resource that works best for you today.</p>
            </div>
        </div>

        <div class="resource-types__grid">
            <a class="resource-card resource-card--video" href="directory.php" aria-label="Browse video learning resources">
                <span class="resource-card__icon" aria-hidden="true">
                    <i class="fa fa-play-circle"></i>
                </span>
                <span class="resource-card__content">
                    <span class="resource-card__title">Watch Videos</span>
                    <span class="resource-card__description">Lessons, demonstrations, and documentaries.</span>
                </span>
                <span class="resource-card__arrow" aria-hidden="true">&rarr;</span>
            </a>

            <a class="resource-card resource-card--audio" href="audiobooks.php" aria-label="Browse audiobooks">
                <span class="resource-card__icon" aria-hidden="true">
                    <i class="fa fa-headphones"></i>
                </span>
                <span class="resource-card__content">
                    <span class="resource-card__title">Audiobooks</span>
                    <span class="resource-card__description">Listen to stories, learning, and ideas.</span>
                </span>
                <span class="resource-card__arrow" aria-hidden="true">&rarr;</span>
            </a>

            <a class="resource-card resource-card--books" href="books.php" aria-label="Browse books and PDF resources">
                <span class="resource-card__icon" aria-hidden="true">
                    <i class="fa fa-book"></i>
                </span>
                <span class="resource-card__content">
                    <span class="resource-card__title">Books &amp; PDFs</span>
                    <span class="resource-card__description">Read guides, textbooks, stories, and reference materials.</span>
                </span>
                <span class="resource-card__arrow" aria-hidden="true">&rarr;</span>
            </a>

            <a class="resource-card resource-card--music" href="music.php" aria-label="Browse music">
                <span class="resource-card__icon" aria-hidden="true">
                    <i class="fa fa-music"></i>
                </span>
                <span class="resource-card__content">
                    <span class="resource-card__title">Music</span>
                    <span class="resource-card__description">Explore songs, playlists, and audio collections.</span>
                </span>
                <span class="resource-card__arrow" aria-hidden="true">&rarr;</span>
            </a>

            <a class="resource-card resource-card--tools" href="tools.php" aria-label="Open learning tools">
                <span class="resource-card__icon" aria-hidden="true">
                    <i class="fa fa-wrench"></i>
                </span>
                <span class="resource-card__content">
                    <span class="resource-card__title">Learning Tools</span>
                    <span class="resource-card__description">Use offline apps, interactive learning, and reference tools.</span>
                </span>
                <span class="resource-card__arrow" aria-hidden="true">&rarr;</span>
            </a>
        </div>
    </section>

    <section class="home-next-steps" aria-labelledby="home-next-steps-title">
        <div class="home-next-steps__copy">
            <p class="home-section-heading__eyebrow">Explore more</p>
            <h2 id="home-next-steps-title">Find the right resource</h2>
            <p>Browse every available collection or use search to find a specific topic, subject, title, or skill.</p>
        </div>

        <div class="home-next-steps__actions">
            <a class="home-button home-button--primary" href="directory.php">
                <i class="fa fa-list" aria-hidden="true"></i>
                Browse All Topics
            </a>
            <a class="home-button home-button--secondary" href="result.php">
                <i class="fa fa-search" aria-hidden="true"></i>
                Search the Library
            </a>
        </div>
    </section>
</main>

<?php
require_once "includes/breadcrumb.php";
renderBreadcrumb([]);
include_once "footer.php";
?>