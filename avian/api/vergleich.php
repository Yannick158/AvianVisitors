<?php
// Vergleichsaufnahmen von xeno-canto: hoert sich der erkannte Vogel wirklich so an?
//
// WARUM ALLES UEBER DEN SERVER LAEUFT:
// Die Sicherheitsrichtlinie erlaubt dem Browser nur eigene Medien
// (media-src 'self'). Fremdes Audio waere gesperrt. Der Server holt es also,
// legt es ab und liefert es selbst aus - genauso wie bei den Vogelfotos.
//
//   ?sci=Motacilla+cinerea            -> JSON: Liste der Vergleichsaufnahmen
//   ?sci=...&nr=0                     -> die Aufnahme selbst (mp3)
//   ?sci=...&nr=0&spek=1              -> Spektrogramm der Vergleichsaufnahme
//   ?eigene=<Dateiname>&spek=1        -> Spektrogramm UNSERER Aufnahme
//
// Beide Spektrogramme entstehen mit derselben ffmpeg-Darstellung. Haetten wir
// unser Bild gegen xeno-cantos Sonagramm gehalten, haette man die Darstellung
// verglichen statt den Vogel.
declare(strict_types=1);
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/standort.php';   // welcher Standort - siehe app/docs/MEHRSTANDORT.md

define('PUFFER', (getenv('APP_WURZEL') ?: '/srv') . '/avian/vergleich');   // Testlauf: Abbild
const SCHLUESSEL = '/srv/avian/wochenschau/.xenocanto-key';
define('EIGENE', vogel_standort()['daten'] . '/Extracted');
const MAX_TREFFER = 8;
const TAGE      = 60;

function raus_json($d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=3600');
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Nur xeno-canto - kein beliebiger Server, den jemand in die Liste schmuggelt.
function quelle_erlaubt(string $url): bool {
    foreach (['https://xeno-canto.org/', 'https://www.xeno-canto.org/'] as $ok) {
        if (stripos($url, $ok) === 0) return true;
    }
    return false;
}

function holen(string $url, int $timeout = 45): ?string {
    if (!quelle_erlaubt($url)) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => 'AvianVisitors/1.0 (privates Vogelprojekt)',
        // Auch nach einer Umleitung darf es nur xeno-canto sein
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ende = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    if ($out === false || $code !== 200 || !quelle_erlaubt($ende)) return null;
    return (string)$out;
}

function spektrogramm(string $audio, string $ziel): bool {
    // Erste 10 Sekunden reichen zum Vergleichen und halten es schnell.
    // Vor dem Zeichnen die Lautstaerke angleichen. Unsere Gartenaufnahmen sind
    // deutlich leiser als die Archivaufnahmen; ohne Angleich vergleicht man den
    // Aufnahmepegel statt den Vogel. Der Hochpass raeumt zusaetzlich das
    // Netzbrummen unter 180 Hz weg, das in unseren Aufnahmen als durchgehender
    // Balken am unteren Rand steht und das Bild dominiert.
    // dynaudnorm hob das Rauschen genauso mit an wie die Rufe - das Bild war
    // dann flaechig rot. Stattdessen nur der Hochpass gegen das Netzbrummen
    // (durchgehender Balken am unteren Rand unserer Aufnahmen) und eine
    // hoehere Empfindlichkeit im Spektrogramm selbst. Dass unsere Aufnahmen
    // leiser sind als Archivaufnahmen, bleibt sichtbar - das ist ehrlich.
    $filter = 'highpass=f=180,'
            . 'showspectrumpic=s=900x260:legend=0:color=intensity:scale=log:gain=4';
    $b = escapeshellarg($audio); $z = escapeshellarg($ziel);
    $befehl = "ffmpeg -y -v error -t 10 -i $b -lavfi " . escapeshellarg($filter)
            . " $z 2>/dev/null";
    exec($befehl, $_, $code);
    return $code === 0 && is_file($ziel);
}

// ---------------------------------------------------------- eigene Aufnahme
if (isset($_GET['eigene'])) {
    $name = basename((string)$_GET['eigene']);
    if (!preg_match('/^[\w.\-:]+\.(mp3|wav)$/u', $name)) { http_response_code(400); exit; }
    // Nur im Aufnahmeordner suchen, nirgends sonst.
    // Die Aufnahmen liegen drei Ebenen tief:
    //   Extracted/By_Date/<Datum>/<Art>/<Datei>
    // PHPs glob kennt kein rekursives **, deshalb die Tiefen einzeln.
    $treffer = glob(EIGENE . '/*/*/*/' . $name)
            ?: glob(EIGENE . '/*/*/' . $name)
            ?: glob(EIGENE . '/*/' . $name);
    $quelle = $treffer[0] ?? null;
    if ($quelle) {
        $echt = realpath($quelle);
        if ($echt === false || strncmp($echt, realpath(EIGENE) . '/', strlen(realpath(EIGENE)) + 1) !== 0) {
            $quelle = null;
        }
    }
    if (!$quelle && substr($name, -4) === '.mp3') {
        // Ausgeduennt bzw. nie hochgeladen (je Art und Minute bleiben nur die
        // drei sichersten, 10.10.2026): dann die sicherste derselben Minute -
        // wie beim Abspielen (recording.php), statt eines Fehlers im Vergleich.
        require_once __DIR__ . '/_ersatzaufnahme.php';
        [$ersatz] = ersatzaufnahme(EIGENE . '/By_Date', $name, '');
        if ($ersatz !== null) {
            $echt = realpath($ersatz);
            if ($echt !== false && strncmp($echt, realpath(EIGENE) . '/', strlen(realpath(EIGENE)) + 1) === 0) {
                $quelle = $ersatz;
            }
        }
    }
    if (!$quelle) { http_response_code(404); exit; }
    if (!isset($_GET['spek'])) {
        header('Content-Type: audio/mpeg');
        header('Cache-Control: private, max-age=86400');
        readfile($quelle); exit;
    }
    @mkdir(PUFFER . '/eigene', 0775, true);
    // Puffer je Standort.
    $sid  = vogel_standort()['id'];
    $bild = PUFFER . '/eigene/' . sha1("$sid|$name") . '.png';
    if (!is_file($bild)) spektrogramm($quelle, $bild);
    if (!is_file($bild)) { http_response_code(500); exit; }
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=86400');
    readfile($bild); exit;
}

// -------------------------------------------------------- Vergleichsaufnahmen
$sci = trim((string)($_GET['sci'] ?? ''));
if ($sci === '' || strlen($sci) > 120 || !preg_match('/^[\p{L}\p{M}\s.\'()-]+$/u', $sci)) {
    raus_json(['ok' => false, 'error' => 'sci fehlt oder ungueltig'], 400);
}
// Fix-Pass S-m3 (23.10.2026): Nicht-Familie (Demo) darf xeno-canto nur fuer
// Arten anstossen, die an DIESEM Standort gehoert wurden (birds.db) - sonst
// koennte sie mit erfundenen Namen den Puffer fuellen und fremde Abrufe
// ausloesen. Die Familie darf wie bisher alles.
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/rolle.php';
if (!vogel_darf_aendern() && !vogel_art_bekannt($sci)) {
    raus_json(['ok' => false, 'error' => 'Art hier nicht gehoert'], 404);
}
$slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $sci));
$ordner = PUFFER . '/' . $slug;
$listeDatei = $ordner . '/liste.json';

