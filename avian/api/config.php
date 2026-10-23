<?php
// AvianVisitors - read/write a small, whitelisted subset of BirdNET-Pi
// settings from the admin overlay's settings panel. Fetched by the
// frontend at /avian/api/config.php.
//
// Endpoints:
//   GET  -> returns current values as JSON.
//   POST -> JSON body with any whitelisted key. Writes through to
//           birdnet.conf and restarts birdnet_analysis + birdnet_recording
//           so the changes take effect immediately.
//
// TAUBEN & CO (P3, 23.10.2026) - ein Login genuegt:
//   * GET darf jede angemeldete Rolle. Demo bekommt KEINE Koordinaten und
//     keinen Ortsnamen (LATITUDE, LONGITUDE, SITE_NAME fehlen in 'values').
//   * POST nur Rolle familie (sonst 403, nie 401).
//   * Erkennung (CONFIDENCE, SENSITIVITY, OVERLAP, SF_THRESH) und Standort
//     (LATITUDE, LONGITUDE, SITE_NAME) aendern verlangt den Kopf
//     X-Vogel-Bestaetigt: 1 - den setzt /eigenes/verwaltung.js erst, nachdem
//     im Bestaetigungsdialog "Aendern" gedrueckt wurde. Fehlt er: 428.
//     (Keine Sicherheitsgrenze - die ist die Rolle -, sondern ein Netz gegen
//     versehentliches Verstellen, auch durch eine alte, zwischengespeicherte
//     Oberflaeche ohne Dialog.)
//   * Vor jeder solchen Aenderung merkt sich der Server die vorigen Werte
//     (einstellungen/rueckgaengig-<standort>.json, gemeinsam mit Webseite und
//     App - /srv/avian/rueckgaengig.php, Fix-Pass R-M3). Ein Klick stellt sie
//     wieder her:
//       GET  ?action=rueckgaengig  -> {ok, letzte:{zeit, vorher, nachher, rueckgaengig, von}|null}
//       POST ?action=rueckgaengig  -> setzt 'vorher' (gleicher Weg wie ein
//                                     normales Speichern, auch bestaetigt) -
//                                     NUR wenn die aktuellen Werte noch die
//                                     'nachher'-Werte sind, sonst 409.
//   * Werte, die am Server gespeichert werden muessten (alles ausser den vier
//     Pi-Werten, z. B. LATITUDE/LONGITUDE/SITE_NAME), gehen nur, wenn das
//     Hilfsprogramm dafuer installiert ist - sonst 409 VOR jeder Aenderung,
//     auch vor dem Pi-Wunsch (keine Teilaenderung, Fix-Pass S-m8).
//
// Direct requests on the station's private address are available without a
// password. Forwarded and public-host requests verify BirdNET-Pi's configured
// admin password here. (Upstream-Text; gilt hier nicht mehr, siehe oben.)
//
// Restart requires passwordless sudo for the caddy user that runs
// php-fpm, dropped in place by install_services.sh at
// /etc/sudoers.d/020_avian-admin.

declare(strict_types=1);
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/standort.php';   // welcher Standort - siehe app/docs/MEHRSTANDORT.md
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/admin-auth.php';
avian_require_login();          // Lesen: jede angemeldete Rolle; Schreiben siehe POST
// Erkennungswerte gehoeren dem Pi, nicht dieser Kopie - siehe unten (PI_SCHLUESSEL).
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/eigenes/app/_einstellungen.php';
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/rueckgaengig.php';   // gemeinsamer Rueckgaengig-Speicher

// Path layout: /home/{USER}/BirdNET-Pi/avian/api/config.php
$BIRDNETPI_DIR = dirname(__DIR__, 2);
$CONF_PATH     = vogel_standort()['conf'];
$ADMIN_CONTROL = getenv('AV_ADMIN_CONTROL') ?: '/usr/local/sbin/avian-admin-control';

