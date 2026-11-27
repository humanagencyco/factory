<?php
// One-off: back up and replace delivery.html in the site root. Removed right after use.
header('Content-Type: text/plain');
if (!hash_equals('ef14c64178d127390f5ef98231b96315', $_GET['t'] ?? '')) { http_response_code(404); exit; }
$root = realpath(__DIR__ . '/../..');
$bak = $root . '/delivery-2026-11-27-before-wayfinding.html';
if (!is_file($bak) && !copy($root . '/delivery.html', $bak)) { http_response_code(500); exit("Backup failed, not replacing.\n"); }
$ok = copy(__DIR__ . '/delivery-release.html', $root . '/delivery.html');
echo $ok ? "Backed up and replaced delivery.html (" . filesize($root . '/delivery.html') . " bytes)\n" : "Copy failed\n";
