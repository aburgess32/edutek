<?php
/**
 * Student Registration — Signup Wizard (FRE-11)
 *
 * 4-step wizard:
 *   Step 1: Enter your name
 *   Step 2: Choose age range
 *   Step 3: Pick an avatar name
 *   Step 4: Meet your avatar (confirmation)
 *
 * Steps 1-3 are client-side (hidden divs toggled by JS).
 * Final POST processes registration server-side.
 */

declare(strict_types=1);

include_once __DIR__ . '/includes/auth.php';

// Already logged in? Go home
if (isLoggedIn()) {
    header('Location: /');
    exit;
}

$error = '';

// ── Handle POST (final registration) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token     = filter_input(INPUT_POST, '_csrf_token', FILTER_DEFAULT) ?? '';
    $name      = trim(filter_input(INPUT_POST, 'display_name', FILTER_DEFAULT) ?? '');
    $ageRange  = filter_input(INPUT_POST, 'age_range', FILTER_DEFAULT) ?? '';
    $avatarName = trim(filter_input(INPUT_POST, 'avatar_name', FILTER_DEFAULT) ?? '');

    if (!csrf_verify($token)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif (strlen($name) < 2) {
        $error = 'Name must be at least 2 characters.';
    } elseif (!in_array($ageRange, ['under_10', '10_14', '15_19', '20_plus'], true)) {
        $error = 'Please select a valid age range.';
    } elseif ($avatarName === '') {
        $error = 'Please choose an avatar name.';
    } else {
        $pdo = getDbConnection();

        // Verify avatar is still available (race condition check)
        $available = getAvailableAvatarNames($pdo);
        $availableNames = array_column($available, 'name');

        if (!in_array($avatarName, $availableNames, true)) {
            $error = 'That avatar name was just taken! Please pick another.';
        } else {
            try {
                $userId = createStudent($pdo, sanitize_name($name), $ageRange, $avatarName);
                $avatar = getAvatarByName($avatarName);
                loginUser($userId, $name, $avatarName, $avatar['color'] ?? '#333', 'student', $ageRange);
                updateLastActive($pdo, $userId);
                header('Location: /');
                exit;
            } catch (PDOException $e) {
                // UNIQUE constraint violation = avatar taken
                if ((int) $e->getCode() === 23000) {
                    $error = 'That avatar name was just taken! Please pick another.';
                } else {
                    $error = 'Something went wrong. Please try again.';
                }
            }
        }
    }
}

