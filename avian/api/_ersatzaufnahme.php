<?php
// Ersatz fuer ausgeduennte Aufnahmen (10.10.2026).
//
// Am Server bleiben je Art und Minute nur die drei sichersten Aufnahmen
// (aufnahmen-ausduennen.py); die Erkennungen selbst stehen weiter alle in
// birds.db. Fragt die Seite eine weggeduennte Aufnahme an, spielt sie die
// sicherste noch vorhandene derselben Art aus derselben Minute - statt stumm
// zu bleiben. Nicht ueber das Web erreichbar (steht in keiner Caddy-Liste).
declare(strict_types=1);

/**
 * @param string $byDate  .../Extracted/By_Date
 * @param string $mp3     angefragter Dateiname (*.mp3), bereits gegen die
 *                        Whitelist der aufrufenden Datei geprueft
 * @param string $endung  '' fuer den Ton, '.png' fuer das Spektrogramm
 * @return array{0: ?string, 1: bool}  [Pfad des Ersatzes oder null,
 *         ob die Aufnahme in der Datenbank steht (dann lohnt kein Durchsuchen
 *         aller Tage mehr)]
 */
function ersatzaufnahme(string $byDate, string $mp3, string $endung): array {
    $dbPfad = vogel_standort()['daten'] . '/birds.db';
    if (!is_readable($dbPfad) || !class_exists('SQLite3')) return [null, false];
    try {
        $db = new SQLite3($dbPfad, SQLITE3_OPEN_READONLY);
        $st = $db->prepare('SELECT Sci_Name, Date, Time FROM detections WHERE File_Name = :f LIMIT 1');
        $st->bindValue(':f', $mp3, SQLITE3_TEXT);
        $zeile = $st->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$zeile) { $db->close(); return [null, false]; }
        $st = $db->prepare('SELECT File_Name, Com_Name, Date FROM detections WHERE Sci_Name = :s AND Date = :d '
            . 'AND substr(Time,1,5) = :m ORDER BY Confidence DESC, Time, File_Name LIMIT 60');
        $st->bindValue(':s', $zeile['Sci_Name'], SQLITE3_TEXT);
        $st->bindValue(':d', $zeile['Date'], SQLITE3_TEXT);
        $st->bindValue(':m', substr((string)$zeile['Time'], 0, 5), SQLITE3_TEXT);
        $r = $st->execute();
        $kandidaten = [];
        while ($k = $r->fetchArray(SQLITE3_ASSOC)) $kandidaten[] = $k;
        $db->close();
    } catch (\Throwable $e) {
        return [null, false];
    }
    // Als falsch markierte Aufnahmen nie als Ersatz unterschieben.
    $versteckt = [];
    $vp = vogel_standort()['verstecken'] ?? '';
    if ($vp !== '' && is_readable($vp)) {
        $v = json_decode((string)@file_get_contents($vp), true);
        if (is_array($v['aufnahmen'] ?? null)) $versteckt = array_flip($v['aufnahmen']);
    }
    $tag = (string)$zeile['Date'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tag)) return [null, true];
    foreach ($kandidaten as $k) {
        $p = ersatz_pfad($byDate, $k, $mp3, $versteckt, $endung);
        if ($p !== null) return [$p, true];
    }
    // Ganze Minute weg (aelter als 30 Tage): dann die sicherste noch vorhandene
    // Aufnahme dieser Art ueberhaupt - die bleibt immer erhalten.
    try {
        $db = new SQLite3($dbPfad, SQLITE3_OPEN_READONLY);
        $st = $db->prepare('SELECT File_Name, Com_Name, Date FROM detections WHERE Sci_Name = :s '
            . 'ORDER BY Confidence DESC, Date, Time, File_Name LIMIT 400');
        $st->bindValue(':s', $zeile['Sci_Name'], SQLITE3_TEXT);
        $r = $st->execute();
        while ($k = $r->fetchArray(SQLITE3_ASSOC)) {
            $p = ersatz_pfad($byDate, $k, $mp3, $versteckt, $endung);
            if ($p !== null) { $db->close(); return [$p, true]; }
        }
        $db->close();
    } catch (\Throwable $e) {
        // dann eben keiner
    }
    return [null, true];
}

/** Pfad einer Aufnahme, wenn sie da, nicht falsch markiert und harmlos benannt ist. */
function ersatz_pfad(string $byDate, array $k, string $mp3, array $versteckt, string $endung): ?string {
    $f = (string)$k['File_Name'];
    $tag = (string)($k['Date'] ?? '');
    if ($f === $mp3 || isset($versteckt[$f])) return null;
    if (strpos($f, '/') !== false || strpos($f, '..') !== false) return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tag)) return null;
    // Ordnername wie BirdNET-Pi (common_name_safe)
    $ordner = str_replace(["'", ' '], ['', '_'], (string)$k['Com_Name']);
    if (strpos($ordner, '/') !== false || strpos($ordner, '..') !== false) return null;
    $p = "$byDate/$tag/$ordner/$f$endung";
    return (is_file($p) && filesize($p) >= 64) ? $p : null;
}
