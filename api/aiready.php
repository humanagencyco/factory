<?php
// Is a brand's website ready for AI assistants? Reads robots.txt, llms.txt and the home page.
// Returns only yes/no findings, never the page itself. Public websites only.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$d = strtolower(trim($_GET['domain'] ?? ''));
$d = preg_replace('#^https?://#', '', $d);
$d = preg_replace('#[/?\#].*$#', '', $d);
$d = preg_replace('/^www\./', '', $d);
if (!preg_match('/^(?=.{4,80}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $d)) { http_response_code(400); echo json_encode(['error' => 'Give a website like dove.com']); exit; }

// Never fetch private or local addresses
$ip = gethostbyname($d);
if ($ip === $d || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { http_response_code(400); echo json_encode(['error' => "Couldn't find that website."]); exit; }

// Cache for a day
$dir = sys_get_temp_dir() . '/ha-aiready';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
$key = $dir . '/' . sha1($d) . '.json';
if (is_file($key) && time() - filemtime($key) < 86400) { header('X-Cache: hit'); readfile($key); exit; }

function grab($url, $max = 600000) {
  $ctx = stream_context_create(['http' => ['timeout' => 12, 'follow_location' => 1, 'max_redirects' => 3, 'ignore_errors' => true,
    'header' => "User-Agent: Mozilla/5.0 (compatible; HumanAgencyCheck/1.0; +https://humanagency.co/factory/)\r\nAccept-Language: en-GB\r\n"],
    'ssl' => ['verify_peer' => true]]);
  $fp = @fopen($url, 'r', false, $ctx);
  if (!$fp) return [0, ''];
  $meta = stream_get_meta_data($fp); $code = 0;
  foreach (($meta['wrapper_data'] ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
  $body = ''; while (!feof($fp) && strlen($body) < $max) $body .= fread($fp, 8192);
  fclose($fp);
  return [$code, $body];
}

// robots.txt: which AI crawlers are blocked from the whole site
[$rc, $robots] = grab("https://$d/robots.txt", 200000);
$bots = ['GPTBot' => 'ChatGPT', 'OAI-SearchBot' => 'ChatGPT search', 'ClaudeBot' => 'Claude', 'PerplexityBot' => 'Perplexity', 'Google-Extended' => 'Google Gemini', 'CCBot' => 'Common Crawl'];
$groups = []; $cur = [];
foreach (preg_split('/\r?\n/', $rc >= 200 && $rc < 300 ? $robots : '') as $line) {
  $line = trim(preg_replace('/#.*/', '', $line)); if ($line === '') continue;
  if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) { if (!empty($cur['rules'])) { $groups[] = $cur; $cur = []; } $cur['agents'][] = strtolower(trim($m[1])); $cur['rules'] = $cur['rules'] ?? []; }
  elseif (preg_match('/^(dis)?allow:\s*(.*)$/i', $line, $m)) { $cur['rules'][] = [strtolower($m[1]) === 'dis' ? 'd' : 'a', trim($m[2])]; }
}
if (!empty($cur)) $groups[] = $cur;
function blocked($groups, $agent) {
  $use = null;
  foreach ($groups as $g) if (in_array(strtolower($agent), $g['agents'] ?? [])) $use = $g;
  if (!$use) foreach ($groups as $g) if (in_array('*', $g['agents'] ?? [])) $use = $g;
  if (!$use) return false;
  $dis = false;
  foreach ($use['rules'] as [$t, $p]) { if ($p === '/' && $t === 'd') $dis = true; if ($p === '/' && $t === 'a') return false; }
  return $dis;
}
$ai = [];
foreach ($bots as $ua => $label) $ai[] = ['bot' => $ua, 'name' => $label, 'blocked' => blocked($groups, $ua)];

// llms.txt
[$lc, $llms] = grab("https://$d/llms.txt", 20000);
$hasLlms = $lc >= 200 && $lc < 300 && stripos($llms, '<html') === false && strlen(trim($llms)) > 20;

// Home page: secure, title, description and structured data
[$hc, $html] = grab("https://$d/");
$types = [];
if (preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $mm)) {
  foreach ($mm[1] as $j) {
    $j = json_decode(html_entity_decode(trim($j)), true); if (!$j) continue;
    $walk = function ($x) use (&$walk, &$types) { if (is_array($x)) { if (isset($x['@type'])) foreach ((array)$x['@type'] as $t) $types[] = (string)$t; foreach ($x as $v) if (is_array($v)) $walk($v); } };
    $walk($j);
  }
}
$types = array_values(array_unique($types));
preg_match('#<title[^>]*>(.*?)</title>#is', $html, $t);
preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)#i', $html, $desc);

$out = [
  'domain' => $d,
  'opens' => $hc >= 200 && $hc < 400,
  'robots_found' => $rc >= 200 && $rc < 300,
  'ai_bots' => $ai,
  'blocked_count' => count(array_filter($ai, fn($b) => $b['blocked'])),
  'llms_txt' => $hasLlms,
  'schema_types' => array_slice($types, 0, 12),
  'has_org_schema' => (bool)array_intersect($types, ['Organization', 'Corporation', 'Brand', 'LocalBusiness', 'Store', 'OnlineStore', 'WebSite']),
  'has_product_schema' => (bool)array_intersect($types, ['Product', 'ProductGroup', 'Offer', 'AggregateOffer', 'ItemList']),
  'title' => mb_substr(trim(html_entity_decode(strip_tags($t[1] ?? ''))), 0, 120),
  'description' => mb_substr(trim(html_entity_decode($desc[1] ?? '')), 0, 220),
  'checked' => gmdate('c'),
];
$body = json_encode($out);
if ($out['opens']) @file_put_contents($key, $body);
header('X-Cache: miss');
echo $body;
