<?php
// Says which parts of the company brief are switched on, and why not. Never shows a key or any value.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/company_lib.php';
$cfg = dirname(__DIR__, 2) . '/.private/config.json';
$raw = is_file($cfg) ? (string)file_get_contents($cfg) : '';
json_decode($raw);
$valid = $raw !== '' && json_last_error() === JSON_ERROR_NONE;
echo json_encode([
  'ai' => co_secret('ANTHROPIC_API_KEY') !== '',
  'register' => co_secret('COMPANIES_HOUSE_KEY') !== '',
  'config_found' => $raw !== '',
  'config_reads_ok' => $valid,
  'config_problem' => $raw !== '' && !$valid ? json_last_error_msg() : '',
  'config_mentions_anthropic' => (bool)preg_match('/anthropic/i', $raw),
  'config_has_sk_ant' => (bool)preg_match('/sk-ant-/', $raw),
  'ha_secrets_file' => is_file(dirname(__DIR__, 3) . '/ha-secrets.php') || is_file(dirname(__DIR__, 2) . '/ha-secrets.php'),
  'keys_file_saved' => (function () { foreach ([dirname(__DIR__, 3), dirname(__DIR__, 2)] as $d) if (is_file("$d/ha-secrets.php")) return gmdate('H:i:s', filemtime("$d/ha-secrets.php")) . ' UTC'; return ''; })(),
  'keys_file_has_sk_ant' => (function () { foreach ([dirname(__DIR__, 3), dirname(__DIR__, 2)] as $d) if (is_file("$d/ha-secrets.php")) return (bool)preg_match('/sk-ant-/', (string)file_get_contents("$d/ha-secrets.php")); return false; })(),
  'updated' => $raw !== '' ? gmdate('H:i', filemtime($cfg)) . ' UTC' : '',
]);
