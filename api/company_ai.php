<?php
// AI brief for a company: written only from the facts company.php already gathered,
// plus the company's Wikipedia summary. Visitors can't send their own text to the AI.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/company_lib.php';

$name = co_clean_name($_GET['name'] ?? '');
$domain = co_clean_domain($_GET['domain'] ?? '');
$wiki = trim(preg_replace('/[^\p{L}\p{N} &\'.,()_-]/u', '', (string)($_GET['wiki'] ?? '')));
if ($name === '' && $domain !== '') $name = ucfirst(explode('.', $domain)[0]);
$apiKey = co_secret('ANTHROPIC_API_KEY');
if (!$apiKey) { http_response_code(503); echo json_encode(['error' => 'not_set_up']); exit; }

$key = co_cache_key($name, $domain);
if ($hit = co_cache_get($key . '-' . sha1($wiki), 7 * 86400, 'ai')) { header('X-Cache: hit'); echo $hit; exit; }
$parts = [];
foreach (['register', 'jobs', 'news'] as $p) { $c = co_cache_get("$key-$p", 2 * 86400); $parts[$p] = $c ? json_decode($c, true) : []; }
if (!$parts['jobs'] && !$parts['register'] && !$parts['news']) { http_response_code(409); echo json_encode(['error' => 'Look the company up first.']); exit; }
if (!co_rate_ok('ai', 12, 3600) || !co_daily_ok('ai', (int)(co_secret('COMPANY_AI_DAILY_CAP') ?: 150))) {
  http_response_code(429); echo json_encode(['error' => 'The AI brief is busy right now. Try again later.']); exit;
}
$f = ['register' => $parts['register'], 'site' => $parts['jobs']['site'] ?? [], 'jobs' => $parts['jobs']['jobs'] ?? [], 'news' => $parts['news']];

// Wikipedia summary, fetched by us, not passed in
$summary = '';
if ($wiki !== '') {
  $w = co_json('https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $wiki)), ['ua' => 'HumanAgencyFactory/1.0 (humanagency.co)']);
  $summary = mb_substr($w['extract'] ?? '', 0, 1200);
}

// Keep the prompt small: just the useful facts
$r = $f['register'] ?? [];
$j = $f['jobs'] ?? [];
$lines = [];
$lines[] = "Company: $name" . ($domain ? " ($domain)" : '');
if ($summary) $lines[] = "Wikipedia summary: $summary";
if (!empty($f['site']['description'])) $lines[] = 'How their website describes them: ' . $f['site']['description'];
if (!empty($f['site']['about'])) $lines[] = 'In their own words (their website): ' . mb_substr($f['site']['about'], 0, 900);
if (!empty($f['site']['job_areas'])) $lines[] = 'Areas listed on their jobs page: ' . implode(', ', $f['site']['job_areas']);
if (!empty($f['site']['recruiter'])) $lines[] = 'They are a recruitment business, so their job adverts are mostly roles they fill for clients.';
if (!empty($r['found'])) {
  $lines[] = 'UK register: ' . $r['name'] . ', ' . $r['status'] . ', set up ' . $r['created'] . ', ' . $r['directors_total'] . ' current directors. Industry codes: '
    . implode('; ', array_map(fn($s) => $s['code'] . ($s['label'] ? ' ' . $s['label'] : ''), $r['sic'] ?? [])) . '. Last accounts made up to ' . ($r['accounts_made_up_to'] ?: 'unknown') . ($r['accounts_type'] ? ' (' . $r['accounts_type'] . ')' : '') . '.';
  if (!empty($r['recent_filings'])) $lines[] = 'Recent filings: ' . implode('; ', array_map(fn($x) => $x['date'] . ' ' . $x['what'], $r['recent_filings']));
}
if (!empty($j['roles'])) {
  $lines[] = 'Open roles from their own hiring system: ' . $j['total'] . ' in total, ' . $j['uk_total'] . ' in the UK, ' . $j['ai_roles'] . ' look AI or data related.';
  if (!empty($j['teams'])) $lines[] = 'Biggest hiring teams: ' . implode(', ', array_map(fn($t) => $t['team'] . ' (' . $t['count'] . ')', $j['teams']));
  $lines[] = 'Sample role titles: ' . implode('; ', array_slice(array_map(fn($x) => $x['title'], $j['roles']), 0, 25));
} else {
  $lines[] = 'Open roles: none found on a hiring system we can read.';
}
if (!empty($f['news']['items'])) $lines[] = 'Recent headlines (last 30 days): ' . implode(' | ', array_map(fn($x) => $x['date'] . ' ' . $x['title'] . ' (' . $x['source'] . ')', $f['news']['items']));
$facts_text = implode("\n", $lines);

$prompt = <<<P
You are helping someone prepare to talk to a company: a job seeker, a consultant or a supplier. Using ONLY the facts below, write a short brief.

Facts:
$facts_text

Rules:
- Use plain, everyday British English. Short sentences. No jargon, no hype, no em dashes.
- Every point must rest on a fact above. Put that fact in "evidence" in a few words. If the facts don't support something, leave it out.
- Don't invent numbers, people, products or plans.
- If the facts are thin, say less.

Reply with JSON only, in this shape:
{
  "snapshot": "Two sentences: what they do and what looks like it's going on right now.",
  "signals": [{"point": "Something notable, e.g. what the hiring says about where they're investing", "evidence": "..."}],
  "ai_ideas": [{"idea": "A specific place AI could help this company", "why": "Why it fits them", "evidence": "..."}],
  "questions": ["A good, specific question to ask them in a first conversation"]
}
Give 2 to 4 signals, 3 ai_ideas and 4 questions.
P;

[$c, $b] = co_get('https://api.anthropic.com/v1/messages', [
  'timeout' => 60, 'max' => 200000,
  'headers' => ['Content-Type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
  'post' => json_encode(['model' => co_secret('COMPANY_AI_MODEL') ?: 'claude-sonnet-5-5', 'max_tokens' => 1500, 'messages' => [['role' => 'user', 'content' => $prompt]]]),
]);
$resp = json_decode($b, true);
$text = '';
foreach (($resp['content'] ?? []) as $part) if (($part['type'] ?? '') === 'text') $text .= $part['text'];
if ($c < 200 || $c >= 300 || !preg_match('/\{.*\}/s', $text, $m) || !($brief = json_decode($m[0], true))) {
  http_response_code(502); echo json_encode(['error' => "Couldn't write the brief just now."]); exit;
}
$clean = fn($s, $n = 400) => mb_substr(trim(str_replace([' — ', ' – ', '—', '–'], [', ', ', ', ', ', '-'], strip_tags((string)$s))), 0, $n);
$out = [
  'snapshot' => $clean($brief['snapshot'] ?? '', 600),
  'signals' => array_slice(array_map(fn($x) => ['point' => $clean($x['point'] ?? ''), 'evidence' => $clean($x['evidence'] ?? '', 200)], (array)($brief['signals'] ?? [])), 0, 4),
  'ai_ideas' => array_slice(array_map(fn($x) => ['idea' => $clean($x['idea'] ?? ''), 'why' => $clean($x['why'] ?? ''), 'evidence' => $clean($x['evidence'] ?? '', 200)], (array)($brief['ai_ideas'] ?? [])), 0, 3),
  'questions' => array_slice(array_map(fn($q) => $clean($q, 300), (array)($brief['questions'] ?? [])), 0, 5),
  'label' => 'Written by AI from the facts on this page. Check before you rely on it.',
];
$body = json_encode($out);
co_cache_put($key . '-' . sha1($wiki), $body, 'ai');
header('X-Cache: miss');
echo $body;