function liste_holen(string $sci): array {
    if (!is_readable(SCHLUESSEL)) return [];
    $key = trim((string)file_get_contents(SCHLUESSEL));
    // Nur Bestqualitaet (q:A). Version 3 versteht kein "q>:B" mehr, nur
    // reine Schlagwoerter. Schlechte Aufnahmen helfen beim
    // Vergleichen nicht, sie verwirren nur.
    $frage = 'sp:"' . $sci . '" q:A';
    $url = 'https://xeno-canto.org/api/3/recordings?query=' . rawurlencode($frage)
         . '&key=' . rawurlencode($key);
    $roh = holen($url, 40);
    if ($roh === null) return [];
    $d = json_decode($roh, true);
    if (!is_array($d) || empty($d['recordings'])) return [];
    $aus = [];
    foreach ($d['recordings'] as $r) {
        $datei = (string)($r['file'] ?? '');
        if ($datei === '' || !quelle_erlaubt($datei)) continue;
        $aus[] = [
            'quelle'  => $datei,
            'id'      => (string)($r['id'] ?? ''),
            'wer'     => (string)($r['rec'] ?? 'unbekannt'),
            'lizenz'  => lizenz_kurz((string)($r['lic'] ?? '')),
            'typ'     => (string)($r['type'] ?? ''),
            'note'    => (string)($r['q'] ?? ''),
            'laenge'  => (string)($r['length'] ?? ''),
            'ort'     => trim(((string)($r['cnt'] ?? ''))),
            'seite'   => 'https://xeno-canto.org/' . (string)($r['id'] ?? ''),
        ];
        if (count($aus) >= MAX_TREFFER) break;
    }
    return $aus;
}

function lizenz_kurz(string $u): string {
    if (preg_match('#creativecommons\.org/licenses/([a-z-]+)/#i', $u, $m)) {
        return 'CC ' . strtoupper(str_replace('-', ' ', $m[1]));
    }
    return $u !== '' ? 'siehe Quelle' : 'unbekannt';
}

@mkdir($ordner, 0775, true);
$liste = null;
if (is_file($listeDatei) && (time() - filemtime($listeDatei)) < TAGE * 86400) {
    $liste = json_decode((string)file_get_contents($listeDatei), true);
}
if (!is_array($liste)) {
    $liste = liste_holen($sci);
    $tmp = $listeDatei . '.' . getmypid();
    file_put_contents($tmp, json_encode($liste, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($tmp, $listeDatei);
}

if (!isset($_GET['nr'])) {
    $aus = [];
    foreach ($liste as $i => $b) {
        $aus[] = ['nr' => $i] + array_diff_key($b, ['quelle' => 1]);
    }
    raus_json(['ok' => true, 'anzahl' => count($aus), 'aufnahmen' => $aus,
               'quelle' => 'xeno-canto.org']);
}

$nr = (int)$_GET['nr'];
if ($nr < 0 || $nr >= count($liste)) { http_response_code(404); exit; }
$audio = $ordner . '/' . $nr . '.mp3';
if (!is_file($audio)) {
    $roh = holen($liste[$nr]['quelle'], 90);
    if ($roh === null || strlen($roh) < 2000) { http_response_code(502); exit; }
    $tmp = $audio . '.' . getmypid();
    file_put_contents($tmp, $roh);
    rename($tmp, $audio);
}

if (isset($_GET['spek'])) {
    $bild = $ordner . '/' . $nr . '.png';
    if (!is_file($bild)) spektrogramm($audio, $bild);
    if (!is_file($bild)) { http_response_code(500); exit; }
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=604800');
    readfile($bild); exit;
}

header('Content-Type: audio/mpeg');
header('Content-Length: ' . (string)filesize($audio));
header('Cache-Control: private, max-age=604800');
readfile($audio);
