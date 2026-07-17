<?php
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Launching Khan Interactive</title>
</head>
<body>
<script>
(function () {
    var khanUrl = 'http://localhost:9061/';
    var w = window.open(khanUrl, 'khanInteractiveWindow', 'width=1400,height=900,resizable=yes,scrollbars=yes');

    if (w) {
        w.focus();
        window.location.replace('/');
    } else {
        alert('Popup was blocked. Please allow popups for this site.');
        window.location.replace(khanUrl);
    }
})();
</script>
<noscript>
    <p>JavaScript is required to launch Khan Interactive.</p>
    <p><a href="http://localhost:9061/" target="khanInteractiveWindow">Open Khan Interactive</a></p>
</noscript>
</body>
</html>