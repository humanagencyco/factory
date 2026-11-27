<?php
// One-off: copy the approved homepage into the site root. Removed right after use.
header('Content-Type: text/plain');
if (!hash_equals('0ba3ad6532a6c9a012d6702abf121339', $_GET['t'] ?? '')) { http_response_code(404); exit; }
$root = realpath(__DIR__ . '/../..');
$src = __DIR__ . '/home-release.html';
if (!is_file($root . '/index-2026-11-27-before-wayfinding.html')) { http_response_code(409); exit("No backup found, not replacing.\n"); }
$ok = copy($src, $root . '/index.html');
echo $ok ? "Replaced index.html (" . filesize($root . '/index.html') . " bytes)\n" : "Copy failed\n";
