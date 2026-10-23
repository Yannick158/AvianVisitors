<?php
// Liefert frei lizenzierte Vogelfotos von Wikimedia Commons.
// Der Server holt und puffert sie selbst - der Browser spricht nie mit Wikimedia
// (Datenschutz), und die strenge CSP (img-src 'self') bleibt erfuellt.
//
//   ?sci=Columba+livia            -> JSON-Liste der Bilder (mit Urheber/Lizenz)
//   ?sci=Columba+livia&bild=0     -> das Bild selbst (aus dem Puffer)
declare(strict_types=1);

define('PUFFER', (getenv('APP_WURZEL') ?: '/srv') . '/avian/vogelbilder');   // Testlauf: Abbild
const MAX_BILD = 12;           // hoechstens so viele Fotos je Art
const TAGE     = 30;           // Liste so lange gueltig
const BREITE   = 1600;         // Pixelbreite der geholten Fassung

$sci = trim((string)($_GET['sci'] ?? ''));
if ($sci === '' || strlen($sci) > 120 || !preg_match('/^[\p{L}\p{M}\s.\'()-]+$/u', $sci)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'sci fehlt oder ungueltig']);
    exit;
}
$slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $sci));
$ordner = PUFFER . '/' . $slug;
$listeDatei = $ordner . '/liste.json';

// Fix-Pass S-m3 (23.10.2026): Nicht-Familie (Demo) bekommt Vorhandenes aus dem
// Puffer, stoesst aber neue Abrufe bei iNaturalist/Commons nur fuer Arten an,
// die an diesem Standort gehoert wurden (birds.db). Die Familie wie bisher.
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/standort.php';
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/rolle.php';
$darfHolen = vogel_darf_aendern() || vogel_art_bekannt($sci);

function holen(string $url, int $timeout = 12): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'AvianVisitors/1.0 (Vogelanzeige; privat)',
    ]);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($out !== false && $code === 200) ? (string)$out : null;
}

// Nur echte Fotos: Karten, Verbreitungsgrafiken und Tonbilder aussortieren.
function taugt(string $titel, string $mime): bool {
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) return false;
    $t = strtolower($titel);
    foreach (['map', 'range', 'distribution', 'verbreitung', 'karte', 'spectrogram',
              'sonogram', 'oscillogram', 'chart', 'diagram', 'logo', 'icon',
              'bestand', 'population', 'statist', 'graph', 'briefmarke', 'stamp',
              'coin', 'muenze', 'wappen', 'skelett', 'schaedel', 'skull',
              'phylogen', 'kladogramm', 'tabelle', 'diagramm'] as $bad) {
        if (strpos($t, $bad) !== false) return false;
    }
    return true;
}

function autor_kurz(string $a): string {
    $a = trim(preg_replace('/\s+/', ' ', strip_tags($a)));
    $a = preg_replace('/^\(c\)\s*/i', '', $a);
    $a = preg_split('/,\s*(some|all|no)\s+rights\s+reserved/i', $a)[0];
    $a = preg_split('/,\s*uploaded by/i', $a)[0];
    $a = trim($a, " ,;.");
    return $a !== '' ? mb_substr($a, 0, 60) : 'unbekannt';
}

function quelle_erlaubt(string $url): bool {
    foreach (['https://upload.wikimedia.org/',
              'https://inaturalist-open-data.s3.amazonaws.com/',
              'https://static.inaturalist.org/'] as $ok) {
        if (stripos($url, $ok) === 0) return true;
    }
    return false;
}