// Whitelist: { config_key => { type, min?, max?, restart? } }
$ALLOWED = [
    'CONFIDENCE'         => ['type' => 'float', 'min' => 0.05, 'max' => 0.99, 'restart' => true],
    'SENSITIVITY'        => ['type' => 'float', 'min' => 0.5,  'max' => 1.5,  'restart' => true],
    // Floor matches upstream: 0.0 admits every label in the model and
    // silently disables the range filter for the whole station.
    'SF_THRESH'          => ['type' => 'float', 'min' => 0.0005, 'max' => 0.99, 'restart' => true],
    'OVERLAP'            => ['type' => 'float', 'min' => 0.0,  'max' => 2.5,  'restart' => true],
    'MAX_FILES_SPECIES'  => ['type' => 'int',   'min' => 0,    'max' => 100000],
    'FULL_DISK'          => ['type' => 'enum',  'values' => ['purge', 'keep']],
    'PURGE_THRESHOLD'    => ['type' => 'int',   'min' => 50,   'max' => 99],
    'LATITUDE'           => ['type' => 'float', 'min' => -90,  'max' => 90, 'restart' => true],
    'LONGITUDE'          => ['type' => 'float', 'min' => -180, 'max' => 180, 'restart' => true],
    'SITE_NAME'          => ['type' => 'string', 'maxlen' => 60],
    // Secrets: writable like any setting, but NEVER echoed back on GET -
    // the response carries only a set/unset flag. Consumed by the
    // illustration pipeline (generate.php passes them by env).
    'GEMINI_API_KEY'     => ['type' => 'secret', 'maxlen' => 200],
    'EBIRD_API_KEY'      => ['type' => 'secret', 'maxlen' => 120],
];

// Diese vier stellt der PI ein. Die birdnet.conf hier auf dem Server ist nur
// eine Kopie, die BirdNET nie liest - frueher zeigte das Menue deshalb 0,75,
// waehrend der Pi mit 0,60 lief, und Speichern kam nie beim Pi an (09.10.2026).
// Wo ein Pi steht, zeigt das Menue jetzt, was der Pi meldet, und schickt
// Aenderungen als Wunsch in den Briefkasten - derselbe gepruefte Weg wie
// eigenes/einstellungen.php (der Pi prueft selbst, bremst, nimmt zurueck).
const PI_SCHLUESSEL = ['CONFIDENCE', 'SENSITIVITY', 'OVERLAP', 'SF_THRESH'];

// Diese Werte aendern nur mit Bestaetigung - und sie lassen sich zuruecknehmen.
const BESTAETIGEN = ['CONFIDENCE', 'SENSITIVITY', 'OVERLAP', 'SF_THRESH',
                     'LATITUDE', 'LONGITUDE', 'SITE_NAME'];
// Diese Werte sieht die Demo nie (Datenschutz: Standort der Station).
const ORT_SCHLUESSEL = ['LATITUDE', 'LONGITUDE', 'SITE_NAME'];


/**
 * Die Werte, die das Menue JETZT zeigt - fuer genau die Schluessel, die sich
 * gleich aendern sollen. Pi-Werte wie im GET (offener Wunsch, sonst Ist-Stand,
 * sonst die Server-Kopie), alles andere aus der Server-Kopie.
 */
function aktuelle_werte(array $schluessel, string $confPfad, array $allowed): array {
    $conf = read_conf($confPfad);
    $pi = array_intersect($schluessel, PI_SCHLUESSEL) ? pi_einstellungen() : null;
    $out = [];
    foreach ($schluessel as $k) {
        $w = null;
        if (in_array($k, PI_SCHLUESSEL, true) && $pi !== null) {
            $w = $pi['wunsch']['werte'][$k] ?? ($pi['ist']['werte'][$k] ?? null);
            if (!is_int($w) && !is_float($w)) $w = null;
        }
        if ($w === null && array_key_exists($k, $conf)) $w = $conf[$k];
        if ($w === null) continue;
        $typ = $allowed[$k]['type'] ?? 'string';
        if ($typ === 'float') $w = (float)$w;
        elseif ($typ === 'int') $w = (int)$w;
        else $w = (string)$w;
        $out[$k] = $w;
    }
    return $out;
}

