<?php
// AvianVisitors - JSON facade over BirdNET-Pi's birds.db. Read-only.
// Symlinked into the BirdNET-Pi Caddy site root at /avian/api/.
//
// Endpoints (?action=...):
//   stats       - totals (detections, unique species, today, last hour)
//   lifelist    - every species with first_seen, last_seen, total_count
//   recent      - &hours=N (default 24): species heard in the window
//   rhythm      - &hours=N: minute-by-minute rhythm for the selected window
//   hourly      - species-by-hour ledger for one calendar day
//   species     - &sci=<sci_name>: per-species detail page
//   timeseries  - &days=N: daily detection counts per species
//   firstseen   - every species' earliest detection
//   calendar    - detection totals by calendar date
//
// Default LAN deploy ships without auth. If you've exposed the Pi via
// Cloudflare or a tunnel, add a Caddy `basic_auth` matcher around the
// /avian/api/* path - see avian/forwarding/.

declare(strict_types=1);
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/standort.php';   // welcher Standort - siehe app/docs/MEHRSTANDORT.md
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');

// PHP resolves __DIR__ through symlinks to the realpath. This script
// lives at $HOME/BirdNET-Pi/avian/api/birdnet-api.php (served via the
// ${EXTRACTED}/avian symlink). dirname(..., 2) walks to the BirdNET-Pi
// install root. Works under any username because we never bake the
// home directory in. getenv('HOME') would resolve to /var/lib/caddy
// under PHP-FPM (BirdNET-Pi runs it as the caddy user), so it can't
// be relied on.
$DB_PATH = (vogel_standort()['daten'] . '/birds.db');

if (!file_exists($DB_PATH)) {
    http_response_code(503);
    echo json_encode(['error' => 'birds.db not found']);
    exit;
}

