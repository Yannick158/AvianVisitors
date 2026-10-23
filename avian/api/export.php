<?php
// AvianVisitors - bulk data export. Your detections and recordings are
// yours; this makes them easy to take somewhere and do science on.
//
// Endpoints:
//   ?what=detections            -> full detections table as CSV
//   ?what=recordings            -> every extracted clip, one tar stream
//   ?what=recordings&sci=X y    -> one species' clips, one tar stream
//
// Everything streams: the CSV is written row by row off the SQLite
// cursor and the tar comes straight from tar's stdout, so a Zero 2 W
// never holds more than a buffer in memory. mp3 doesn't compress, so
// no gzip. Same auth stance as the rest of avian/api/.
//
// TAUBEN & CO (P3, 23.10.2026): Export ist LESEN - die Familie darf alles,
// ohne zweites Passwort. Die Demo-Rolle (und eine unerkannte Sitzung):
//   * bekommt KEINEN Aufnahmen-Export (Roh-Audio, Fix-Pass S-m4) -> 403,
//   * die Tabelle nur OHNE die Spalten Lat und Lon (Standort der Station),
//   * und die hoechstens einmal je DEMO_CSV_TAKT Sekunden - je STANDORT, fuer
//     alle Demo-Sitzungen gemeinsam (eine neue Sitzung umgeht die Bremse
//     nicht). Fail-closed: laesst sich die Bremse nicht pruefen, gibt es 503.
// Aufnahmen kommen aus dem Ordner DIESES Standorts (vorher: ein fester Pfad
// BirdSongs/Extracted fuer alle Standorte).
//
// TAUBEN & CO (P4, 23.10.2026): "alles als tar" waren im Garten 11 GB / 55 000
// Dateien - fuer die Familie praktisch unbrauchbar. Neu:
//   ?what=recordings&tage=N     nur die Datumsordner der letzten N Tage (1..366,
//                               heute zaehlt mit), Dateiname recordings-Ntage-<Datum>.tar
//   ?what=umfang                JSON {ok, tage:{"7":{dateien,bytes},"30":{...},"alle":{...}}}
//                               fuer die Werkzeuge-Kachel; 10 Minuten zwischengespeichert
//                               unter /srv/avian/einstellungen/export-umfang-<standort>.json.
//                               Nur Familie (Demo 403, wie der tar-Export).
// Das tar nimmt nur noch Datumsordner (JJJJ-MM-TT) mit, auch ohne tage - fremde
// Ordner unter By_Date bleiben draussen.
//
// FIX-PASS P4 (23.10.2026):
//   * Datumsangaben immer in Europe/Berlin (die Pi-Ordner heissen nach Berliner Datum;
//     php.ini am Server steht auf UTC) - export_datum(), nicht date().
//   * ?what=umfang: der Scan laeuft hoechstens einmal gleichzeitig (flock auf eine
//     Sperrdatei). Wer waehrenddessen fragt, bekommt den alten Stand (bis 6 h, "veraltet":
//     true) statt selbst parallel zu scannen. Zwischenspeicher wird atomar ersetzt.
//   * tar: hoechstens ein Lauf gleichzeitig je Standort (flock), sonst 429 mit
//     verstaendlichem Satz - ein zweiter 11-GB-Strom wuerde Platte und Pi-Abgleich bremsen.

declare(strict_types=1);
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/standort.php';   // welcher Standort - siehe app/docs/MEHRSTANDORT.md

require_once __DIR__ . '/admin-auth.php';
avian_require_login();
$mitOrt = vogel_darf_ort_sehen();
const DEMO_CSV_TAKT = 120;   // Sekunden zwischen zwei Demo-CSV-Exporten je Standort

/**
 * Gemeinsame Demo-Bremse je Standort. true = darf. Fail-closed: kann die
 * Datei nicht angelegt/gesperrt werden, false (Antwort 503).
 * Raeumt nebenbei die Merkdateien der alten Fassung (export-takt-<sitzung>) auf.
 */
