<?php
// Shared helpers for company.php and company_ai.php. Does nothing when opened directly.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

function co_secret($k) {
  static $s = null;
  if ($s === null) {
    $s = [];
    foreach ([dirname(__DIR__, 3) . '/ha-secrets.php', dirname(__DIR__, 2) . '/ha-secrets.php'] as $f) {
      if (is_file($f)) { $v = include $f; if (is_array($v)) { $s = $v; break; } }
    }
  }
  $e = getenv($k);
  if ($e !== false && $e !== '') return $e;
  if (!empty($s[$k])) return $s[$k];
  return co_private_config($k);
}

// Keys already kept for the site in public_html/.private/config.json (that folder is closed to the web).
// Read on the server only; the value never goes to the browser.
function co_private_config($k) {
  static $flat = null;
  if ($flat === null) {
    $flat = [];
    $f = dirname(__DIR__, 2) . '/.private/config.json';
    $c = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    $walk = function ($x, $path) use (&$walk, &$flat) {
      if (is_array($x)) { foreach ($x as $kk => $v) $walk($v, $path === '' ? (string)$kk : "$path.$kk"); }
      elseif (is_string($x) && $x !== '') $flat[strtolower($path)] = $x;
    };
    if (is_array($c)) $walk($c, '');
    foreach ([dirname(__DIR__, 2) . '/.private/.env', dirname(__DIR__, 3) . '/.env'] as $envf) {
      if (is_file($envf)) foreach (file($envf, FILE_IGNORE_NEW_LINES) as $line) if (preg_match('/^\s*([A-Za-z0-9_.]+)\s*=\s*"?([^"#]+?)"?\s*$/', $line, $m)) $flat[strtolower($m[1])] = $m[2];
    }
  }
  $want = [
    'ANTHROPIC_API_KEY' => fn($p) => str_contains($p, 'anthropic') || str_contains($p, 'claude'),
    'COMPANIES_HOUSE_KEY' => fn($p) => str_contains(str_replace(['_', '-', '.', ' '], '', $p), 'companieshouse'),
  ][$k] ?? null;
  if (!$want) return '';
  foreach ($flat as $p => $v) if ($want($p) && preg_match('/key|token|secret/', $p) && !preg_match('/model|url|version/', $p)) return $v;
  // Anthropic keys look the same wherever they're kept, so take one under any name
  if ($k === 'ANTHROPIC_API_KEY') foreach ($flat as $v) if (preg_match('/^sk-ant-[A-Za-z0-9_-]{20,}$/', trim($v))) return trim($v);
  return '';
}

function co_clean_name($n) {
  $n = trim(preg_replace('/\s+/', ' ', strip_tags((string)$n)));
  $n = preg_replace('/[^\p{L}\p{N} &\'.,()+-]/u', '', $n);
  return mb_strlen($n) >= 2 && mb_strlen($n) <= 60 ? $n : '';
}