/** Was der Pi meldet und was noch unterwegs ist - oder null, wenn hier kein Pi steht. */
function pi_einstellungen(): ?array {
    return rk_pi_stand();   // gemeinsam mit Webseite/App (rueckgaengig.php)
}

function read_conf(string $path): array {
    if (!is_readable($path)) return [];
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (!$line || $line[0] === '#') continue;
        if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/i', $line, $m)) {
            $val = trim($m[2]);
            if (strlen($val) >= 2 && $val[0] === '"' && substr($val, -1) === '"') {
                $val = substr($val, 1, -1);
            }
            $out[$m[1]] = $val;
        }
    }
    return $out;
}

function run_admin_control(string $control, array $arguments, ?string $input = null): array {
    if (!is_executable($control)) {
        return ['ok' => false, 'error' => 'admin control is not installed'];
    }
    $command = ['/usr/bin/sudo', '-n', $control];
    foreach ($arguments as $argument) $command[] = (string)$argument;
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['ok' => false, 'error' => 'could not start admin control'];
    }

    $writeOk = true;
    if ($input !== null && $input !== '') {
        $offset = 0;
        $length = strlen($input);
        while ($offset < $length) {
            $written = @fwrite($pipes[0], substr($input, $offset));
            if (!is_int($written) || $written < 1) {
                $writeOk = false;
                break;
            }
            $offset += $written;
        }
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if (!$writeOk) {
        return ['ok' => false, 'error' => 'could not send config to admin control'];
    }

    $decoded = json_decode(is_string($stdout) ? $stdout : '', true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'admin control returned an invalid response'];
    }
    if ($code !== 0 || empty($decoded['ok'])) {
        return ['ok' => false, 'error' => (string)($decoded['error'] ?? 'admin control failed')];
    }
    return $decoded;
}