function demo_export_takt(int $abstand, ?int &$warten = null): ?bool {
    $dir = (getenv('APP_WURZEL') ?: '/srv') . '/avian/einstellungen';
    foreach (glob($dir . '/export-takt-*') ?: [] as $alt) {
        if (strpos(basename($alt), 'export-takt-demo-') === 0) continue;
        if (time() - (int)@filemtime($alt) > 600) @unlink($alt);
    }
    $datei = $dir . '/export-takt-demo-' . vogel_standort()['id'];
    $fh = @fopen($datei, 'c+');
    if ($fh === false) return null;
    if (!@flock($fh, LOCK_EX)) { fclose($fh); return null; }
    $zuletzt = (int)trim((string)stream_get_contents($fh));
    $jetzt = time();
    if ($zuletzt > 0 && $jetzt - $zuletzt < $abstand) {
        $warten = $abstand - ($jetzt - $zuletzt);
        fclose($fh);
        return false;
    }
    ftruncate($fh, 0); rewind($fh);
    $ok = fwrite($fh, (string)$jetzt) !== false;
    fflush($fh);
    fclose($fh);
    return $ok ? true : null;
}

$BIRDNETPI_DIR = dirname(__DIR__, 2);
$DB_PATH   = (vogel_standort()['daten'] . '/birds.db');
// Je Standort: <daten>/Extracted.
$EXTRACTED = vogel_standort()['daten'] . '/Extracted';
$what = $_GET['what'] ?? '';

/** Berliner Zeit, unabhaengig von der PHP-Konfiguration (date.timezone). */
function export_tz(): DateTimeZone {
    static $tz = null;
    if ($tz === null) $tz = new DateTimeZone('Europe/Berlin');
    return $tz;
}
/** Datum (JJJJ-MM-TT) in Berliner Zeit, $zurueck Tage vor heute. */
function export_datum(int $zurueck = 0): string {
    $d = new DateTime('now', export_tz());
    if ($zurueck > 0) $d->modify('-' . $zurueck . ' days');
    return $d->format('Y-m-d');
}
/**
 * Sperre ohne Warten. Rueckgabe: Handle = gehalten (bis zum Skriptende, auch bei Abbruch des
 * Browsers), false = ein anderer Lauf haelt sie, null = Sperrdatei nicht anlegbar (dann ohne
 * Sperre weitermachen - sie schuetzt vor Last, nicht vor Missbrauch).
 */
function export_sperre(string $name) {
    $dir = (getenv('APP_WURZEL') ?: '/srv') . '/avian/einstellungen';
    $fh = @fopen($dir . '/' . $name, 'c');
    if ($fh === false) return null;
    if (@flock($fh, LOCK_EX | LOCK_NB)) return $fh;
    fclose($fh);
    return false;
}

/** Datumsordner unter By_Date (JJJJ-MM-TT), aufsteigend; mit $tage nur die letzten N Tage. */
function export_datumsordner(string $byDate, ?int $tage): array {
    $ab = $tage === null ? '' : export_datum($tage - 1);
    $aus = [];
    foreach (scandir($byDate) ?: [] as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !is_dir("$byDate/$d")) continue;
        if ($ab !== '' && strcmp($d, $ab) < 0) continue;
        $aus[] = $d;
    }
    sort($aus);
    return $aus;
}

/** tage-Parameter: null = alle, sonst 1..366; ungueltig -> 400. */
function export_tage(): ?int {
    if (!isset($_GET['tage']) || $_GET['tage'] === '' || $_GET['tage'] === 'alle') return null;
    $t = filter_var($_GET['tage'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 366]]);
    if ($t === false) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'tage: 1 bis 366']);
        exit;
    }
    return $t;
}

