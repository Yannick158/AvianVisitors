<?php
// Verwaltet Web-Push-Abos ("Benachrichtigungen"). Hinter dem Login-Gate.
// GET  ?action=info            -> oeffentlicher VAPID-Schluessel
// POST JSON {action:'an', modus:'neu'|'alle', abo:{endpoint,keys:{p256dh,auth}}}
// POST JSON {action:'ab', abo:{endpoint}}
declare(strict_types=1);
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/standort.php';   // welcher Standort - siehe app/docs/MEHRSTANDORT.md
header('Content-Type: application/json; charset=utf-8');

$host  = $_SERVER['HTTP_HOST'] ?? '';
$DATEI = vogel_standort()['abos'];
$PUB   = '/srv/avian/push/vapid_public.txt';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && strtolower($origin) !== 'null') {
    $oh = parse_url($origin, PHP_URL_HOST) ?: '';
    $hh = preg_replace('/:\d+$/', '', $host);
    if ($oh !== '' && strcasecmp($oh, (string)$hh) !== 0) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'origin']); exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (($_GET['action'] ?? '') === 'info') {
        echo json_encode(['ok'=>true, 'schluessel'=>trim((string)@file_get_contents($PUB))]); exit;
    }
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'action']); exit;
}

// P3 (23.10.2026): An-/Abmelden nur Rolle familie - die Demo bekommt keine
// Benachrichtigungen aus fremden Gaerten (403, nie 401).
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/rolle.php';
vogel_aendern_pruefen();

$roh = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($roh)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'json']); exit; }
$action = (string)($roh['action'] ?? '');
$abo    = $roh['abo'] ?? null;
$ep     = is_array($abo) ? (string)($abo['endpoint'] ?? '') : '';
if ($ep === '' || strlen($ep) > 1000 || strpos($ep, 'https://') !== 0) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'endpoint']); exit;
}
$key = hash('sha256', $ep);

$alle = [];
if (is_readable($DATEI)) {
    $j = json_decode((string)file_get_contents($DATEI), true);
    if (is_array($j)) $alle = $j;
}

if ($action === 'an') {
    $modus = (string)($roh['modus'] ?? 'neu');
    if (!in_array($modus, ['neu','alle'], true)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'modus']); exit; }
    $p256 = (string)($abo['keys']['p256dh'] ?? ''); $auth = (string)($abo['keys']['auth'] ?? '');
    if ($p256 === '' || $auth === '' || strlen($p256) > 200 || strlen($auth) > 100
        || !preg_match('#^[A-Za-z0-9_\-]+=*$#D', $p256) || !preg_match('#^[A-Za-z0-9_\-]+=*$#D', $auth)) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'keys']); exit;
    }
    $alle[$key] = ['modus'=>$modus, 'seit'=>date('c'),
        'abo'=>['endpoint'=>$ep, 'keys'=>['p256dh'=>$p256, 'auth'=>$auth]]];
} elseif ($action === 'ab') {
    unset($alle[$key]);
} else {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'action']); exit;
}

$tmp = $DATEI . '.tmp';
if (@file_put_contents($tmp, json_encode($alle, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $DATEI)) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'schreiben']); exit;
}
echo json_encode(['ok'=>true, 'anzahl'=>count($alle)]);