function co_clean_domain($d) {
  $d = strtolower(trim((string)$d));
  $d = preg_replace('#^https?://#', '', $d);
  $d = preg_replace('#[/?\#].*$#', '', $d);
  $d = preg_replace('/^www\./', '', $d);
  return preg_match('/^(?=.{4,80}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $d) ? $d : '';
}

function co_dir($sub) {
  $dir = sys_get_temp_dir() . '/ha-company/' . $sub;
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  return $dir;
}
function co_cache_key($name, $domain) { return sha1('v2|' . mb_strtolower($name) . '|' . $domain); }
function co_cache_get($key, $age, $sub = 'brief') {
  $f = co_dir($sub) . "/$key.json";
  return is_file($f) && time() - filemtime($f) < $age ? file_get_contents($f) : null;
}
function co_cache_put($key, $body, $sub = 'brief') { @file_put_contents(co_dir($sub) . "/$key.json", $body); }

// Simple limits per visitor and per day, so nobody can run up costs
function co_rate_ok($what, $max, $window) {
  $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'x';
  $f = co_dir('rate') . '/' . sha1($what . '|' . $ip) . '.json';
  $now = time();
  $hits = is_file($f) ? array_filter((array)json_decode(file_get_contents($f), true), fn($t) => $now - $t < $window) : [];
  if (count($hits) >= $max) return false;
  $hits[] = $now;
  @file_put_contents($f, json_encode(array_values($hits)));
  return true;
}
function co_daily_ok($what, $max) {
  $f = co_dir('rate') . '/day-' . $what . '-' . gmdate('Ymd');
  $n = is_file($f) ? (int)file_get_contents($f) : 0;
  if ($n >= $max) return false;
  @file_put_contents($f, (string)($n + 1));
  return true;
}

// Only fetch public internet addresses
function co_public_host($url) {
  if (PHP_SAPI === 'cli' && getenv('CO_FIXTURES')) return true;
  $h = parse_url($url, PHP_URL_HOST);
  if (!$h) return false;
  $ip = gethostbyname($h);
  return $ip !== $h && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function co_get($url, $opts = []) {
  // Tests only (command line, never on the website): answer from saved responses
  if (PHP_SAPI === 'cli' && ($fx = getenv('CO_FIXTURES'))) {
    foreach (json_decode(file_get_contents($fx), true) as $pat => $resp) if (preg_match($pat, $url)) return [$resp[0], is_string($resp[1]) ? $resp[1] : json_encode($resp[1]), $url];
    return [404, '', $url];
  }
  $max = $opts['max'] ?? 800000;
  $ua = $opts['ua'] ?? 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
  $headers = array_merge(['Accept: text/html,application/json,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: en-GB,en;q=0.9'], $opts['headers'] ?? []);
  $ch = curl_init($url);
  $buf = '';
  curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => $opts['timeout'] ?? 10, CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_USERAGENT => $ua, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => $headers,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$buf, $max) { $buf .= $chunk; return strlen($buf) > $max ? 0 : strlen($chunk); },
  ]);
  if (!empty($opts['userpwd'])) curl_setopt($ch, CURLOPT_USERPWD, $opts['userpwd']);
  if (isset($opts['post'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['post']); }
  curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  curl_close($ch);
  return [$code, $buf, $final];
}
function co_json($url, $opts = []) {
  [$c, $b] = co_get($url, $opts + ['timeout' => 8]);
  return $c >= 200 && $c < 300 ? json_decode($b, true) : null;
}

function co_norm($s) {
  $s = mb_strtolower(html_entity_decode((string)$s));
  $s = preg_replace('/\b(the|plc|ltd|limited|llp|llc|inc|group|holdings|uk|co|company|corporation|corp)\b/u', ' ', $s);
  return trim(preg_replace('/[^\p{L}\p{N}]+/u', '', $s));
}

