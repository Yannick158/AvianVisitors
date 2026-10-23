<?php
// Pflegt die Ausblend-Liste ("als falsch markiert"). Liegt hinter dem
// Login-Gate (Caddy forward_auth), also nur fuer angemeldete Nutzer.
// Reiner Anzeige-Filter: die birds.db und der Pi bleiben unberuehrt.
declare(strict_types=1);
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/standort.php';   // welcher Standort - siehe app/docs/MEHRSTANDORT.md
header('Content-Type: application/json; charset=utf-8');

$host = $_SERVER['HTTP_HOST'] ?? '';
$file = vogel_standort()['verstecken'];

require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/rolle.php';

// Cross-Origin-POSTs abweisen. Fix-Pass S-m11: "Origin: null" (Sandbox-iframe,
// data:-Seite - z. B. von einem Geschwister-Host, der als same-site das
// Lax-Cookie mitbekaeme) gilt nicht mehr als "eigene Seite", ausser der
// Browser bestaetigt per Sec-Fetch-Site same-origin. Fehlt der Origin ganz
// (sehr alte/E-Ink-Browser), bleibt es wie bisher erlaubt.
// JSON wird hier bewusst NICHT verlangt: index.html und versteckt.html senden
// das Falsch-Markieren als application/x-www-form-urlencoded (fetch, same-origin)
// - ein JSON-Zwang wuerde es brechen. Die Herkunftspruefung reicht dafuer.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !vogel_post_herkunft_ok()) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'origin']); exit;
}

function laden(string $f): array {
    $d = ['arten' => [], 'aufnahmen' => []];
    if (is_readable($f)) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j)) {
            $d['arten']     = array_values(array_unique(array_map('strval', $j['arten'] ?? [])));
            $d['aufnahmen'] = array_values(array_unique(array_map('strval', $j['aufnahmen'] ?? [])));
        }
    }
    return $d;
}
function speichern(string $f, array $d): bool {
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) === false) return false;
    return @rename($tmp, $f);
}

$data   = laden($file);
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && ($action === '' || $action === 'liste')) {
    echo json_encode(['ok'=>true, 'versteckt'=>$data]); exit;
}
if ($method !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST noetig']); exit;
}
// P3 (23.10.2026): Aendern nur Rolle familie - die Demo sieht, markiert aber nichts (403).
vogel_aendern_pruefen();

$sci  = trim((string)($_POST['sci']  ?? $_GET['sci']  ?? ''));
$datei = trim((string)($_POST['file'] ?? $_GET['file'] ?? ''));

switch ($action) {
    case 'art_verstecken':
        if ($sci === '' || strlen($sci) > 200) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'sci']); exit; }
        if (!in_array($sci, $data['arten'], true)) $data['arten'][] = $sci;
        break;
    case 'art_zeigen':
        $data['arten'] = array_values(array_filter($data['arten'], static function($x) use ($sci){ return $x !== $sci; }));
        break;
    case 'aufnahme_verstecken':
        if ($datei === '' || strlen($datei) > 400) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'file']); exit; }
        if (!in_array($datei, $data['aufnahmen'], true)) $data['aufnahmen'][] = $datei;
        break;
    case 'aufnahme_zeigen':
        $data['aufnahmen'] = array_values(array_filter($data['aufnahmen'], static function($x) use ($datei){ return $x !== $datei; }));
        break;
    default:
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'unbekannte aktion']); exit;
}

if (!speichern($file, $data)) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'schreiben fehlgeschlagen']); exit;
}
echo json_encode(['ok'=>true, 'versteckt'=>$data]);