try {
    $db = new SQLite3($DB_PATH, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(2000);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db open failed']);
    exit;
}


// Ausblend-Liste ("als falsch markiert", host-getrennt, von verstecken.php).
$HIDE_PATH = vogel_standort()['verstecken'];
$HIDE = ['arten' => [], 'aufnahmen' => []];
if (is_readable($HIDE_PATH)) {
    $hj = json_decode((string)file_get_contents($HIDE_PATH), true);
    if (is_array($hj)) {
        $HIDE['arten']     = array_flip(array_map('strval', $hj['arten'] ?? []));
        $HIDE['aufnahmen'] = array_flip(array_map('strval', $hj['aufnahmen'] ?? []));
    }
}
// Versteckte Aufnahmen aus Zaehlungen ausschliessen, damit eine Art mit lauter
// versteckten Aufnahmen aus Collage/Listen faellt.
$FILE_EXCL = '';
if (!empty($HIDE['aufnahmen'])) {
    $qs = array_map(function ($f) { return "'" . SQLite3::escapeString((string)$f) . "'"; }, array_keys($HIDE['aufnahmen']));
    $FILE_EXCL = ' AND File_Name NOT IN (' . implode(',', $qs) . ')';
}
function ohne_versteckte_arten(array $rows, array $HIDE): array {
    if (empty($HIDE['arten'])) return $rows;
    return array_values(array_filter($rows, static function ($r) use ($HIDE) {
        return !isset($HIDE['arten'][(string)($r['sci'] ?? '')]);
    }));
}

/** Perchs Werte zu Aufnahmen: [File_Name => p] aus /srv/avian/perch/<standort>.db (nur lesend). */
function perch_werte(array $dateien): array {
    $id = (string)(vogel_standort()['id'] ?? '');
    $pfad = '/srv/avian/perch/' . $id . '.db';
    if ($id === '' || !preg_match('/^[a-z][a-z0-9-]{1,20}$/', $id) || !is_readable($pfad) || !$dateien) return [];
    $aus = [];
    try {
        $pdb = new SQLite3($pfad, SQLITE3_OPEN_READONLY);
        $pdb->busyTimeout(2000);
        foreach (array_chunk(array_values(array_unique(array_filter($dateien))), 400) as $teil) {
            $platz = implode(',', array_fill(0, count($teil), '?'));
            $st = $pdb->prepare("SELECT datei, p FROM pruefung WHERE datei IN ($platz) AND p IS NOT NULL");
            foreach ($teil as $k => $f) $st->bindValue($k + 1, $f, SQLITE3_TEXT);
            $r = $st->execute();
            while ($z = $r->fetchArray(SQLITE3_ASSOC)) $aus[(string)$z['datei']] = round((float)$z['p'], 4);
        }
        $pdb->close();
    } catch (\Throwable $e) {
        return [];
    }
    return $aus;
}

function rows(SQLite3 $db, string $sql, array $bind = []): array {
    $stmt = $db->prepare($sql);
    foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
    $res = $stmt->execute();
    $out = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $out[] = $r;
    return $out;
}
function one(SQLite3 $db, string $sql, array $bind = []) {
    $r = rows($db, $sql, $bind);
    return $r[0] ?? null;
}

// ---- P9 (23.10.2026): englische Artnamen --------------------------------
// ?lang=en tauscht nur die ANZEIGENAMEN (com) gegen englische aus
// /srv/avian/oeffentlich/app/namen-en.json (sci -> en, BirdNET-V2.4-Labels,
// Fallback: wissenschaftlicher Name). Die Datenbank des Pi bleibt deutsch.
// Ohne lang=en (oder mit jedem anderen Wert) laeuft nichts davon an.
function sprache_en(): bool {
    return ($_GET['lang'] ?? '') === 'en';
}
function name_en_fuer(string $sci): string {
    require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/namen_en.php';
    return vogel_name_en($sci);
}
function namen_en_anwenden(array $rows): array {
    if (!sprache_en()) return $rows;
    foreach ($rows as &$r) {
        if (isset($r['sci'])) $r['com'] = name_en_fuer((string)$r['sci']);
    }
    unset($r);
    return $rows;
}

function validIsoDate(string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

// A stats date is always interpreted in the station's SQLite local clock.
// Today ends at the current second; a historical date ends at 23:59:59.
// Returning the same shape from every date-aware action keeps the frontend's
// timeline, lists, ledger, and rhythm on one coherent cutoff.
function dateContext(SQLite3 $db): array {
    $today = one($db, "SELECT DATE('now','localtime') AS d")['d'] ?? date('Y-m-d');
    $asked = $_GET['date'] ?? null;
    if ($asked !== null && (!is_string($asked) || !validIsoDate($asked) || $asked > $today)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad date']);
        exit;
    }
    $date = $asked ?? $today;
    $isToday = $date === $today;
    $anchor = $isToday
        ? (one($db, "SELECT DATETIME('now','localtime') AS t")['t'] ?? ($today.' 23:59:59'))
        : $date.' 23:59:59';
    return ['date' => $date, 'today' => $today, 'is_today' => $isToday, 'anchor' => $anchor];
}

function windowClause(int $hours): string {
    $through = "DATETIME(Date||' '||Time) <= DATETIME(:anchor)";
    if ($hours >= 1000000) return $through;
    return $through." AND DATETIME(Date||' '||Time) > DATETIME(:anchor,'-".$hours." hours')";
}

$action = $_GET['action'] ?? 'stats';

switch ($action) {

    case 'stats': {
        $ctx = dateContext($db);
        $bind = [':anchor' => $ctx['anchor']];
        $total       = (int)(one($db, "SELECT COUNT(*) AS n FROM detections WHERE DATETIME(Date||' '||Time) <= DATETIME(:anchor)", $bind)['n'] ?? 0);
        $species     = (int)(one($db, "SELECT COUNT(DISTINCT Sci_Name) AS n FROM detections WHERE DATETIME(Date||' '||Time) <= DATETIME(:anchor)", $bind)['n'] ?? 0);
        $day         = (int)(one($db, "SELECT COUNT(*) AS n FROM detections WHERE Date = :d", [':d' => $ctx['date']])['n'] ?? 0);
        $daySpec     = (int)(one($db, "SELECT COUNT(DISTINCT Sci_Name) AS n FROM detections WHERE Date = :d", [':d' => $ctx['date']])['n'] ?? 0);
        $lastHour    = (int)(one($db, "SELECT COUNT(*) AS n FROM detections WHERE ".windowClause(1), $bind)['n'] ?? 0);
        $week        = (int)(one($db, "SELECT COUNT(*) AS n FROM detections WHERE ".windowClause(168), $bind)['n'] ?? 0);
        $weekSpec    = (int)(one($db, "SELECT COUNT(DISTINCT Sci_Name) AS n FROM detections WHERE ".windowClause(168), $bind)['n'] ?? 0);
        $first       = one($db, "SELECT MIN(Date) AS d FROM detections WHERE DATETIME(Date||' '||Time) <= DATETIME(:anchor)", $bind);
        echo json_encode([
            'totals'    => ['detections' => $total, 'species' => $species],
            'today'     => ['detections' => $day, 'species' => $daySpec],
            'last_hour' => ['detections' => $lastHour],
            'week'      => ['detections' => $week,  'species' => $weekSpec],
            'started'   => $first['d'] ?? null,
            'date'      => $ctx['date'],
            'station_date' => $ctx['today'],
            'is_today'  => $ctx['is_today'],
            'anchor'    => $ctx['anchor'],
            'as_of'     => date('c'),
        ]);
        break;
    }

    case 'lifelist': {
        // n = total calls (matches the `recent` action's alias so the
        // frontend can read either response interchangeably).
        $rs = rows($db,
          "SELECT Sci_Name AS sci, Com_Name AS com, MIN(Date||' '||Time) AS first_seen, "
        . "       MAX(Date||' '||Time) AS last_seen, COUNT(*) AS n, MAX(Confidence) AS best_conf "
        . "FROM detections WHERE 1=1".$FILE_EXCL." GROUP BY Sci_Name ORDER BY first_seen ASC"
        );
        $rs = ohne_versteckte_arten($rs, $HIDE);
        $rs = namen_en_anwenden($rs);
        echo json_encode(['species' => $rs, 'as_of' => date('c')]);
        break;
    }

    case 'recent': {
        // Cap raised to 1,000,000 hours (~114 years) so the frontend's
        // "ALL" button can turn off the time filter without needing a
        // separate code path.
        $hours = max(1, min(1000000, (int)($_GET['hours'] ?? 24)));
        $ctx = dateContext($db);
        $where = windowClause($hours);
        $bind = [':anchor' => $ctx['anchor']];
        // species-collapsed view: one row per species seen in the window,
        // with the file of its highest-confidence detection inside the window.
        $rs = rows($db,
          "SELECT Sci_Name AS sci, Com_Name AS com, COUNT(*) AS n, MAX(Confidence) AS best_conf, "
        . "       MAX(Date||' '||Time) AS last_seen "
        . "FROM detections "
        . "WHERE $where".$FILE_EXCL." "
        . "GROUP BY Sci_Name ORDER BY last_seen DESC",
          $bind
        );
        $rs = ohne_versteckte_arten($rs, $HIDE);
        $rs = namen_en_anwenden($rs);
        // for each row, attach the file of the top-confidence detection in the window
        foreach ($rs as &$r) {
            $best = one($db,
              "SELECT File_Name AS file, Date AS d, Time AS t, Confidence AS conf "
            . "FROM detections "
            . "WHERE Sci_Name = :sn "
            . "AND $where".$FILE_EXCL." "
            . "ORDER BY Confidence DESC LIMIT 1",
              [':sn' => $r['sci'], ':anchor' => $ctx['anchor']]
            );
            $r['top_file'] = $best['file'] ?? null;
            $r['top_at']   = isset($best['d']) ? ($best['d'].' '.$best['t']) : null;
        }
        echo json_encode([
            'hours' => $hours, 'date' => $ctx['date'], 'station_date' => $ctx['today'],
            'is_today' => $ctx['is_today'], 'anchor' => $ctx['anchor'],
            'species' => $rs, 'as_of' => date('c')
        ]);
        break;
    }

    case 'species': {
        $sci = $_GET['sci'] ?? '';
        if ($sci === '') { http_response_code(400); echo json_encode(['error' => 'sci= required']); break; }
        $limit = max(1, min(1000, (int)($_GET['limit'] ?? 500)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $detections = rows($db,
          "SELECT Date AS d, Time AS t, File_Name AS file, Confidence AS conf "
        . "FROM detections WHERE Sci_Name = :sn ORDER BY Date DESC, Time DESC "
        . "LIMIT ".$limit." OFFSET ".$offset,
          [':sn' => $sci]
        );
        foreach ($detections as &$dref) { $dref['versteckt'] = isset($HIDE['aufnahmen'][(string)($dref['file'] ?? '')]); }
        unset($dref);
        // Zweitmeinung (Perch v2, am Server nachgeprueft): Wahrscheinlichkeit
        // der gemeldeten Art je Aufnahme. Eigene Datenbank, weil birds.db dem
        // Pi gehoert. Fehlt sie oder ein Wert, bleibt 'perch' null.
        $perch = perch_werte(array_map(fn($d) => (string)($d['file'] ?? ''), $detections));
        foreach ($detections as &$dref) { $dref['perch'] = $perch[(string)($dref['file'] ?? '')] ?? null; }
        unset($dref);
        $summary = one($db,
          "SELECT Com_Name AS com, COUNT(*) AS total, MIN(Date||' '||Time) AS first_seen, "
        . "       MAX(Date||' '||Time) AS last_seen, MAX(Confidence) AS best_conf "
        . "FROM detections WHERE Sci_Name = :sn",
          [':sn' => $sci]
        );
        if (sprache_en() && is_array($summary)) $summary['com'] = name_en_fuer($sci);
        echo json_encode([
            'sci' => $sci,
            'summary' => $summary,
            'detections' => $detections,
            'page' => ['limit' => $limit, 'offset' => $offset, 'returned' => count($detections)],
        ]);
        break;
    }

    case 'timeseries': {
        // Aggregated time-bucketed counts for the stats charts.
        //   daily   - last $days days, detections + unique species per day
        //   by_hour - detections grouped by hour of day, last 30 days
        // The frontend backfills missing dates with zero - sparse data days
        // are otherwise dropped by the GROUP BY.
        $days = max(1, min(90, (int)($_GET['days'] ?? 30)));
        $daily = rows($db,
          "SELECT Date AS date, COUNT(*) AS detections, COUNT(DISTINCT Sci_Name) AS species "
        . "FROM detections "
        . "WHERE Date >= DATE('now','localtime','-".($days - 1)." day') "
        . "GROUP BY Date ORDER BY Date"
        );
        $by_hour = rows($db,
          "SELECT CAST(strftime('%H', Time) AS INT) AS hour, COUNT(*) AS detections "
        . "FROM detections "
        . "WHERE Date >= DATE('now','localtime','-30 day') "
        . "GROUP BY hour ORDER BY hour"
        );
        echo json_encode([
            'days'    => $days,
            'daily'   => $daily,
            'by_hour' => $by_hour,
            'as_of'   => date('c'),
        ]);
        break;
    }

    case 'firstseen': {
        // Most recent additions to the life list - first detection per
        // species, sorted by first_seen DESC. Powers the "First Detections"
        // section on the stats view.
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));
        $ctx = dateContext($db);
        $rs = rows($db,
          "SELECT Sci_Name AS sci, Com_Name AS com, MIN(Date||' '||Time) AS first_seen, "
        . "       COUNT(*) AS total "
        . "FROM detections WHERE DATETIME(Date||' '||Time) <= DATETIME(:anchor)".$FILE_EXCL." "
        . "GROUP BY Sci_Name ORDER BY first_seen DESC LIMIT :lim",
          [':anchor' => $ctx['anchor'], ':lim' => $limit]
        );
        $rs = namen_en_anwenden($rs);
        echo json_encode([
            'date' => $ctx['date'], 'station_date' => $ctx['today'],
            'is_today' => $ctx['is_today'], 'species' => $rs, 'as_of' => date('c')
        ]);
        break;
    }

    case 'calendar': {
        // Small date index for the Stats calendar. Counts let the UI mark
        // days with detections without guessing from the currently selected
        // window, while empty days remain selectable and honest.
        $today = one($db, "SELECT DATE('now','localtime') AS d")['d'] ?? date('Y-m-d');
        $rs = rows($db,
          "SELECT Date AS date, COUNT(*) AS detections, "
        . "COUNT(DISTINCT Sci_Name) AS species FROM detections "
        . "WHERE Date <= :today GROUP BY Date ORDER BY Date",
          [':today' => $today]
        );
        echo json_encode([
            'station_date' => $today,
            'first_date' => $rs[0]['date'] ?? null,
            'last_date' => $rs ? $rs[count($rs) - 1]['date'] : null,
            'days' => $rs,
            'as_of' => date('c'),
        ]);
        break;
    }

    case 'rhythm': {
        // Minute-of-day pulse for the stats window. Short windows and 24H show
        // one selected day against the preceding week's average. 7D shows the
        // average daily shape of that seven-day block against the seven days
        // immediately before it. ALL intentionally returns the selected day:
        // the global date pager can then walk the station history day by day.
        $days = max(1, min(30, (int)($_GET['days'] ?? 7)));
        $hours = max(1, min(1000000, (int)($_GET['hours'] ?? 24)));
        $ctx = dateContext($db);
        // Slot 0..1439 = hour*60 + minute.
        $slot = "(CAST(strftime('%H', Time) AS INT) * 60 "
              . "+ CAST(strftime('%M', Time) AS INT))";
        $day = $ctx['date'];
        $isToday = $ctx['is_today'];
        $mode = $hours === 168 ? 'week' : ($hours >= 1000000 ? 'all-day' : 'day');
        if ($mode === 'week') {
            $today = rows($db,
              "SELECT $slot AS slot, ROUND(COUNT(*) * 1.0 / 7, 2) AS detections "
            . "FROM detections WHERE DATETIME(Date||' '||Time) > DATETIME(:anchor,'-168 hours') "
            . "AND DATETIME(Date||' '||Time) <= DATETIME(:anchor) GROUP BY slot ORDER BY slot",
              [':anchor' => $ctx['anchor']]
            );
            $avg = rows($db,
              "SELECT $slot AS slot, ROUND(COUNT(*) * 1.0 / 7, 2) AS avg "
            . "FROM detections WHERE DATETIME(Date||' '||Time) > DATETIME(:anchor,'-336 hours') "
            . "AND DATETIME(Date||' '||Time) <= DATETIME(:anchor,'-168 hours') GROUP BY slot ORDER BY slot",
              [':anchor' => $ctx['anchor']]
            );
        } else {
            $today = rows($db,
              "SELECT $slot AS slot, COUNT(*) AS detections "
            . "FROM detections WHERE Date = :d GROUP BY slot ORDER BY slot",
              [':d' => $day]
            );
            $avg = rows($db,
              "SELECT $slot AS slot, ROUND(COUNT(*) * 1.0 / ".$days.", 2) AS avg "
            . "FROM detections WHERE Date >= DATE(:d,'-".$days." day') AND Date < :d "
            . "GROUP BY slot ORDER BY slot",
              [':d' => $day]
            );
        }
        // The station's own current slot: the frontend draws the live day's
        // line only up to it. A past day is complete, so its line runs the
        // whole way.
        $nb = one($db, "SELECT (CAST(strftime('%H','now','localtime') AS INT) * 60 "
                     . "+ CAST(strftime('%M','now','localtime') AS INT)) AS s");
        $nowSlot = ($isToday && $mode !== 'week') ? (int)($nb['s'] ?? 1439) : 1439;
        $rangeStart = $hours <= 1 ? max(0, $nowSlot - 59)
                    : ($hours <= 12 ? max(0, $nowSlot - 719) : 0);
        $rangeEnd = $hours <= 12 ? $nowSlot : 1439;
        echo json_encode([
            'days'     => $days,
            'hours'    => $hours,
            'mode'     => $mode,
            'date'     => $day,
            'station_date' => $ctx['today'],
            'is_today' => $isToday,
            'slots'    => 1440,
            'today'    => $today,
            'avg'      => $avg,
            'now_slot' => $nowSlot,
            'now_hour' => intdiv($nowSlot, 60),
            'range_start_slot' => $rangeStart,
            'range_end_slot' => $rangeEnd,
            'as_of'    => date('c'),
        ]);
        break;
    }

    case 'hourly': {
        // Species-by-hour ledger for one calendar day (the station's local
        // date): the day's top species, each with per-hour detection counts.
        // Hours with no rows are absent - the frontend backfills zeros, same
        // convention as timeseries. Powers the hourly tab on the stats view.
        // "Today" comes from SQLite's localtime, the same clock the rows
        // were written against and the same one the rhythm query uses.
        // PHP's date() follows date.timezone, which on a stock Pi is UTC:
        // taking today from there puts a US station a day ahead every
        // evening and the ledger reads empty.
        $ctx = dateContext($db);
        $today = $ctx['today'];
        $date = $ctx['date'];
        $limit = max(1, min(30, (int)($_GET['limit'] ?? 15)));
        $rs = rows($db,
          "WITH top AS ( "
        . "  SELECT Sci_Name FROM detections WHERE Date = :d "
        . "  GROUP BY Sci_Name ORDER BY COUNT(*) DESC, Sci_Name ASC LIMIT :lim "
        . ") "
        . "SELECT d.Sci_Name AS sci, d.Com_Name AS com, "
        . "       CAST(strftime('%H', d.Time) AS INT) AS hour, COUNT(*) AS n "
        . "FROM detections d JOIN top ON top.Sci_Name = d.Sci_Name "
        . "WHERE d.Date = :d GROUP BY d.Sci_Name, hour",
          [':d' => $date, ':lim' => $limit]
        );
        $rs = namen_en_anwenden($rs);
        // Pivot to one entry per species, ordered by the day's total.
        $species = [];
        foreach ($rs as $r) {
            $sci = $r['sci'];
            if (!isset($species[$sci])) {
                $species[$sci] = ['sci' => $sci, 'com' => $r['com'], 'total' => 0, 'hours' => []];
            }
            $species[$sci]['hours'][] = ['hour' => (int)$r['hour'], 'n' => (int)$r['n']];
            $species[$sci]['total'] += (int)$r['n'];
        }
        $species = array_values($species);
        usort($species, function ($a, $b) {
            return $b['total'] <=> $a['total'] ?: strcmp($a['sci'], $b['sci']);
        });
        echo json_encode([
            'date'    => $date,
            'station_date' => $today,
            'is_today' => $date === $today,
            'anchor_hour' => $date === $today
                ? (int)(one($db, "SELECT CAST(strftime('%H','now','localtime') AS INT) AS h")['h'] ?? 23)
                : 23,
            'species' => $species,
            'as_of'   => date('c'),
        ]);
        break;
    }

    default:
        http_response_code(404);
        echo json_encode(['error' => 'unknown action']);
}