// ── Load available avatars for Step 3 ──
try {
    $pdo = getDbConnection();
    $availableAvatars = getAvailableAvatarNames($pdo);
} catch (Exception $e) {
    $availableAvatars = getAllAvatarNames(); // Fallback to full list
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Your Avatar - <?php echo SITE_TITLE; ?></title>
    <link href="vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/login.css" rel="stylesheet">
</head>
<body class="auth-page">

<div class="auth-container">
    <a href="login.php" class="auth-back"><i class="fa fa-arrow-left"></i> Back</a>

    <div class="auth-card">
        <!-- Progress dots -->
        <div class="wizard-progress">
            <span class="wizard-dot active" id="dot-1"></span>
            <span class="wizard-dot" id="dot-2"></span>
            <span class="wizard-dot" id="dot-3"></span>
            <span class="wizard-dot" id="dot-4"></span>
        </div>

        <?php if ($error): ?>
        <div class="auth-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" action="register.php" id="register-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="age_range" id="age_range" value="">
            <input type="hidden" name="avatar_name" id="avatar_name" value="">

            <!-- Step 1: Name -->
            <div class="wizard-step active" id="step-1">
                <h2 class="auth-title">What's your name?</h2>
                <p class="auth-subtitle">This is how your teacher will know you</p>
                <div class="auth-field">
                    <input type="text" name="display_name" id="display_name"
                           placeholder="Type your name..." maxlength="50" autocomplete="off"
                           required minlength="2">
                </div>
                <button type="button" class="auth-btn auth-btn--primary" id="btn-to-step2">
                    Next <i class="fa fa-arrow-right"></i>
                </button>
            </div>

            <!-- Step 2: Age Range -->
            <div class="wizard-step" id="step-2">
                <h2 class="auth-title">How old are you?</h2>
                <p class="auth-subtitle">This helps us show the right content</p>
                <div class="age-grid">
                    <div class="age-tile" data-age="under_10">
                        <span class="age-tile-label">Under 10</span>
                        <span class="age-tile-desc">Little learner</span>
                    </div>
                    <div class="age-tile" data-age="10_14">
                        <span class="age-tile-label">10 - 14</span>
                        <span class="age-tile-desc">Explorer</span>
                    </div>
                    <div class="age-tile" data-age="15_19">
                        <span class="age-tile-label">15 - 19</span>
                        <span class="age-tile-desc">Advanced</span>
                    </div>
                    <div class="age-tile" data-age="20_plus">
                        <span class="age-tile-label">20+</span>
                        <span class="age-tile-desc">Adult learner</span>
                    </div>
                </div>
            </div>

            <!-- Step 3: Avatar Picker -->
            <div class="wizard-step" id="step-3">
                <h2 class="auth-title">Pick your avatar name</h2>
                <p class="auth-subtitle">This is your unique identity on Edutek</p>
                <div class="avatar-grid">
                    <?php foreach ($availableAvatars as $av): ?>
                    <div class="avatar-chip"
                         data-name="<?php echo htmlspecialchars($av['name'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-color="<?php echo htmlspecialchars($av['color'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-meaning="<?php echo htmlspecialchars($av['meaning'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                         style="background: <?php echo htmlspecialchars($av['color'], ENT_QUOTES, 'UTF-8'); ?>;">
                        <?php echo htmlspecialchars($av['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Step 4: Meet Your Avatar -->
            <div class="wizard-step" id="step-4">
                <div class="avatar-reveal">
                    <div class="avatar-reveal-badge" id="reveal-badge"></div>
                    <h2 class="avatar-reveal-name" id="reveal-name"></h2>
                    <p class="avatar-reveal-meaning" id="reveal-meaning"></p>
                    <p class="auth-subtitle">Remember this name — it's yours!</p>
                    <button type="submit" class="auth-btn auth-btn--primary">
                        Start Learning <i class="fa fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var form = document.getElementById('register-form');
    var steps = document.querySelectorAll('.wizard-step');
    var dots  = document.querySelectorAll('.wizard-dot');
    var currentStep = 1;

    function showStep(n) {
        steps.forEach(function(s) { s.classList.remove('active'); });
        dots.forEach(function(d) { d.classList.remove('active'); });
        document.getElementById('step-' + n).classList.add('active');
        for (var i = 1; i <= n; i++) {
            document.getElementById('dot-' + i).classList.add('active');
        }
        currentStep = n;
    }

    // Step 1 → 2
    document.getElementById('btn-to-step2').addEventListener('click', function() {
        var name = document.getElementById('display_name').value.trim();
        if (name.length < 2) {
            alert('Please enter a name (at least 2 characters).');
            return;
        }
        showStep(2);
    });

    // Step 2: Age tiles
    document.querySelectorAll('.age-tile').forEach(function(tile) {
        tile.addEventListener('click', function() {
            document.querySelectorAll('.age-tile').forEach(function(t) { t.classList.remove('selected'); });
            tile.classList.add('selected');
            document.getElementById('age_range').value = tile.getAttribute('data-age');
            // Auto-advance after short delay
            setTimeout(function() { showStep(3); }, 300);
        });
    });

    // Step 3: Avatar chips
    document.querySelectorAll('.avatar-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            document.querySelectorAll('.avatar-chip').forEach(function(c) { c.classList.remove('selected'); });
            chip.classList.add('selected');

            var name    = chip.getAttribute('data-name');
            var color   = chip.getAttribute('data-color');
            var meaning = chip.getAttribute('data-meaning');

            document.getElementById('avatar_name').value = name;

            // Populate Step 4 reveal
            var badge = document.getElementById('reveal-badge');
            badge.textContent = name.charAt(0);
            badge.style.background = color;

            document.getElementById('reveal-name').textContent = name;
            document.getElementById('reveal-meaning').textContent = meaning ? '"' + meaning + '"' : '';

            setTimeout(function() { showStep(4); }, 300);
        });
    });
})();
</script>

</body>
</html>
