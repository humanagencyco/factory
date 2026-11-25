<?php
// Same-site bridge to Miru's Local Finder search, so Factory pages can use it.
// Only this one search is allowed, with a postcode and a short query.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=600');
$pc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['pc'] ?? ''));
$q  = trim(preg_replace('/[^A-Za-z0-9 &\'-]/', '', $_GET['q'] ?? ''));
if (!preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]?[0-9][A-Z]{2}$/', $pc) || strlen($q) < 2 || strlen($q) > 40) {
  http_response_code(400);
  echo json_encode(['error' => 'Add a UK postcode and what to look for.']);
  exit;
}
// Keep each answer for a day, so repeat searches don't cost another Google call
$dir = sys_get_temp_dir() . '/ha-finder';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
$key = $dir . '/' . sha1($pc . '|' . strtolower($q)) . '.json';
if (is_file($key) && time() - filemtime($key) < 86400) { header('X-Cache: hit'); readfile($key); exit; }
$url = 'https://miru.humanagency.co/api/finder/search?pc=' . urlencode($pc) . '&q=' . urlencode($q) . '&open_now=0';
$ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: humanagency-factory\r\n"]]);
$body = @file_get_contents($url, false, $ctx);
if ($body === false) {
  http_response_code(502);
  echo json_encode(['error' => "Couldn't reach the search right now."]);
  exit;
}
$data = json_decode($body, true);
if (is_array($data) && !empty($data['results'])) @file_put_contents($key, $body);
header('X-Cache: miss');
echo $body;
