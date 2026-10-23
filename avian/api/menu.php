<?php
// AvianVisitors - drawer menu items.
//
// Returns the list of links shown in the side drawer when a user clicks
// the menu button. The live JS expects {items: [{label, href, native}]}.
//
// TAUBEN & CO (P3, 23.10.2026): KEIN zweites Passwort mehr. Wer am Anmeldetor
// vorbei ist (Caddy forward_auth, Cookie vogel_sitzung), bekommt das ganze
// Menue sofort mit 200 - die Schlossform in index.html erscheint dadurch nie
// (apt.js zeigt sie nur bei 401). Ein Basic-Kopf von einer alten, noch
// zwischengespeicherten apt.js wird einfach ignoriert.
//
// Zusaetzlich:
//   * rolle / nur_ansehen: Rolle der Sitzung (familie | demo, siehe
//     /srv/avian/rolle.php). Demo bekommt oben einen Hinweis-Eintrag; das
//     Aendern verweigern die Endpunkte selbst (403).
//   * In der App stehen oben Berichte, Post und Einstellungen als Deep Links
//     (taubenundco://...), weil der untere "mehr"-Knopf der App wegfaellt.
//     ABER nur, wenn die App das ausdruecklich ankuendigt: Faehigkeits-Kennung
//     "menue2" im Klammerteil des User-Agents, z. B.
//         TaubenApp/1.1 (ios; menue2)
//     Ausgelieferte Apps (TaubenApp/1.0 (ios|android)) reagieren im WebView
//     nur auf taubenundco://live und verwerfen den Rest still - dort waeren
//     die Eintraege tot (Fix-Pass R-M2). Kennung statt Versionsvergleich:
//     eine App-Version, die die Ziele noch nicht kann, schickt sie einfach nicht.
//     native:true -> kein target="_blank"; der WebView faengt das Schema ab.
//     Schnittstelle: app/server/politur-3/SCHNITTSTELLE-APP.md
declare(strict_types=1);
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/standort.php';   // welcher Standort - siehe app/docs/MEHRSTANDORT.md
require_once (getenv('APP_WURZEL') ?: '/srv') . '/avian/rolle.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Faehigkeits-Kennung, mit der eine App die drei Menue-Ziele ankuendigt.
const APP_KENNUNG_MENUE = 'menue2';

// Count of instant (chroma) cutouts awaiting the full-quality upgrade
// pass - drives the notification dot on the menu button and the
// settings entry. See generate.php / generate_one.py.
$cuts = dirname(__DIR__) . '/assets/illustrations/cuts.json';
$chroma = 0;
if (is_readable($cuts)) {
    $j = json_decode((string)file_get_contents($cuts), true);
    if (is_array($j)) {
        foreach ($j as $kind) { if ($kind === 'chroma') $chroma++; }
    }
}

$rolle = vogel_rolle();                    // 'familie' | 'demo' | ''
$nurAnsehen = $rolle !== 'familie';        // '' (nicht erkannt) wie demo behandeln
$englisch = (vogel_standort()['ton'] ?? '') === 'englisch';

/**
 * Faehigkeiten der App aus dem User-Agent: "TaubenApp/1.1 (ios; menue2)"
 * -> ['ios', 'menue2']. Kein TaubenApp-Teil -> null (Browser).
 */
function app_faehigkeiten(string $ua): ?array {
    if (!preg_match('#\bTaubenApp/\d{1,3}(?:\.\d{1,3}){0,2}\b(?:\s*\(([^)]{0,200})\))?#', $ua, $m)) return null;
    $teile = array_map('trim', explode(';', strtolower($m[1] ?? '')));
    return array_values(array_filter($teile, 'strlen'));
}
$faehig = app_faehigkeiten((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$inApp = $faehig !== null;
$deepLinks = $inApp && in_array(APP_KENNUNG_MENUE, $faehig, true);

$items = [];

if ($nurAnsehen) {
    // Kein Link im eigentlichen Sinn: verwaltung.js macht daraus eine Notiz.
    // Ohne verwaltung.js bleibt es ein harmloser Anker auf die Startseite.
    $items[] = [
        'label'   => $rolle === 'demo'
            ? ($englisch ? 'Demo – view only' : 'Demo – nur ansehen')
            : ($englisch ? 'View only – please sign in again' : 'Nur ansehen – bitte neu anmelden'),
        'href'    => '#nur-ansehen',
        'native'  => true,
        'hinweis' => true,
    ];
}

if ($deepLinks) {
    $items[] = ['label' => $englisch ? 'Reports' : 'Berichte',  'href' => 'taubenundco://berichte',      'native' => true, 'app' => true];
    $items[] = ['label' => $englisch ? 'Messages' : 'Post',     'href' => 'taubenundco://post',          'native' => true, 'app' => true];
    $items[] = ['label' => $englisch ? 'App settings' : 'Einstellungen', 'href' => 'taubenundco://einstellungen', 'native' => true, 'app' => true];
}

// All four items are in-app overlays. `native: true` tells the FE to
// route via `#admin=<section>` rather than opening a new window. We
// deliberately don't link out to BirdNET-Pi's stock pages - those stay
// reachable at /index.php, and the github link lives in the drawer
// footer next to "built by teddy".
$items[] = ['label' => 'settings', 'href' => '/#admin=settings', 'native' => true, 'dot' => $chroma > 0];
$items[] = ['label' => 'system',   'href' => '/#admin=system',   'native' => true];
$items[] = ['label' => 'logs',     'href' => '/#admin=logs',     'native' => true];
$items[] = ['label' => 'tools',    'href' => '/#admin=tools',    'native' => true];

echo json_encode([
    'items'       => $items,
    'chroma'      => $chroma,
    'rolle'       => $rolle === '' ? 'unbekannt' : $rolle,
    'nur_ansehen' => $nurAnsehen,
    'app'         => $inApp,
    'deeplinks'   => $deepLinks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