// iNaturalist: kuratierte Artfotos, oft besser als eine Commons-Kategorie.
// Nur CC-lizenzierte Bilder - alles ohne freie Lizenz wird verworfen.
function liste_inaturalist(string $sci): array {
    $frei = ['cc0', 'cc-by', 'cc-by-sa', 'cc-by-nc', 'cc-by-nc-sa'];
    $such = holen('https://api.inaturalist.org/v1/taxa?' . http_build_query([
        'q' => $sci, 'rank' => 'species', 'per_page' => '8',
    ]));
    if ($such === null) return [];
    $sj = json_decode($such, true);
    $id = null;
    foreach (($sj['results'] ?? []) as $t) {
        if (strcasecmp((string)($t['name'] ?? ''), $sci) === 0) { $id = (int)$t['id']; break; }
    }
    if (!$id) return [];
    $roh = holen('https://api.inaturalist.org/v1/taxa/' . $id);
    if ($roh === null) return [];
    $j = json_decode($roh, true);
    $t = $j['results'][0] ?? null;
    if (!$t) return [];
    $bilder = [];
    foreach (($t['taxon_photos'] ?? []) as $tp) {
        $p = $tp['photo'] ?? [];
        $liz = (string)($p['license_code'] ?? '');
        if (!in_array($liz, $frei, true)) continue;
        $u = (string)($p['large_url'] ?? $p['medium_url'] ?? $p['url'] ?? '');
        if ($u === '' || !quelle_erlaubt($u)) continue;
        $att = autor_kurz((string)($p['attribution'] ?? ''));
        $bilder[] = [
            'quelle' => $u,
            'titel'  => 'iNaturalist',
            'autor'  => $att,
            'lizenz' => strtoupper(str_replace('-', ' ', $liz)),
            'seite'  => 'https://www.inaturalist.org/photos/' . (string)($p['id'] ?? ''),
        ];
    }
    return $bilder;
}

function liste_holen(string $sci): array {
    $api = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
        'action' => 'query', 'format' => 'json', 'formatversion' => '2',
        'generator' => 'categorymembers',
        'gcmtitle' => 'Category:' . $sci,
        'gcmtype' => 'file', 'gcmlimit' => '40',
        'prop' => 'imageinfo',
        'iiprop' => 'url|mime|extmetadata',
        'iiurlwidth' => (string)BREITE,
    ]);
    $roh = holen($api);
    if ($roh === null) return [];
    $j = json_decode($roh, true);
    $seiten = $j['query']['pages'] ?? [];
    $bilder = [];
    foreach ($seiten as $p) {
        $ii = $p['imageinfo'][0] ?? null;
        if (!$ii) continue;
        $titel = (string)($p['title'] ?? '');
        if (!taugt($titel, (string)($ii['mime'] ?? ''))) continue;
        $quelle = (string)($ii['thumburl'] ?? $ii['url'] ?? '');
        if ($quelle === '' || !quelle_erlaubt($quelle)) continue;
        $meta = $ii['extmetadata'] ?? [];
        $autor = autor_kurz((string)($meta['Artist']['value'] ?? ''));
        $bilder[] = [
            'quelle'  => $quelle,
            'titel'   => preg_replace('/^File:/', '', $titel),
            'autor'   => $autor,
            'lizenz'  => (string)($meta['LicenseShortName']['value'] ?? ''),
            'seite'   => (string)($ii['descriptionurl'] ?? ''),
        ];
        if (count($bilder) >= MAX_BILD) break;
    }
    return $bilder;
}

// --- Liste besorgen (aus dem Puffer, sonst frisch) ---
$liste = null;
if (is_readable($listeDatei) && (time() - filemtime($listeDatei)) < TAGE * 86400) {
    $liste = json_decode((string)file_get_contents($listeDatei), true);
}
if (!is_array($liste) && !$darfHolen) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Art hier nicht gehoert']);
    exit;
}
if (!is_array($liste)) {
    $liste = array_merge(liste_inaturalist($sci), liste_holen($sci));
    $liste = array_slice($liste, 0, MAX_BILD);
    @mkdir($ordner, 0755, true);
    @file_put_contents($listeDatei, json_encode($liste, JSON_UNESCAPED_UNICODE));
}

// --- Einzelnes Bild ausliefern ---
if (isset($_GET['bild'])) {
    $i = (int)$_GET['bild'];
    if ($i < 0 || $i >= count($liste)) { http_response_code(404); exit; }
    $datei = $ordner . '/' . $i . '.jpg';
    if (!is_readable($datei)) {
        if (!$darfHolen) { http_response_code(404); exit; }
        $roh = holen($liste[$i]['quelle'], 25);
        if ($roh === null) { http_response_code(502); exit; }
        @mkdir($ordner, 0755, true);
        @file_put_contents($datei, $roh);
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=604800');
    header('Content-Length: ' . (string)filesize($datei));
    readfile($datei);
    exit;
}

// --- Liste als JSON ---
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=3600');
$aus = [];
foreach ($liste as $i => $b) {
    $aus[] = ['nr' => $i, 'titel' => $b['titel'], 'autor' => $b['autor'],
              'lizenz' => $b['lizenz'], 'seite' => $b['seite']];
}
echo json_encode(['ok' => true, 'anzahl' => count($aus), 'bilder' => $aus], JSON_UNESCAPED_UNICODE);
