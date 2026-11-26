<?php
// Bin days for councils Miru doesn't cover yet. Read straight from the council's own public pages.
//   ?council=richmond&postcode=TW11ER   -> list of addresses (id = UPRN)
//   ?council=richmond&uprn=100022331436  -> next collection for each bin
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$council = strtolower($_GET['council'] ?? '');
$pc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['postcode'] ?? ''));
$uprn = preg_replace('/\D/', '', $_GET['uprn'] ?? '');
function fail($code, $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
if ($council !== 'richmond') fail(400, 'Council not supported here yet.');
if (!$uprn && !preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]?[0-9][A-Z]{2}$/', $pc)) fail(400, 'Add a UK postcode.');

$dir = sys_get_temp_dir() . '/ha-bins';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
$key = $dir . '/' . $council . '-' . ($uprn ? 'u' . $uprn : 'p' . $pc) . '-' . date('Ymd') . '.json';
if (is_file($key)) { header('X-Cache: hit'); readfile($key); exit; }

$jar = tempnam(sys_get_temp_dir(), 'hab');
function get($url, $jar) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; HumanAgencyFactory/1.0; +https://humanagency.co/factory/)']);
  $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
  return $code >= 200 && $code < 300 ? $b : null;
}
$tidy = fn($s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
$base = 'https://www.richmond.gov.uk';

if ($uprn) {
  $h = get($base . '/my_richmond?pid=' . $uprn, $jar);
  @unlink($jar);
  if (!$h) fail(502, "Couldn't reach Richmond's site.");
  $i = strpos($h, 'my-waste');
  if ($i === false) fail(404, 'No collection details for that address.');
  $sec = substr($h, $i, 8000);
  $end = strpos($sec, 'my-item', 20); if ($end) $sec = substr($sec, 0, $end);
  preg_match_all('#<h4>(.*?)</h4>\s*<ul>(.*?)</ul>#s', $sec, $m, PREG_SET_ORDER);
  $out = [];
  foreach ($m as $x) {
    $bin = $tidy($x[1]);
    preg_match_all('#<li>(.*?)</li>#s', $x[2], $li);
    foreach ($li[1] as $d) {
      $t = strtotime($tidy($d));
      if ($t) $out[] = ['bin' => $bin, 'date' => date('Y-m-d', $t)];
    }
  }
  $body = json_encode(['council' => 'Richmond upon Thames', 'collections' => $out, 'source' => 'Richmond Council (My Richmond)', 'checked' => date('j M Y')]);
} else {
  $h = get($base . '/myrichmond', $jar);
  if (!$h || !preg_match('#name="__RequestVerificationToken"[^>]*value="([^"]+)"#', $h, $tok)) { @unlink($jar); fail(502, "Couldn't reach Richmond's site."); }
  preg_match('#name="ufprt"[^>]*value="([^"]+)"#', $h, $uf);
  $q = http_build_query(['postcode' => $pc, 'property' => '', '__RequestVerificationToken' => $tok[1], 'ufprt' => $uf[1] ?? '', 'findproperty' => 'Get details']);
  $h = get($base . '/myrichmond?' . $q, $jar);
  @unlink($jar);
  if (!$h) fail(502, "Couldn't reach Richmond's site.");
  preg_match_all('#<option[^>]*value="(\d{6,})"[^>]*>(.*?)</option>#s', $h, $m, PREG_SET_ORDER);
  $addr = array_map(fn($x) => ['id' => $x[1], 'label' => $tidy($x[2])], $m);
  if (!$addr) fail(404, 'No addresses found for that postcode on Richmond\'s site.');
  $body = json_encode(['status' => 'addresses', 'council' => 'Richmond upon Thames', 'addresses' => $addr]);
}
@file_put_contents($key, $body);
header('X-Cache: miss');
echo $body;
