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
$url = 'https://miru.humanagency.co/api/finder/search?pc=' . urlencode($pc) . '&q=' . urlencode($q) . '&open_now=0';
$ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: humanagency-factory\r\n"]]);
$body = @file_get_contents($url, false, $ctx);
if ($body === false) {
  http_response_code(502);
  echo json_encode(['error' => "Couldn't reach the search right now."]);
  exit;
}
echo $body;
