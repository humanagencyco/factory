<?php
// Is a brand's website ready for AI assistants? Reads robots.txt, llms.txt and the home page.
// Returns only yes/no findings, never the page itself. Public websites only.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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
$key = $dir . '/' . sha1('v4' . $d) . '.json';
if (empty($_GET['fresh']) && is_file($key) && time() - filemtime($key) < 86400) { header('X-Cache: hit'); header('Cache-Control: public, max-age=3600'); readfile($key); exit; }

function grab($url, $max = 600000) {
  // Look like a normal browser: many big brand sites turn away anything that doesn't
  $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    $buf = '';
    curl_setopt_array($ch, [
      CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
      CURLOPT_USERAGENT => $ua, CURLOPT_ENCODING => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
      CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
      CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.8', 'Accept-Language: en-GB,en;q=0.9'],
      CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$buf, $max) { $buf .= $chunk; return strlen($buf) > $max ? 0 : strlen($chunk); },
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $buf];
  }
  $ctx = stream_context_create(['http' => ['timeout' => 12, 'follow_location' => 1, 'max_redirects' => 4, 'ignore_errors' => true,
    'header' => "User-Agent: $ua\r\nAccept-Language: en-GB\r\n"]]);
  $fp = @fopen($url, 'r', false, $ctx);
  if (!$fp) return [0, ''];
  $meta = stream_get_meta_data($fp); $code = 0;
  foreach (($meta['wrapper_data'] ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
  $body = ''; while (!feof($fp) && strlen($body) < $max) $body .= fread($fp, 8192);
  fclose($fp);
  return [$code, $body];
}

// When a site blocks our server, use the Internet Archive's latest public copy instead
$fromArchive = false;
function grabArchived($url, $max = 600000) {
  [$c, $b] = grab('https://web.archive.org/web/2id_/' . $url, $max);
  return [$c, $b];
}

// robots.txt: which AI crawlers are blocked from the whole site
$base = $d;
[$rc, $robots] = grab("https://$d/robots.txt", 200000);
if (!($rc >= 200 && $rc < 300)) { [$rc2, $r2] = grab("https://www.$d/robots.txt", 200000); if ($rc2 >= 200 && $rc2 < 300) { $base = "www.$d"; $rc = $rc2; $robots = $r2; } }
if (!($rc >= 200 && $rc < 300) || stripos($robots, '<html') !== false) {
  foreach (["https://www.$d/robots.txt", "https://$d/robots.txt"] as $u) {
    [$ac, $ab] = grabArchived($u, 200000);
    if ($ac >= 200 && $ac < 300 && stripos($ab, '<html') === false && stripos($ab, 'user-agent') !== false) { $rc = $ac; $robots = $ab; $fromArchive = true; $base = parse_url($u, PHP_URL_HOST); break; }
  }
}
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
[$lc, $llms] = grab("https://$base/llms.txt", 20000);
$hasLlms = $lc >= 200 && $lc < 300 && stripos($llms, '<html') === false && strlen(trim($llms)) > 20;

// Home page: secure, title, description and structured data
$t0 = microtime(true);
[$hc, $html] = grab("https://$base/");
$ms = (int)round((microtime(true) - $t0) * 1000);
if (!($hc >= 200 && $hc < 400) && $base === $d) [$hc, $html] = grab("https://www.$d/");
if (!($hc >= 200 && $hc < 400)) { [$pc2, $ph] = grab("http://$d/"); if ($pc2 >= 200 && $pc2 < 400) { $hc = $pc2; $html = $ph; } }
if (!($hc >= 200 && $hc < 400) || strlen($html) < 2000) {
  foreach (["https://www.$d/", "https://$d/"] as $u) { [$ac, $ab] = grabArchived($u); if ($ac >= 200 && $ac < 300 && strlen($ab) > 2000) { $hc = $ac; $html = $ab; $fromArchive = true; break; } }
}
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

// Secure connection: does the padlock show, or do browsers warn "Not secure"?
function sslCheck($host) {
  if (!function_exists('curl_init')) return null;
  $ch = curl_init("https://$host/");
  curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36']);
  curl_exec($ch); $no = curl_errno($ch); $err = curl_error($ch); curl_close($ch);
  if (!$no) return ['ok' => true, 'problem' => ''];
  $p = 'other';
  if (preg_match('/expired/i', $err)) $p = 'expired';
  elseif (preg_match('/subject|name|match|SAN/i', $err)) $p = 'wrong_name';
  elseif (preg_match('/self.signed|issuer|local issuer|unable to get/i', $err)) $p = 'untrusted';
  elseif (in_array($no, [7, 28, 35])) $p = 'no_https';
  return ['ok' => false, 'problem' => $p];
}
$ssl = $fromArchive ? null : sslCheck($d);
$sslWww = $fromArchive ? null : sslCheck("www.$d");
if ($ssl && !$ssl['ok'] && $sslWww && $sslWww['ok']) $ssl = ['ok' => false, 'problem' => 'only_www'];

// Details for a ready-to-paste business card for AI (only what the page itself says)
preg_match('#href=["\']tel:([^"\']+)#i', $html, $tel);
preg_match('#<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']*)#i', $html, $sn);
preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']*)#i', $html, $oi);
preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $h1t);

// Basics a person or a search engine notices first
$sitemap = (bool)preg_match('/^\s*sitemap:\s*\S+/im', $robots ?? '');
if (!$sitemap && !$fromArchive) { [$sc, $sb] = grab("https://$base/sitemap.xml", 4000); $sitemap = $sc >= 200 && $sc < 300 && stripos($sb, '<urlset') !== false || stripos($sb ?? '', '<sitemapindex') !== false; }
$basics = [
  'mobile' => (bool)preg_match('#<meta[^>]+name=["\']viewport["\']#i', $html),
  'h1' => (bool)preg_match('#<h1[\s>]#i', $html),
  'social_preview' => (bool)preg_match('#<meta[^>]+property=["\']og:(title|image)["\']#i', $html),
  'phone_link' => (bool)preg_match('#href=["\']tel:#i', $html),
  'sitemap' => $sitemap,
  'local_business' => (bool)array_intersect($types, ['LocalBusiness', 'Restaurant', 'Store', 'ProfessionalService', 'HomeAndConstructionBusiness', 'FoodEstablishment', 'HealthAndBeautyBusiness', 'AutomotiveBusiness', 'LegalService', 'MedicalBusiness']),
  'load_ms' => $fromArchive ? null : $ms,
  'page_kb' => $fromArchive ? null : (int)round(strlen($html) / 1024),
];

$out = [
  'basics' => $basics,
  'ssl' => $ssl,
  'phone' => isset($tel[1]) ? mb_substr(trim(urldecode($tel[1])), 0, 30) : '',
  'site_name' => mb_substr(trim(html_entity_decode($sn[1] ?? '')), 0, 80),
  'image' => mb_substr(trim($oi[1] ?? ''), 0, 300),
  'h1_text' => mb_substr(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($h1t[1] ?? '')))), 0, 120),
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
  'from_archive' => $fromArchive,
];
$body = json_encode($out);
if ($out['opens']) { @file_put_contents($key, $body); header('Cache-Control: public, max-age=3600'); }
header('X-Cache: miss');
echo $body;