// Tight allowlist for string fields. The root-owned writer repeats the same
// validation before it touches birdnet.conf.
function safe_string_value(string $v): bool {
    return (bool)preg_match("/^[A-Za-z0-9 _.,'-]*$/u", $v);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$action = (string)($_GET['action'] ?? '');

if ($method === 'GET' && $action === 'rueckgaengig') {
    // Die Demo bekommt nichts davon: vorige Werte koennten Koordinaten sein.
    if (!vogel_darf_aendern()) {
        echo json_encode(['ok' => true, 'letzte' => null, 'nur_ansehen' => true]);
        exit;
    }
    echo json_encode(['ok' => true, 'letzte' => rk_lesen()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET') {
    $conf = read_conf($CONF_PATH);
    $out = [];
    $secrets = [];
    foreach ($ALLOWED as $k => $spec) {
        if ($spec['type'] === 'secret') {
            // Never return the value; the UI only needs to know it's set.
            $secrets[$k] = ($conf[$k] ?? '') !== '';
            continue;
        }
        if (!array_key_exists($k, $conf)) continue;
        $v = $conf[$k];
        if ($spec['type'] === 'float') $v = (float)$v;
        elseif ($spec['type'] === 'int') $v = (int)$v;
        $out[$k] = $v;
    }
    $pi = pi_einstellungen();
    if ($pi !== null) {
        // Angezeigt wird: noch offener Wunsch, sonst was der Pi tatsaechlich benutzt.
        foreach (PI_SCHLUESSEL as $k) {
            $w = $pi['wunsch']['werte'][$k] ?? ($pi['ist']['werte'][$k] ?? null);
            // Meldet der Pi nichts (Tunnel weg), bleibt die Kopie stehen - das
            // Menue sagt dann dazu, dass die Werte unsicher sind ('gemeldet').
            if (is_int($w) || is_float($w)) $out[$k] = (float)$w;
        }
    }
    $darfOrt = vogel_darf_ort_sehen();
    if (!$darfOrt) {
        foreach (ORT_SCHLUESSEL as $k) unset($out[$k]);
    }
    echo json_encode([
        'values'   => $out,
        'rolle'    => vogel_rolle() === '' ? 'unbekannt' : vogel_rolle(),
        'nur_ansehen' => !vogel_darf_aendern(),
        'ort_verborgen' => !$darfOrt,
        'bestaetigen' => BESTAETIGEN,
        'pi'       => $pi === null ? null : [
            'gemeldet' => isset($pi['ist']['zeit']) ? (int)$pi['ist']['zeit'] : null,
            // Der Pi meldet alle 20 s. Laenger als 5 min still: Werte unsicher.
            'frisch'   => isset($pi['ist']['zeit']) && (time() - (float)$pi['ist']['zeit']) < 300,
            'offen'    => $pi['wunsch'] !== null,
            'wartet'   => is_string($pi['ist']['wartet'] ?? null) ? $pi['ist']['wartet'] : null,
        ],
        'secrets'  => $secrets,
        'meta'     => $ALLOWED,
        'preserve' => (int)($conf['MAX_FILES_SPECIES'] ?? 0) >= 10000,
    ]);
    exit;
}

if ($method === 'POST') {
    vogel_aendern_pruefen();        // nur Rolle familie, sonst 403
    avian_require_json_action();
    $istRueckgaengig = ($action === 'rueckgaengig');
    if ($istRueckgaengig) {
        // Ein Klick: die gemerkten vorigen Werte noch einmal speichern - aber
        // nur, wenn seitdem niemand (Webseite, App, Menue) etwas anderes
        // eingestellt hat. Unter derselben Sperre wie jedes Speichern.
        if (!rk_sperren()) {
            http_response_code(503);
            echo json_encode(['error' => 'Rückgängig-Speicher gerade nicht verfügbar'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $letzte = rk_lesen();
        if ($letzte === null) {
            http_response_code(409);
            echo json_encode(['error' => 'Es gibt nichts zurückzunehmen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!rk_passt($letzte, aktuelle_werte(array_keys($letzte['nachher']), $CONF_PATH, $ALLOWED))) {
            http_response_code(409);
            echo json_encode(['error' => 'Seitdem wurde schon wieder etwas geändert – Zurücknehmen geht nicht mehr.',
                              'grund' => 'veraltet'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $body = $letzte['vorher'];
    } else {
        $raw = file_get_contents('php://input');
        $body = json_decode((string)$raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['error' => 'bad json']);
            exit;
        }
    }
    $updates = [];
    $errors = [];
    foreach ($body as $k => $v) {
        // 'preserve' is a UI-side convenience flag handled below - skip it here.
        if ($k === 'preserve') continue;
        if (!isset($ALLOWED[$k])) { $errors[$k] = 'unknown'; continue; }
        $spec = $ALLOWED[$k];
        if ($spec['type'] === 'float') {
            if (!is_int($v) && !is_float($v)) { $errors[$k] = 'not a number'; continue; }
            $v = (float)$v;
            if ($v < ($spec['min'] ?? -INF) || $v > ($spec['max'] ?? INF)) { $errors[$k] = 'out of range'; continue; }
        } elseif ($spec['type'] === 'int') {
            if (!is_int($v)) { $errors[$k] = 'not an integer'; continue; }
            if ($v < ($spec['min'] ?? -PHP_INT_MAX) || $v > ($spec['max'] ?? PHP_INT_MAX)) { $errors[$k] = 'out of range'; continue; }
        } elseif ($spec['type'] === 'enum') {
            if (!is_string($v)) { $errors[$k] = 'invalid value'; continue; }
            if (!in_array($v, $spec['values'], true)) { $errors[$k] = 'invalid value'; continue; }
        } elseif ($spec['type'] === 'string') {
            if (!is_string($v)) { $errors[$k] = 'not a string'; continue; }
            $v = (string)$v;
            if (strlen($v) > ($spec['maxlen'] ?? 200)) { $errors[$k] = 'too long'; continue; }
            // String fields land in birdnet.conf which upstream shell tools
            // source. Keep shell metacharacters out before the root writer
            // repeats the same validation.
            if (!safe_string_value($v)) { $errors[$k] = 'invalid characters'; continue; }
        } elseif ($spec['type'] === 'secret') {
            if (!is_string($v)) { $errors[$k] = 'not a string'; continue; }
            $v = trim((string)$v);
            if (strlen($v) > ($spec['maxlen'] ?? 200)) { $errors[$k] = 'too long'; continue; }
            // API tokens need a wider character set than SITE_NAME, but still
            // stay inside a fixed allowlist. Empty string clears the key.
            if (!preg_match('/^[A-Za-z0-9_\-.\/+=:]*$/', $v)) { $errors[$k] = 'invalid characters'; continue; }
        }
        $updates[$k] = $v;
    }
    if ($errors) {
        http_response_code(400);
        echo json_encode(['error' => 'validation', 'fields' => $errors]);
        exit;
    }

    // Erkennung/Standort nur mit Bestaetigung (siehe Kopf). Gilt auch fuer
    // das Zuruecknehmen - das ist ja wieder eine Aenderung.
    $heikel = array_values(array_intersect(array_keys($updates), BESTAETIGEN));
    if ($heikel && (string)($_SERVER['HTTP_X_VOGEL_BESTAETIGT'] ?? '') !== '1') {
        http_response_code(428);
        echo json_encode(['error' => 'Bitte bestätigen – Seite neu laden, falls kein Dialog kam.',
                          'grund' => 'bestaetigen', 'felder' => $heikel], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Fix-Pass S-m8: Was am Server gespeichert werden muesste, VOR jeder
    // Aenderung pruefen - sonst landete der Pi-Wunsch, und danach scheiterte
    // der Server-Teil mit 500 (Teilaenderung, nichts zum Zuruecknehmen).
    $piDa = array_intersect(array_keys($updates), PI_SCHLUESSEL) && pi_einstellungen() !== null;
    $serverTeil = array_keys($piDa ? array_diff_key($updates, array_flip(PI_SCHLUESSEL)) : $updates);
    if (isset($body['preserve'])) $serverTeil[] = 'MAX_FILES_SPECIES';
    if ($serverTeil && !is_executable($ADMIN_CONTROL)) {
        $ort = array_intersect($serverTeil, ORT_SCHLUESSEL);
        http_response_code(409);
        echo json_encode([
            'error'  => $ort
                ? 'Der Standort lässt sich hier nicht ändern – am Server fehlt das Hilfsprogramm dafür. Nichts geändert.'
                : 'Diese Einstellung lässt sich am Server gerade nicht speichern (Hilfsprogramm fehlt). Nichts geändert.',
            'grund'  => 'server',
            'felder' => array_values(array_unique($serverTeil)),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Vorige Werte JETZT festhalten (vor dem Speichern), nur fuer die heiklen -
    // unter der gemeinsamen Sperre, damit Webseite/App nicht dazwischenkommen.
    if ($heikel) rk_sperren();
    $vorher = $heikel ? aktuelle_werte($heikel, $CONF_PATH, $ALLOWED) : [];

    // Convenience flag: "preserve" toggle in the UI sets a high recording cap.
    // Off restores the installer default (0 = no per-species cap) rather
    // than 50, which would delete recordings a user never asked to lose.
    if (isset($body['preserve'])) {
        if (!is_bool($body['preserve'])) {
            http_response_code(400);
            echo json_encode(['error' => 'validation', 'fields' => ['preserve' => 'not a boolean']]);
            exit;
        }
        $updates['MAX_FILES_SPECIES'] = $body['preserve'] ? 99999 : 0;
    }

    // Erkennungswerte an den Pi statt in die Kopie. Immer alle vier schicken
    // (offener Wunsch bzw. Ist-Stand, darueber die neuen) - sonst wuerde ein
    // zweiter Regler den noch nicht abgeholten ersten Wunsch ueberschreiben.
    $anPi = null;
    $pi = array_intersect_key($updates, array_flip(PI_SCHLUESSEL)) ? pi_einstellungen() : null;
    if ($pi !== null) {
        $basis = $pi['wunsch']['werte'] ?? ($pi['ist']['werte'] ?? []);
        $wunsch = [];
        foreach (PI_SCHLUESSEL as $k) {
            if (array_key_exists($k, $updates)) $wunsch[$k] = $updates[$k];
            elseif (is_int($basis[$k] ?? null) || is_float($basis[$k] ?? null)) $wunsch[$k] = $basis[$k];
            unset($updates[$k]);
        }
        [$werte, $grund] = einst_pruefen($wunsch);
        if ($werte === null) {
            http_response_code(400);
            echo json_encode(['error' => $grund]);
            exit;
        }
        if (!takt_pruefen(hash('sha256', 'einst|' . vogel_sitzung_kennung()), 'einstsetzen', 6)) {
            http_response_code(429);
            echo json_encode(['error' => 'Zu viele Änderungen - bitte eine Minute warten']);
            exit;
        }
        [$gut, $id] = einst_wunsch_ablegen($werte, 'menue');
        if (!$gut) {
            http_response_code(503);
            echo json_encode(['error' => $id]);
            exit;
        }
        $anPi = ['id' => $id, 'werte' => $werte];
    }

    if ($updates) {
        $payload = '';
        foreach ($updates as $key => $value) {
            $payload .= $key . "\0" . (string)$value . "\0";
        }
        $result = run_admin_control(
            $ADMIN_CONTROL,
            ['config-set-stdin', (string)count($updates)],
            $payload
        );
        if (empty($result['ok'])) {
            http_response_code(500);
            echo json_encode(['error' => $result['error'] ?? 'config write failed']);
            exit;
        }
    }

    // Restart services if any setting requires it.
    $needsRestart = false;
    foreach (array_keys($updates) as $k) {
        if (!empty($ALLOWED[$k]['restart'])) { $needsRestart = true; break; }
    }
    $restarted = [];
    if ($needsRestart) {
        foreach (['birdnet_analysis', 'birdnet_recording'] as $svc) {
            $result = run_admin_control($ADMIN_CONTROL, ['restart', $svc]);
            $restarted[$svc] = !empty($result['ok']);
        }
        if (in_array(false, $restarted, true)) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => 'settings saved, but a BirdNET service did not restart',
                'restarted' => $restarted,
            ]);
            exit;
        }
    }
    // Don't echo secret values back; report which keys changed only.
    $shown = [];
    foreach ($updates as $k => $v) {
        $shown[$k] = (($ALLOWED[$k]['type'] ?? '') === 'secret') ? '(saved)' : $v;
    }
    if ($anPi !== null) $shown += $anPi['werte'];

    // Rueckgaengig merken: was vorher galt und was jetzt gewuenscht ist. Nur
    // Werte, die sich wirklich aendern - sonst gaebe es nichts zurueckzunehmen.
    $merk = null;
    if ($heikel) {
        $nachher = [];
        foreach ($heikel as $k) {
            $neu = $anPi['werte'][$k] ?? ($updates[$k] ?? null);
            if ($neu !== null) $nachher[$k] = $neu;
        }
        $merk = rk_merken($vorher, $nachher, $istRueckgaengig, 'menue');
    }
    echo json_encode(['ok' => true, 'updates' => $shown, 'restarted' => $restarted, 'an_pi' => $anPi,
                      'rueckgaengig' => $merk], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
