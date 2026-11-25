<?php
// House prices near a postcode, from HM Land Registry open data.
// 1. The council area's average price (UK House Price Index)
// 2. What homes actually sold for in the nearest postcodes over the last two years (Price Paid Data)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['pc'] ?? ''));
if (!preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]?[0-9][A-Z]{2}$/', $pc)) { http_response_code(400); echo json_encode(['error' => 'Add a UK postcode.']); exit; }

$dir = sys_get_temp_dir() . '/ha-houses';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
$key = $dir . '/v2-' . $pc . '.json';
if (is_file($key) && time() - filemtime($key) < 7 * 86400) { header('Cache-Control: public, max-age=86400'); header('X-Cache: hit'); readfile($key); exit; }

function getj($url, $accept = 'application/json', $timeout = 25) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => ["Accept: $accept"], CURLOPT_USERAGENT => 'HumanAgencyFactory/1.0 (+https://humanagency.co/factory/)']);
  $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
  return $code >= 200 && $code < 300 ? json_decode($b, true) : null;
}
function sparql($q) {
  return getj('https://landregistry.data.gov.uk/landregistry/query?query=' . rawurlencode($q), 'application/sparql-results+json');
}

// Postcode details and the postcodes around it
$p = getj('https://api.postcodes.io/postcodes/' . rawurlencode($pc));
if (!$p || empty($p['result'])) { http_response_code(404); echo json_encode(['error' => "Couldn't find that postcode."]); exit; }
$r = $p['result'];
$near = getj('https://api.postcodes.io/postcodes?lon=' . $r['longitude'] . '&lat=' . $r['latitude'] . '&radius=2000&limit=100');
$pcs = [$r['postcode']];
foreach (($near['result'] ?? []) as $n) $pcs[] = $n['postcode'];
$pcs = array_values(array_unique($pcs));

// 1. Area average (latest month) for the council area
$district = $r['admin_district'] ?? '';
$slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', str_replace(['&', ','], ['and', ''], $district)), '-'));
$area = null;
if ($slug) {
  $q = 'PREFIX ukhpi: <http://landregistry.data.gov.uk/def/ukhpi/>
SELECT ?month ?avg ?annual ?detached ?semi ?terraced ?flat WHERE {
  ?r ukhpi:refRegion <http://landregistry.data.gov.uk/id/region/' . $slug . '> ; ukhpi:refMonth ?month ; ukhpi:averagePrice ?avg .
  OPTIONAL { ?r ukhpi:percentageAnnualChange ?annual }
  OPTIONAL { ?r ukhpi:averagePriceDetached ?detached }
  OPTIONAL { ?r ukhpi:averagePriceSemiDetached ?semi }
  OPTIONAL { ?r ukhpi:averagePriceTerraced ?terraced }
  OPTIONAL { ?r ukhpi:averagePriceFlatMaisonette ?flat }
} ORDER BY DESC(?month) LIMIT 1';
  $h = sparql($q);
  $b = $h['results']['bindings'][0] ?? null;
  if ($b) {
    $v = fn($k) => isset($b[$k]) ? round((float)$b[$k]['value']) : null;
    $area = ['name' => $district, 'month' => $b['month']['value'] ?? '', 'average' => $v('avg'),
      'annual_change' => isset($b['annual']) ? round((float)$b['annual']['value'], 1) : null,
      'by_type' => ['Detached' => $v('detached'), 'Semi-detached' => $v('semi'), 'Terraced' => $v('terraced'), 'Flat' => $v('flat')]];
  }
}

// 2. Sales in the nearest postcodes, last two years
$since = date('Y-m-d', strtotime('-24 months'));
$values = implode(' ', array_map(fn($x) => '"' . addslashes($x) . '"', $pcs));
$q = 'PREFIX lrppi: <http://landregistry.data.gov.uk/def/ppi/>
PREFIX lrcommon: <http://landregistry.data.gov.uk/def/common/>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
SELECT ?price ?date ?type ?pc ?street WHERE {
  VALUES ?pc { ' . $values . ' }
  ?addr lrcommon:postcode ?pc .
  OPTIONAL { ?addr lrcommon:street ?street }
  ?tx lrppi:propertyAddress ?addr ; lrppi:pricePaid ?price ; lrppi:transactionDate ?date ; lrppi:propertyType ?type .
  FILTER (?date >= "' . $since . '"^^xsd:date)
} ORDER BY DESC(?date) LIMIT 300';
$s = sparql($q);
$sales = [];
$names = ['detached' => 'Detached', 'semi-detached' => 'Semi-detached', 'terraced' => 'Terraced', 'flat-maisonette' => 'Flat', 'other-property-type' => 'Other'];
foreach (($s['results']['bindings'] ?? []) as $x) {
  $t = basename($x['type']['value'] ?? '');
  $sales[] = ['price' => (int)$x['price']['value'], 'date' => substr($x['date']['value'], 0, 10), 'type' => $names[$t] ?? 'Other',
    'postcode' => $x['pc']['value'], 'street' => ucwords(strtolower($x['street']['value'] ?? ''))];
}
function median($a) { if (!$a) return null; sort($a); $n = count($a); return $n % 2 ? $a[intdiv($n, 2)] : (int)round(($a[$n/2 - 1] + $a[$n/2]) / 2); }
$byType = [];
foreach ($sales as $x) $byType[$x['type']][] = $x['price'];
$types = [];
foreach ($byType as $t => $list) $types[$t] = ['median' => median($list), 'count' => count($list)];

$out = ['postcode' => $r['postcode'], 'area' => $area,
  'nearby' => ['count' => count($sales), 'median' => median(array_column($sales, 'price')), 'by_type' => $types, 'since' => $since, 'postcodes_checked' => count($pcs),
    'recent' => array_slice($sales, 0, 8)],
  'source' => 'HM Land Registry Price Paid Data and UK House Price Index. Contains HM Land Registry data © Crown copyright and database right. Licensed under the Open Government Licence v3.0.'];
$body = json_encode($out);
if ($area || $sales) { @file_put_contents($key, $body); header('Cache-Control: public, max-age=86400'); }
header('X-Cache: miss');
echo $body;