// ---------- Companies House ----------
function co_register($name) {
  $k = co_secret('COMPANIES_HOUSE_KEY');
  if (!$k) return ['available' => false];
  $base = 'https://api.company-information.service.gov.uk';
  $auth = ['userpwd' => $k . ':'];
  $s = co_json("$base/search/companies?items_per_page=10&q=" . urlencode($name), $auth);
  if (!$s) return ['available' => true, 'found' => false];
  $want = co_norm($name);
  $best = null; $score = -1;
  foreach ($s['items'] ?? [] as $i => $it) {
    $t = co_norm($it['title'] ?? '');
    // Exact name first, then the closest longer name ("Monzo Bank" beats "Monzo Support Services")
    $extra = min(30, 2 * max(0, mb_strlen($t) - mb_strlen($want)));
    $sc = ($t === $want ? 100 : (str_starts_with($t, $want) ? 60 - $extra : (str_contains($t, $want) ? 30 - $extra / 2 : 0)))
        + (($it['company_status'] ?? '') === 'active' ? 20 : 0) + (str_contains(strtolower($it['title'] ?? ''), 'plc') ? 5 : 0) - $i;
    if ($sc > $score) { $score = $sc; $best = $it; }
  }
  if (!$best || $score < 15) return ['available' => true, 'found' => false, 'search_url' => 'https://find-and-update.company-information.service.gov.uk/search/companies?q=' . urlencode($name)];
  $num = $best['company_number'];
  $p = co_json("$base/company/$num", $auth) ?: [];
  $o = co_json("$base/company/$num/officers?items_per_page=35", $auth) ?: [];
  $f = co_json("$base/company/$num/filing-history?items_per_page=8", $auth) ?: [];
  $directors = [];
  foreach ($o['items'] ?? [] as $x) {
    if (!empty($x['resigned_on'])) continue;
    if (!preg_match('/director|member|secretary/i', $x['officer_role'] ?? '')) continue;
    $directors[] = ['name' => co_tidy_person($x['name'] ?? ''), 'role' => ucfirst(str_replace('-', ' ', $x['officer_role'] ?? '')), 'since' => $x['appointed_on'] ?? '', 'occupation' => $x['occupation'] ?? ''];
  }
  $filings = [];
  foreach ($f['items'] ?? [] as $x) $filings[] = ['date' => $x['date'] ?? '', 'what' => co_filing_words($x)];
  $addr = $p['registered_office_address'] ?? [];
  return [
    'available' => true, 'found' => true,
    'name' => $p['company_name'] ?? $best['title'], 'number' => $num,
    'status' => $p['company_status'] ?? ($best['company_status'] ?? ''),
    'type' => $p['type'] ?? '', 'created' => $p['date_of_creation'] ?? ($best['date_of_creation'] ?? ''),
    'address' => implode(', ', array_filter([$addr['address_line_1'] ?? '', $addr['locality'] ?? '', $addr['postal_code'] ?? ''])),
    'sic' => array_map(fn($c) => ['code' => $c, 'label' => co_sic($c)], $p['sic_codes'] ?? []),
    'accounts_made_up_to' => $p['accounts']['last_accounts']['made_up_to'] ?? '',
    'accounts_type' => $p['accounts']['last_accounts']['type'] ?? '',
    'accounts_overdue' => !empty($p['accounts']['overdue']),
    'directors' => array_slice($directors, 0, 12), 'directors_total' => count($directors),
    'recent_filings' => array_slice($filings, 0, 6),
    'url' => "https://find-and-update.company-information.service.gov.uk/company/$num",
    'others' => array_values(array_map(fn($x) => ['name' => $x['title'], 'number' => $x['company_number'], 'status' => $x['company_status'] ?? ''],
      array_slice(array_filter($s['items'] ?? [], fn($x) => $x['company_number'] !== $num), 0, 4))),
  ];
}
function co_tidy_person($n) {
  // Register gives "SURNAME, Firstname Middle"; show "Firstname Surname"
  if (strpos($n, ',') === false) return $n;
  [$last, $first] = array_map('trim', explode(',', $n, 2));
  $first = preg_replace('/^(Mr|Mrs|Ms|Miss|Dr|Sir|Dame|Lord|Lady)\s+/i', '', $first);
  return trim(explode(' ', $first)[0] . ' ' . ucwords(mb_strtolower($last)));
}
function co_filing_words($x) {
  $t = $x['type'] ?? '';
  $map = ['AA' => 'Annual accounts', 'CS01' => 'Confirmation statement', 'AP01' => 'New director appointed', 'TM01' => 'Director left',
    'CH01' => 'Director details changed', 'SH01' => 'New shares issued', 'MR01' => 'New charge (a loan secured on assets)', 'MR04' => 'Charge paid off',
    'AD01' => 'Registered office moved', 'PSC01' => 'New person with significant control', 'PSC07' => 'Person with significant control left',
    'NM01' => 'Name changed', 'RESOLUTIONS' => 'Shareholder resolution', 'AP03' => 'New secretary appointed', 'TM02' => 'Secretary left', 'AA01' => 'Accounts date changed'];
  if (isset($map[$t])) return $map[$t];
  return ucfirst(str_replace('-', ' ', $x['category'] ?? 'Filing'));
}
function co_sic($c) {
  // The most common codes; others show as the number with a link
  static $m = [
    '62012' => 'Business and domestic software development', '62020' => 'IT consultancy', '62090' => 'Other IT services', '63110' => 'Data processing and hosting',
    '63120' => 'Web portals', '58290' => 'Other software publishing', '70229' => 'Management consultancy', '70100' => 'Head office activities',
    '64191' => 'Banks', '64209' => 'Holding companies', '64999' => 'Other financial services', '66190' => 'Other activities auxiliary to financial services',
    '47110' => 'Supermarkets and general stores', '47190' => 'Other non-specialised retail', '47910' => 'Online and mail order retail',
    '10890' => 'Other food manufacturing', '11050' => 'Brewing', '20420' => 'Perfumes and toiletries manufacturing', '21200' => 'Pharmaceutical preparations',
    '35110' => 'Electricity production', '35140' => 'Electricity trade', '35220' => 'Gas distribution', '06100' => 'Crude oil extraction', '19201' => 'Oil refining',
    '46900' => 'Wholesale', '49410' => 'Road freight', '52290' => 'Other transport support', '56101' => 'Restaurants', '55100' => 'Hotels',
    '68100' => 'Buying and selling own property', '68209' => 'Letting own property', '41100' => 'Property development', '41201' => 'Building construction',
    '72190' => 'Research and development', '73110' => 'Advertising agencies', '74909' => 'Other professional services', '78109' => 'Recruitment',
    '82990' => 'Other business support', '85590' => 'Other education', '86900' => 'Other health services', '96090' => 'Other personal services', '99999' => 'Dormant',
    '61900' => 'Other telecoms', '61100' => 'Wired telecoms', '61200' => 'Wireless telecoms', '65120' => 'Non-life insurance', '65110' => 'Life insurance',
  ];
  return $m[$c] ?? '';
}

