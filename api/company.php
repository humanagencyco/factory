<?php
// Company brief, in three parts the page loads side by side:
//   part=register  the UK register (Companies House)
//   part=jobs      their website, careers page and open roles, read from the hiring system they use
//   part=news      recent UK news (Google News)
// Public company information only. Optional secrets live in ha-secrets.php above the web folder,
// never in this repo: COMPANIES_HOUSE_KEY, ANTHROPIC_API_KEY (see company_lib.php).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/company_lib.php';

$part = $_GET['part'] ?? '';
$name = co_clean_name($_GET['name'] ?? '');
$domain = co_clean_domain($_GET['domain'] ?? '');
if (!in_array($part, ['register', 'jobs', 'news'], true)) { http_response_code(400); echo json_encode(['error' => 'Unknown part.']); exit; }
if ($name === '' && $domain === '') { http_response_code(400); echo json_encode(['error' => 'Type a company name, like Monzo or Tesco.']); exit; }
if ($name === '') $name = ucfirst(explode('.', $domain)[0]);

$key = co_cache_key($name, $domain) . '-' . $part;
if (empty($_GET['fresh']) && ($hit = co_cache_get($key, 86400))) { header('X-Cache: hit'); echo $hit; exit; }
if (!co_rate_ok($part, 40, 3600)) { http_response_code(429); echo json_encode(['error' => 'Lots of lookups from you just now. Try again in a little while.']); exit; }

if ($part === 'register') {
  $out = co_register($name);
} elseif ($part === 'news') {
  $out = co_news($name);
} else {
  $site = $domain ? co_site($domain) : null;
  // No website given: try the obvious addresses, and say it was a guess
  if (!$domain) {
    $slug = preg_replace('/[^a-z0-9]/', '', strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', str_replace('&', 'and', $name)) ?: $name));
    if (strlen($slug) >= 3) foreach ([$slug . '.co.uk', $slug . '.com'] as $g) {
      $try = co_site($g);
      if (!empty($try['opens']) && co_names_match($try['title'] . ' ' . $try['description'], $name)) { $site = $try; $site['guessed'] = true; break; }
    }
  }
  $out = [
    'site' => $site ? array_diff_key($site, ['html' => 1, 'careers_html' => 1]) : null,
    'jobs' => co_jobs($name, $site['domain'] ?? $domain, $site),
  ];
}
$out['checked'] = gmdate('c');
$body = json_encode($out);
co_cache_put($key, $body);
header('X-Cache: miss');
echo $body;
