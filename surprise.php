<?php
// "Surprise Me" — picks one valid, aired, reasonably complete episode
// and redirects straight to it. No login, no state kept server-side.
require_once __DIR__ . '/includes/functions.php';

$n = getSurpriseEpisodeNumber();
header('Location: ' . ($n ? episodeUrl($n) : bp() . '/search.php'));
exit;