// ---------- Their website and careers page ----------
function co_site($domain) {
  $html = ''; $final = '';
  foreach (["https://$domain/", "https://www.$domain/"] as $u) {
    if (!co_public_host($u)) continue;
    [$c, $b, $f] = co_get($u);
    if ($c >= 200 && $c < 400 && strlen($b) > 500) { $html = $b; $final = $f; break; }
  }
  if ($html === '') return ['opens' => false, 'domain' => $domain];
  $out = ['opens' => true, 'domain' => $domain, 'url' => $final, 'html' => $html];
  preg_match('#<title[^>]*>(.*?)</title>#is', $html, $t);
  preg_match('#<meta[^>]+(?:name|property)=["\'](?:og:)?description["\'][^>]+content=["\']([^"\']*)#i', $html, $d);
  $out['title'] = mb_substr(trim(html_entity_decode(strip_tags($t[1] ?? ''))), 0, 140);
  $out['description'] = mb_substr(trim(html_entity_decode($d[1] ?? '')), 0, 280);
  // Social links
  $social = [];
  foreach (['linkedin' => '#https?://(?:[a-z]+\.)?linkedin\.com/company/[^"\'\s<>?]+#i', 'x' => '#https?://(?:www\.)?(?:twitter|x)\.com/(?!share|intent)[A-Za-z0-9_]{2,}#i',
    'instagram' => '#https?://(?:www\.)?instagram\.com/[A-Za-z0-9_.]{2,}#i', 'youtube' => '#https?://(?:www\.)?youtube\.com/(?:@|channel/|c/|user/)[^"\'\s<>?]+#i'] as $k => $re) {
    if (preg_match($re, $html, $m)) $social[$k] = rtrim($m[0], '/');
  }
  $out['social'] = $social;
  // Careers page: a link whose words or address look like jobs
  $careers = '';
  if (preg_match_all('#<a\b[^>]*href=["\']([^"\'\#]+)["\'][^>]*>(.*?)</a>#is', $html, $mm, PREG_SET_ORDER)) {
    $best = 0;
    foreach ($mm as $a) {
      $href = html_entity_decode($a[1]); $text = strtolower(trim(strip_tags($a[2])));
      $sc = 0;
      if (preg_match('/^(careers?|jobs|join us|join the team|work (with|for) us|vacancies|we\'re hiring|open roles)$/', $text)) $sc += 3;
      elseif (preg_match('/careers?|jobs|vacanc|join us|hiring/', $text)) $sc += 2;
      if (preg_match('#/(careers?|jobs|join-us|work-with-us|vacancies)(/|$|\?)#i', $href)) $sc += 2;
      if (preg_match('#(greenhouse\.io|lever\.co|ashbyhq\.com|workable\.com|smartrecruiters\.com|recruitee\.com|teamtailor\.com|myworkdayjobs\.com|bamboohr\.com|personio\.)#i', $href)) $sc += 3;
      if ($sc > $best) { $best = $sc; $careers = $href; }
    }
  }
  if ($careers !== '') $careers = co_abs($careers, $final);
  $out['careers_url'] = $careers;
  $out['careers_html'] = '';
  if ($careers && co_public_host($careers) && !preg_match('#linkedin\.com#i', $careers)) {
    [$c, $b, $f] = co_get($careers);
    if ($c >= 200 && $c < 400) { $out['careers_html'] = $b; $out['careers_url'] = $f ?: $careers; }
  }
  // What they say they do: their About page, or failing that the home page's own words
  $about = ''; $aboutUrl = '';
  if (preg_match_all('#<a\b[^>]*href=["\']([^"\'\#]+)["\'][^>]*>(.*?)</a>#is', $html, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $a) {
      $text = strtolower(trim(strip_tags($a[2])));
      if (preg_match('/^(about|about us|who we are|our story|our company|company)$/', $text) || preg_match('#/(about|about-us|who-we-are|our-story)/?$#i', $a[1])) { $aboutUrl = co_abs(html_entity_decode($a[1]), $final); break; }
    }
  }
  if ($aboutUrl && co_public_host($aboutUrl) && parse_url($aboutUrl, PHP_URL_HOST) === parse_url($final, PHP_URL_HOST)) {
    [$c, $b] = co_get($aboutUrl);
    if ($c >= 200 && $c < 400) $about = co_paragraphs($b);
  }
  if ($about === '') { $about = co_paragraphs($html); $aboutUrl = $about ? $final : ''; }
  $out['about'] = $about; $out['about_url'] = $aboutUrl;
  $words = strtolower($out['title'] . ' ' . $out['description'] . ' ' . $about);
  $out['recruiter'] = (bool)preg_match('/\b(recruitment|recruiter|recruiting agency|staffing|talent solutions|executive search|headhunt)/', $words);
  // Areas they hire or recruit for: links on the careers page that go one level deeper
  $areas = [];
  if (!empty($out['careers_html']) && $out['careers_url']) {
    $cpath = rtrim(parse_url($out['careers_url'], PHP_URL_PATH) ?: '/', '/');
    if (preg_match_all('#<a\b[^>]*href=["\']([^"\'\#?]+)["\'][^>]*>(.*?)</a>#is', $out['careers_html'], $mm, PREG_SET_ORDER)) {
      foreach ($mm as $a) {
        $p = rtrim(parse_url(co_abs(html_entity_decode($a[1]), $out['careers_url']), PHP_URL_PATH) ?: '', '/');
        $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($a[2]))));
        if ($cpath !== '' && str_starts_with($p, $cpath . '/') && substr_count(substr($p, strlen($cpath)), '/') === 1 && $t !== '' && mb_strlen($t) < 50
          && !preg_match('/\b(alerts?|search|submit|upload|log ?in|sign[ -]?(in|up)|register|apply|saved|cv)\b/i', $t . ' ' . str_replace(['-', '_', '/'], ' ', $p))) $areas[$t] = 1;
      }
    }
  }
  $out['job_areas'] = array_slice(array_keys($areas), 0, 10);
  return $out;
}

