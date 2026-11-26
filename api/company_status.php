<?php
// Says which parts of the company brief are switched on. Never shows a key.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/company_lib.php';
echo json_encode(['ai' => co_secret('ANTHROPIC_API_KEY') !== '', 'register' => co_secret('COMPANIES_HOUSE_KEY') !== '', 'private_config' => is_file(dirname(__DIR__, 2) . '/.private/config.json')]);