if ($what === 'umfang') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!vogel_darf_aendern()) {
        http_response_code(403);
        echo json_encode(['error' => 'Demo – kein Aufnahmen-Export', 'grund' => 'demo', 'nur_ansehen' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $byDate = "$EXTRACTED/By_Date";
    if (!is_dir($byDate)) { echo json_encode(['ok' => true, 'tage' => ['7' => ['dateien' => 0, 'bytes' => 0],
        '30' => ['dateien' => 0, 'bytes' => 0], 'alle' => ['dateien' => 0, 'bytes' => 0]]]); exit; }
    $dirE = (getenv('APP_WURZEL') ?: '/srv') . '/avian/einstellungen';
    $merk = $dirE . '/export-umfang-' . vogel_standort()['id'] . '.json';
    $lesen = function () use ($merk): ?array {
        $a = @json_decode((string)@file_get_contents($merk), true);
        return (is_array($a) && isset($a['tage'], $a['zeit'])) ? $a : null;
    };
    $frisch = function (?array $a): bool {
        return $a !== null && (int)$a['zeit'] > time() - 600 && ($a['tag'] ?? '') === export_datum();
    };
    $zeigen = function (array $a, bool $veraltet) {
        $stand = (new DateTime('@' . (int)$a['zeit']))->setTimezone(export_tz())->format('c');
        echo json_encode(['ok' => true, 'tage' => $a['tage'], 'stand' => $stand] + ($veraltet ? ['veraltet' => true] : []));
        exit;
    };
    $alt = $lesen();
    if ($frisch($alt)) $zeigen($alt, false);
    // Hoechstens ein Scan gleichzeitig (der Scan geht ueber zehntausende Dateien).
    $sperre = export_sperre('export-umfang-' . vogel_standort()['id'] . '.lock');
    if ($sperre === false) {
        // Ein anderer Lauf scannt gerade: alten Stand (bis 6 h) liefern, sonst kurz auf ihn warten.
        if ($alt !== null && (int)$alt['zeit'] > time() - 6 * 3600) $zeigen($alt, true);
        for ($i = 0; $i < 40 && $sperre === false; $i++) {   // bis ca. 20 s
            usleep(500000);
            $neu = $lesen();
            if ($frisch($neu)) $zeigen($neu, false);
            $sperre = export_sperre('export-umfang-' . vogel_standort()['id'] . '.lock');
        }
        if ($sperre === false) {
            http_response_code(503);
            header('Retry-After: 30');
            echo json_encode(['error' => 'Die Größe wird gerade berechnet. Bitte gleich noch einmal ansehen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    if ($sperre !== null) {   // Sperre gehalten: vielleicht hat der andere Lauf inzwischen fertig gemacht
        $neu = $lesen();
        if ($frisch($neu)) $zeigen($neu, false);
    }
    // Je Datumsordner einmal zaehlen, dann die drei Zeitraeume daraus summieren.
    $je = [];
    foreach (export_datumsordner($byDate, null) as $d) {
        $n = 0; $b = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$byDate/$d", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { if ($f->isFile()) { $n++; $b += $f->getSize(); } }
        $je[$d] = [$n, $b];
    }
    $summe = function (?int $tage) use ($je): array {
        $ab = $tage === null ? '' : export_datum($tage - 1);
        $n = 0; $b = 0;
        foreach ($je as $d => $w) { if ($ab === '' || strcmp($d, $ab) >= 0) { $n += $w[0]; $b += $w[1]; } }
        return ['dateien' => $n, 'bytes' => $b];
    };
    $tage = ['7' => $summe(7), '30' => $summe(30), 'alle' => $summe(null)];
    $neu = ['zeit' => time(), 'tag' => export_datum(), 'tage' => $tage];
    // Atomar ersetzen: wer ohne Sperre liest, sieht nie eine halb geschriebene Datei.
    $tmp = $merk . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($neu)) !== false) { if (!@rename($tmp, $merk)) @unlink($tmp); }
    $zeigen($neu, false);
}

if ($what === 'detections') {
    if (!$mitOrt) {
        $warten = 0;
        $darf = demo_export_takt(DEMO_CSV_TAKT, $warten);
        if ($darf !== true) {
            http_response_code($darf === null ? 503 : 429);
            if ($darf === false) header('Retry-After: ' . $warten);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $darf === null ? 'Export gerade nicht möglich'
                : 'Demo: höchstens ein Export alle ' . intdiv(DEMO_CSV_TAKT, 60) . ' Minuten'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    if (!file_exists($DB_PATH)) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'birds.db not found']);
        exit;
    }
    $db = new SQLite3($DB_PATH, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(2000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="detections-' . export_datum() . '.csv"');
    header('Cache-Control: no-store');
    while (ob_get_level()) ob_end_clean();
    $out = fopen('php://output', 'w');
    $cols = ['Date', 'Time', 'Sci_Name', 'Com_Name', 'Confidence',
             'Lat', 'Lon', 'Cutoff', 'Week', 'Sens', 'Overlap', 'File_Name'];
    if (!$mitOrt) $cols = array_values(array_diff($cols, ['Lat', 'Lon']));
    // Explicit escape='' writes standard RFC-4180 quoting and quiets the
    // PHP 8.4+ deprecation about the changing default.
    fputcsv($out, $cols, ',', '"', '');
    // Row-by-row off the cursor - rows() would buffer a production DB's
    // 100k+ rows into RAM. ASC order reads as a diary and the reverse
    // index scan costs nothing.
    $res = $db->query('SELECT ' . implode(',', $cols) . ' FROM detections ORDER BY Date, Time');
    while ($r = $res->fetchArray(SQLITE3_NUM)) {
        fputcsv($out, $r, ',', '"', '');
    }
    $db->close();
    exit;
}

if ($what === 'recordings') {
    if (!vogel_darf_aendern()) {
        // Demo: kein Roh-Audio als Paket (Fix-Pass S-m4). Einzelne Aufnahmen
        // anhoeren geht weiter ueber recording.php.
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Demo – kein Aufnahmen-Export', 'grund' => 'demo', 'nur_ansehen' => true],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
    $byDate = "$EXTRACTED/By_Date";
    if (!is_dir($byDate)) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'no extracted recordings']);
        exit;
    }
    $sci = trim((string)($_GET['sci'] ?? ''));
    $tage = export_tage();
    $daten = export_datumsordner($byDate, $tage);
    $label = 'recordings' . ($tage === null ? '' : '-' . $tage . 'tage');
    $list = [];
    if ($sci !== '') {
        if (!preg_match("/^[A-Z][a-z-]+ [a-z-]+$/", $sci)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'bad sci name']);
            exit;
        }
        // Resolve the species' on-disk dir name (space->underscore common
        // name) from the DB, then collect its clips from every date dir.
        if (!file_exists($DB_PATH)) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'birds.db not found']);
            exit;
        }
        $db = new SQLite3($DB_PATH, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(2000);
        $st = $db->prepare('SELECT DISTINCT Com_Name FROM detections WHERE Sci_Name = :s');
        $st->bindValue(':s', $sci, SQLITE3_TEXT);
        $rs = $st->execute();
        // Match dirs the way recording.php does - normalized to bare
        // alphanumerics, because BirdNET-Pi isn't consistent about
        // apostrophes in species dir names (Anna's vs Annas).
        $norm = function (string $s): string {
            return preg_replace('/[^a-z0-9]/', '', strtolower($s));
        };
        $want = [];
        while ($r = $rs->fetchArray(SQLITE3_ASSOC)) {
            $want[$norm((string)$r['Com_Name'])] = true;
        }
        $db->close();
        if (!$want) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'species not in your detections']);
            exit;
        }
        foreach ($daten as $date) {
            foreach (scandir("$byDate/$date") ?: [] as $sub) {
                if ($sub === '.' || $sub === '..' || !is_dir("$byDate/$date/$sub")) continue;
                if (isset($want[$norm($sub)])) $list[] = "By_Date/$date/$sub";
            }
        }
        if (!$list) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'no clips on disk for that species']);
            exit;
        }
        $label = strtolower(str_replace(' ', '-', $sci)) . ($tage === null ? '' : '-' . $tage . 'tage');
    } else {
        foreach ($daten as $date) $list[] = "By_Date/$date";
        if (!$list) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'no recordings in that period']);
            exit;
        }
    }

    // Hoechstens ein tar-Lauf gleichzeitig je Standort (die Sperre faellt mit dem Skriptende,
    // auch wenn der Browser abbricht).
    $tarSperre = export_sperre('export-tar-' . vogel_standort()['id'] . '.lock');
    if ($tarSperre === false) {
        http_response_code(429);
        header('Retry-After: 120');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Es wird gerade schon ein Aufnahmen-Paket für diesen Standort zusammengestellt. '
            . 'Bitte in ein paar Minuten noch einmal versuchen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: application/x-tar');
    header('Content-Disposition: attachment; filename="' . $label . '-' . export_datum() . '.tar"');
    header('Cache-Control: no-store');
    while (ob_get_level()) ob_end_clean();
    set_time_limit(0);
    // tar streams from disk to stdout; -C keeps paths tidy relative to
    // Extracted/. Every path in $list was built above from validated
    // parts, and escapeshellarg guards the boundary anyway.
    $args = implode(' ', array_map('escapeshellarg', $list));
    $p = popen('tar -cf - -C ' . escapeshellarg($EXTRACTED) . ' ' . $args, 'r');
    if ($p) {
        fpassthru($p);
        pclose($p);
    }
    exit;
}

http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'what=detections or what=recordings']);