// The first few real paragraphs of a page, as plain text
function co_paragraphs($html) {
  $html = preg_replace('#<(script|style|nav|header|footer|form|noscript|svg)\b.*?</\1>#is', ' ', $html);
  $out = []; $len = 0;
  if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $m)) {
    foreach ($m[1] as $p) {
      $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5)));
      if (mb_strlen($t) < 70 || preg_match('/cookie|javascript|©|copyright|all rights reserved|privacy/i', $t) || in_array($t, $out, true)) continue;
      $out[] = $t; $len += mb_strlen($t);
      if (count($out) >= 3 || $len > 700) break;
    }
  }
  return mb_substr(implode("\n\n", $out), 0, 900);
}

// Job adverts written into the careers page itself (schema.org JobPosting)
function co_jobs_from_ld($html, $base) {
  $roles = [];
  if (!preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', (string)$html, $mm)) return $roles;
  foreach ($mm[1] as $j) {
    $j = json_decode(html_entity_decode(trim($j)), true); if (!$j) continue;
    $walk = function ($x) use (&$walk, &$roles, $base) {
      if (!is_array($x)) return;
      if (in_array('JobPosting', (array)($x['@type'] ?? []), true) && !empty($x['title'])) {
        $loc = $x['jobLocation'] ?? []; if (isset($loc[0])) $loc = $loc[0];
        $addr = $loc['address'] ?? [];
        $roles[] = ['title' => strip_tags($x['title']), 'location' => is_array($addr) ? implode(', ', array_filter([$addr['addressLocality'] ?? '', $addr['addressCountry']['name'] ?? (is_string($addr['addressCountry'] ?? null) ? $addr['addressCountry'] : '')])) : (string)$addr,
          'team' => is_string($x['occupationalCategory'] ?? null) ? $x['occupationalCategory'] : '', 'url' => $x['url'] ?? $base, 'posted' => co_date($x['datePosted'] ?? '')];
      }
      foreach ($x as $v) if (is_array($v)) $walk($v);
    };
    $walk($j);
  }
  return array_slice($roles, 0, 60);
}
function co_abs($href, $base) {
  if (preg_match('#^https?://#i', $href)) return $href;
  if (str_starts_with($href, '//')) return 'https:' . $href;
  $p = parse_url($base);
  $root = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
  if (str_starts_with($href, '/')) return $root . $href;
  return rtrim($root . dirname($p['path'] ?? '/'), '/') . '/' . $href;
}

// ---------- Open roles, read from the hiring system they use ----------
function co_ats_from_text($text) {
  $res = [];
  $pats = [
    'greenhouse' => ['#(?:boards|job-boards)(?:\.eu)?\.greenhouse\.io/(?:embed/job_board(?:/js)?\?for=)?([a-z0-9_-]+)#i', '#greenhouse\.io/embed/job_board(?:/js)?\?for=([a-z0-9_-]+)#i'],
    'lever' => ['#jobs(?:\.eu)?\.lever\.co/([a-z0-9_.-]+)#i'],
    'ashby' => ['#jobs\.ashbyhq\.com/([a-z0-9_.%-]+)#i'],
    'workable' => ['#apply\.workable\.com/([a-z0-9_-]+)#i', '#https?://([a-z0-9-]+)\.workable\.com#i'],
    'smartrecruiters' => ['#(?:careers|jobs)\.smartrecruiters\.com/([A-Za-z0-9_-]+)#'],
    'recruitee' => ['#https?://([a-z0-9-]+)\.recruitee\.com#i'],
    'bamboohr' => ['#https?://([a-z0-9-]+)\.bamboohr\.com#i'],
    'personio' => ['#https?://([a-z0-9-]+)\.jobs\.personio\.(?:de|com)#i'],
    'teamtailor' => ['#https?://([a-z0-9-]+)\.teamtailor\.com#i'],
    'workday' => ['#https?://([a-z0-9-]+\.wd\d+\.myworkdayjobs\.com/[A-Za-z0-9_/-]+)#i'],
  ];
  foreach ($pats as $ats => $list) foreach ($list as $re) {
    if (preg_match($re, $text, $m)) { $slug = rtrim($m[1], '/.'); if (!in_array(strtolower($slug), ['embed', 'www', 'api', 'apply', 'jobs', 'careers'])) { $res[$ats] = $slug; break; } }
  }
  return $res;
}

function co_jobs($name, $domain, $site) {
  $found = [];
  if ($site) {
    $found = co_ats_from_text(($site['careers_url'] ?? '') . ' ' . ($site['careers_html'] ?? '') . ' ' . ($site['html'] ?? ''));
  }
  $how = 'their careers page';
  // Nothing linked? Try the company's name on the three hiring systems with open job lists
  if (!$found) {
    $slugs = array_unique(array_filter([$domain ? explode('.', $domain)[0] : '', preg_replace('/[^a-z0-9]/', '', strtolower($name)), trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-')]));
    foreach ($slugs as $s) {
      $g = co_json("https://boards-api.greenhouse.io/v1/boards/$s");
      if ($g && co_names_match($g['name'] ?? '', $name)) { $found = ['greenhouse' => $s]; break; }
    }
    if (!$found) foreach ($slugs as $s) {
      foreach (['ashby' => "https://api.ashbyhq.com/posting-api/job-board/$s", 'lever' => "https://api.lever.co/v0/postings/$s?mode=json&limit=1"] as $ats => $u) {
        $j = co_json($u);
        if ($ats === 'ashby' && !empty($j['jobs'])) { $found = [$ats => $s]; break 2; }
        if ($ats === 'lever' && is_array($j) && count($j)) { $found = [$ats => $s]; break 2; }
      }
    }
    $how = 'their name on ' . ($found ? array_key_first($found) : 'the main hiring systems');
    if ($found) $how .= ' (not linked from their site, so check it is them)';
  }
  $out = ['careers_url' => $site['careers_url'] ?? '', 'system' => '', 'found_via' => $how, 'roles' => [], 'total' => null, 'board_url' => ''];
  foreach ($found as $ats => $slug) {
    $r = co_read_board($ats, $slug);
    if ($r === null) continue;
    $out = array_merge($out, $r, ['system' => $ats]);
    break;
  }
  if (!$out['system'] && $found) { $ats = array_key_first($found); $out['system'] = $ats; $out['board_url'] = co_board_link($ats, $found[$ats]); }
  if (!$out['roles'] && !empty($site['careers_html'])) {
    $ld = co_jobs_from_ld($site['careers_html'], $site['careers_url']);
    if ($ld) { $out['roles'] = $ld; $out['total'] = count($ld); $out['system'] = 'their website'; $out['board_url'] = $site['careers_url']; $out['found_via'] = 'job adverts written into their careers page'; }
  }
  // Tidy, UK first, then by newest
  $roles = $out['roles'];
  foreach ($roles as &$r) $r['uk'] = (bool)preg_match('/\b(uk|united kingdom|london|manchester|edinburgh|glasgow|bristol|birmingham|leeds|cardiff|belfast|cambridge|oxford|reading|england|scotland|wales|gb|remote\s*[-(,]?\s*(uk|emea|europe))\b/i', $r['location'] ?? '');
  unset($r);
  usort($roles, fn($a, $b) => [$b['uk'], $b['posted'] ?? ''] <=> [$a['uk'], $a['posted'] ?? '']);
  $out['total'] = $out['total'] ?? count($roles);
  $out['uk_total'] = count(array_filter($roles, fn($r) => $r['uk']));
  $teams = [];
  foreach ($roles as $r) if (!empty($r['team'])) $teams[$r['team']] = ($teams[$r['team']] ?? 0) + 1;
  arsort($teams);
  $out['teams'] = array_slice(array_map(fn($k, $v) => ['team' => $k, 'count' => $v], array_keys($teams), $teams), 0, 6);
  $out['ai_roles'] = count(array_filter($roles, fn($r) => preg_match('/\b(ai|ml|machine learning|llm|genai|data scien|artificial intelligence|applied scien)/i', $r['title'] . ' ' . ($r['team'] ?? ''))));
  $out['roles'] = array_slice($roles, 0, 40);
  return $out;
}
function co_names_match($a, $b) { $a = co_norm($a); $b = co_norm($b); return $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a)); }
function co_board_link($ats, $s) {
  return [
    'greenhouse' => "https://job-boards.greenhouse.io/$s", 'lever' => "https://jobs.lever.co/$s", 'ashby' => "https://jobs.ashbyhq.com/$s",
    'workable' => "https://apply.workable.com/$s/", 'smartrecruiters' => "https://careers.smartrecruiters.com/$s", 'recruitee' => "https://$s.recruitee.com/",
    'bamboohr' => "https://$s.bamboohr.com/careers", 'personio' => "https://$s.jobs.personio.de/", 'teamtailor' => "https://$s.teamtailor.com/jobs", 'workday' => "https://$s",
  ][$ats] ?? '';
}
function co_date($v) {
  if (!$v) return '';
  if (is_numeric($v)) return gmdate('Y-m-d', $v > 1e11 ? intdiv((int)$v, 1000) : (int)$v);
  $t = strtotime((string)$v); return $t ? gmdate('Y-m-d', $t) : '';
}
function co_read_board($ats, $s) {
  $roles = []; $total = null;
  switch ($ats) {
    case 'greenhouse':
      $j = co_json("https://boards-api.greenhouse.io/v1/boards/$s/jobs?content=true", ['max' => 6000000, 'timeout' => 12]);
      if (!$j) return null;
      foreach ($j['jobs'] ?? [] as $x) $roles[] = ['title' => $x['title'] ?? '', 'location' => $x['location']['name'] ?? '', 'team' => $x['departments'][0]['name'] ?? '', 'url' => $x['absolute_url'] ?? '', 'posted' => co_date($x['first_published'] ?? $x['updated_at'] ?? '')];
      $total = $j['meta']['total'] ?? count($roles);
      break;
    case 'lever':
      $j = co_json("https://api.lever.co/v0/postings/$s?mode=json", ['max' => 6000000]) ?? co_json("https://api.eu.lever.co/v0/postings/$s?mode=json", ['max' => 6000000]);
      if (!is_array($j)) return null;
      foreach ($j as $x) $roles[] = ['title' => $x['text'] ?? '', 'location' => $x['categories']['location'] ?? '', 'team' => $x['categories']['team'] ?? ($x['categories']['department'] ?? ''), 'url' => $x['hostedUrl'] ?? '', 'posted' => co_date($x['createdAt'] ?? '')];
      break;
    case 'ashby':
      $j = co_json("https://api.ashbyhq.com/posting-api/job-board/" . rawurlencode(urldecode($s)), ['max' => 6000000]);
      if (!$j) return null;
      foreach ($j['jobs'] ?? [] as $x) { if (isset($x['isListed']) && !$x['isListed']) continue; $roles[] = ['title' => $x['title'] ?? '', 'location' => ($x['location'] ?? '') . (!empty($x['isRemote']) && stripos($x['location'] ?? '', 'remote') === false ? ' (remote)' : ''), 'team' => $x['department'] ?? ($x['team'] ?? ''), 'url' => $x['jobUrl'] ?? '', 'posted' => co_date($x['publishedAt'] ?? '')]; }
      break;
    case 'workable':
      $j = co_json("https://apply.workable.com/api/v1/widget/accounts/$s");
      if (!$j) return null;
      foreach ($j['jobs'] ?? [] as $x) $roles[] = ['title' => $x['title'] ?? '', 'location' => implode(', ', array_filter([$x['city'] ?? '', $x['country'] ?? ''])), 'team' => $x['department'] ?? '', 'url' => $x['url'] ?? ($x['shortlink'] ?? ''), 'posted' => co_date($x['published_on'] ?? '')];
      break;
    case 'smartrecruiters':
      $j = co_json("https://api.smartrecruiters.com/v1/companies/$s/postings?limit=100");
      if (!$j) return null;
      foreach ($j['content'] ?? [] as $x) $roles[] = ['title' => $x['name'] ?? '', 'location' => implode(', ', array_filter([$x['location']['city'] ?? '', strtoupper($x['location']['country'] ?? '')])), 'team' => $x['department']['label'] ?? '', 'url' => "https://jobs.smartrecruiters.com/$s/" . ($x['id'] ?? ''), 'posted' => co_date($x['releasedDate'] ?? '')];
      $total = $j['totalFound'] ?? count($roles);
      break;
    case 'recruitee':
      $j = co_json("https://$s.recruitee.com/api/offers/");
      if (!$j) return null;
      foreach ($j['offers'] ?? [] as $x) $roles[] = ['title' => $x['title'] ?? '', 'location' => $x['location'] ?? '', 'team' => $x['department'] ?? '', 'url' => $x['careers_url'] ?? '', 'posted' => co_date($x['published_at'] ?? '')];
      break;
    case 'bamboohr':
      $j = co_json("https://$s.bamboohr.com/careers/list");
      if (!$j) return null;
      foreach ($j['result'] ?? [] as $x) $roles[] = ['title' => $x['jobOpeningName'] ?? '', 'location' => implode(', ', array_filter([$x['location']['city'] ?? '', $x['location']['state'] ?? ''])), 'team' => $x['departmentLabel'] ?? '', 'url' => "https://$s.bamboohr.com/careers/" . ($x['id'] ?? ''), 'posted' => ''];
      break;
    case 'personio':
      [$c, $b] = co_get("https://$s.jobs.personio.de/xml?language=en");
      if ($c < 200 || $c >= 300 || !function_exists('simplexml_load_string')) return null;
      $x = @simplexml_load_string($b, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
      if (!$x) return null;
      foreach ($x->position as $p) $roles[] = ['title' => (string)$p->name, 'location' => (string)$p->office, 'team' => (string)$p->department, 'url' => "https://$s.jobs.personio.de/job/" . (string)$p->id, 'posted' => co_date((string)$p->createdAt)];
      break;
    default:
      return null; // Teamtailor and Workday: no open list, so we just link to it
  }
  $roles = array_values(array_filter($roles, fn($r) => trim($r['title']) !== ''));
  return ['roles' => $roles, 'total' => $total ?? count($roles), 'board_url' => co_board_link($ats, $s)];
}

// ---------- Recent news (Google News, UK edition) ----------
function co_news($name) {
  $q = '"' . $name . '" when:30d';
  [$c, $b] = co_get('https://news.google.com/rss/search?hl=en-GB&gl=GB&ceid=GB:en&q=' . urlencode($q), ['timeout' => 8, 'max' => 400000]);
  if ($c < 200 || $c >= 300 || !function_exists('simplexml_load_string')) return ['available' => false];
  $x = @simplexml_load_string($b, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
  if (!$x || !isset($x->channel)) return ['available' => false];
  $items = []; $seen = [];
  foreach ($x->channel->item as $it) {
    $title = trim((string)$it->title);
    $src = trim((string)$it->source);
    if ($src && str_ends_with($title, " - $src")) $title = substr($title, 0, -strlen(" - $src"));
    $k = co_norm($title);
    if (isset($seen[$k])) continue; $seen[$k] = 1;
    $items[] = ['title' => $title, 'source' => $src, 'url' => (string)$it->link, 'date' => co_date((string)$it->pubDate)];
    if (count($items) >= 8) break;
  }
  usort($items, fn($a, $b) => strcmp($b['date'], $a['date']));
  return ['available' => true, 'items' => $items, 'count' => count($x->channel->item)];
}
