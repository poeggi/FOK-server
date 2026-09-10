<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Events.php';
require_once __DIR__ . '/../src/Qr.php';

/**
 * The event's poster: one A4 page carrying the QR that opens the event.
 * Print to PDF and that is the export.
 *
 * THIS IS THE ONLY PLACE THE KEY EXISTS OUTSIDE THE DATABASE. No JSON answer
 * this server can produce carries it, for anybody - not the admin API, not
 * the organizer's client. A page behind the login, sent no-store, is where an
 * operator sees it, and paper is where it goes next.
 *
 * It is drawn in the GAME's own typeface (Press Start 2P, the face FOK-snake
 * sets its menus in), because the poster is the first thing a player sees of
 * the event and it should look like the thing it lets them into. The face is
 * used where it reads at a glance - the name, the code, the labels - and not
 * for the description, which is prose and would be punishing in it.
 */
header('Cache-Control: no-store');
Auth::startSession();
Auth::requireLogin();

$eid = (string)($_GET['eid'] ?? '');
if (preg_match('/^[' . Events::ALPHABET . ']{4}$/', $eid) !== 1) {
    http_response_code(400);
    exit('invalid eid');
}
$card = Events::card($eid);
if ($card === null) {
    http_response_code(404);
    exit('unknown event');
}

$url = FOK_GAME_URL . '#event=' . $card['eid'] . '.' . $card['ekey'];
// Level M on paper: a poster gets creased, shadowed and photographed at an
// angle, and the extra correction is what a printed code is for. The encoder
// picks the version and the lowest-penalty mask itself.
$svg = Qr::svg($url, 'M', 0, -1, 8, 2);
$e = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$when = static fn(?int $t): string => $t === null ? '' : gmdate('d.m.Y H:i', $t) . ' UTC';
$asset = '../assets/PressStart2P-Regular.woff2?v=' . FOK_SERVER_VERSION;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($card['name']) ?> - event poster</title>
<style>
/* Deliberately NOT admin.css. This page is a POSTER, not a dashboard tile:
   it is sized in millimetres, it is printed, and it carries the game's look
   rather than the operator's. Its own handful of rules, and no variable that
   only this page would ever use gets added to the shared sheet. */
@font-face {
    font-family: 'Press Start 2P';
    font-display: swap;
    src: url('<?= $e($asset) ?>') format('woff2');
}

/* Two worlds, one sheet. The class on <html> decides, so the CHOICE prints -
   what is on screen is what comes out of the printer. */
:root {
    --ink: #101418;
    --ink-soft: #55606c;
    --paper: #ffffff;
    --line: #d5dae0;
    --accent: #12894b;
    --snake: #7fff7f;
    --apple: #ff5f56;
}
html.dark {
    --ink: #e6edf3;
    --ink-soft: #8b98a5;
    --paper: #0d1117;
    --line: #2a3442;
    --accent: #3ddc84;
    --snake: #7fff7f;
    --apple: #ff5f56;
}

* { box-sizing: border-box; }

html, body { margin: 0; padding: 0; background: #55606c; }

body {
    font: 14px/1.55 system-ui, sans-serif;
    display: flex; flex-direction: column; align-items: center;
    padding: 18px 12px 40px;
    color: var(--ink);
}

/* The sheet IS A4. Everything on it is placed in millimetres, so what the
   screen shows is what the paper gets, at any zoom. */
.sheet {
    width: 210mm; min-height: 297mm;
    padding: 22mm 20mm 18mm;
    background: var(--paper);
    color: var(--ink);
    display: flex; flex-direction: column; align-items: center; text-align: center;
    box-shadow: 0 2px 18px rgba(0,0,0,0.45);
}

.pixel { font-family: 'Press Start 2P', 'Courier New', monospace; }

.titleblock {
    width: 100%; padding: 9mm 6mm 8mm; margin-bottom: 10mm;
    background: #0b0f14; border-radius: 1.5mm;
}
.wordmark {
    font-size: 11.3mm; line-height: 1; color: #7fff7f;
    white-space: pre; text-shadow: 0 0 10.7mm #7fff7f;
}
.edition {
    margin-top: 6.5mm; font-size: 2.83mm; line-height: 1;
    color: #4a7a4a; white-space: pre; text-shadow: 0 0 0.3mm #4a7a4a;
}
h1 {
    margin: 0; font-size: 8mm; line-height: 1.35;
    text-wrap: balance; overflow-wrap: anywhere;
}
/* A long name would push the QR off the page, so it steps down instead. */
h1.long { font-size: 6mm; }
h1.longer { font-size: 4.6mm; }

.descr {
    margin: 7mm auto 0; max-width: 130mm;
    font-size: 4.2mm; line-height: 1.5; color: var(--ink-soft);
}
.when {
    margin: 6mm 0 0; font-size: 3.2mm; letter-spacing: 0.08em;
    color: var(--ink-soft);
}
.when span { white-space: nowrap; }

/* The QR is ALWAYS dark on light, in both modes. An inverted code is a
   gamble on the scanner, and the one job this page has is being scanned - so
   in dark mode it sits on its own lit panel instead, which reads as a screen
   and is the point of the page. */
.qr {
    margin: 11mm auto 0; padding: 5mm;
    background: #fff; border-radius: 2mm;
    width: 96mm; max-width: 100%;
}
html.dark .qr { box-shadow: 0 0 0 0.8mm var(--accent); }
.qr svg { display: block; width: 100%; height: auto; }

.code {
    margin: 7mm 0 0; font-size: 5mm; letter-spacing: 0.06em;
    color: var(--ink); word-break: break-all;
}
.how { margin: 5mm 0 0; font-size: 3.6mm; color: var(--ink-soft); }
.snake { display: block; width: 88mm; max-width: 100%; margin: 9mm auto 0; }

.foot {
    margin-top: auto; padding-top: 8mm; width: 100%;
    border-top: 0.4mm solid var(--line);
    display: flex; justify-content: space-between; gap: 6mm;
    font-size: 2.9mm; letter-spacing: 0.06em; color: var(--ink-soft);
}

/* Screen only: the controls, and the note about what is on this page. */
.bar { margin: 16px 0 0; display: flex; gap: 10px; align-items: center; }
.bar button {
    font: 13px/1 system-ui, sans-serif; padding: 9px 16px; cursor: pointer;
    border: 1px solid #7d8894; border-radius: 6px;
    background: #e9edf1; color: #101418;
}
.bar button:hover { border-color: #12894b; }
.note {
    margin: 10px 0 0; max-width: 210mm; text-align: center;
    font-size: 12px; color: #e9edf1;
}

@media print {
    /* A4 with no browser margin: the sheet owns its own, so the layout on
       paper is the layout on screen. */
    @page { size: A4 portrait; margin: 0; }
    html, body { background: var(--paper); }
    body { padding: 0; display: block; }
    .sheet {
        width: 210mm; min-height: 297mm; box-shadow: none;
        page-break-after: avoid; break-after: avoid;
    }
    .bar, .note { display: none; }
    /* The title block is a filled area in BOTH modes and dark mode is a
       choice, so the printer must honour them rather than silently
       dropping every fill. */
    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>
<?php
$name = (string)$card['name'];
$size = strlen($name) > 34 ? ' longer' : (strlen($name) > 18 ? ' long' : '');
?>
<div class="sheet">
    <div class="titleblock">
        <div class="wordmark pixel">S N A K E</div>
        <div class="edition pixel">F O K   E D I T I O N</div>
    </div>
    <h1 class="pixel<?= $size ?>"><?= $e($name) ?></h1>

    <?php if ($card['descr'] !== ''): ?>
        <p class="descr"><?= $e($card['descr']) ?></p>
    <?php endif; ?>

    <?php if ($card['starts'] !== null || $card['ends'] !== null): ?>
        <p class="when pixel">
            <?php if ($card['starts'] !== null): ?>
                <span>FROM <?= $e($when($card['starts'])) ?></span>
            <?php endif; ?>
            <?php if ($card['ends'] !== null): ?>
                <span>UNTIL <?= $e($when($card['ends'])) ?></span>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <div class="qr"><?= $svg ?></div>
    <p class="code pixel"><?= $e($card['eid'] . '.' . $card['ekey']) ?></p>
    <p class="how">Scan to join. Point any camera at it.</p>

    <!-- The snake as the game draws it (js/render.js drawSnakeG): a
         20-unit grid, 18x18 segments inset by 1 so the cells stay
         separate, the body darkening towards the tail, the head
         brighter and rounder with its two eyes. -->
    <svg class="snake" viewBox="-2 -5 284 108"
         role="img" aria-label="A snake, chasing an apple">
        <g>
            <rect x="1" y="61" width="18" height="18" rx="3" fill="hsl(120,65%,22%)"></rect>
            <rect x="21" y="61" width="18" height="18" rx="3" fill="hsl(120,65%,23%)"></rect>
            <rect x="41" y="61" width="18" height="18" rx="3" fill="hsl(120,65%,24%)"></rect>
            <rect x="61" y="61" width="18" height="18" rx="3" fill="hsl(120,65%,26%)"></rect>
            <rect x="61" y="41" width="18" height="18" rx="3" fill="hsl(120,65%,27%)"></rect>
            <rect x="61" y="21" width="18" height="18" rx="3" fill="hsl(120,65%,28%)"></rect>
            <rect x="81" y="21" width="18" height="18" rx="3" fill="hsl(120,65%,29%)"></rect>
            <rect x="101" y="21" width="18" height="18" rx="3" fill="hsl(120,65%,31%)"></rect>
            <rect x="121" y="21" width="18" height="18" rx="3" fill="hsl(120,65%,32%)"></rect>
            <rect x="141" y="21" width="18" height="18" rx="3" fill="hsl(120,65%,33%)"></rect>
            <rect x="141" y="41" width="18" height="18" rx="3" fill="hsl(120,65%,35%)"></rect>
            <rect x="141" y="61" width="18" height="18" rx="3" fill="hsl(120,65%,36%)"></rect>
            <rect x="161" y="61" width="18" height="18" rx="3" fill="hsl(120,65%,37%)"></rect>
            <rect x="181" y="61" width="18" height="18" rx="3" fill="hsl(120,65%,38%)"></rect>
            <rect x="201" y="61" width="18" height="18" rx="3" fill="hsl(120,65%,40%)"></rect>
            <rect x="221" y="61" width="18" height="18" rx="5" fill="var(--snake)"></rect>
            <rect x="234" y="63" width="3" height="3" fill="#001500"></rect>
            <rect x="234" y="74" width="3" height="3" fill="#001500"></rect>
            <rect x="261" y="61" width="18" height="18" rx="5" fill="var(--apple)"></rect>
            <rect x="269" y="56" width="2" height="6" fill="#2f7d2f"></rect>
        </g>
    </svg>

    <div class="foot pixel">
        <span><?= $e($card['eid']) ?></span>
        <span><?= $card['closed'] ? 'BY APPROVAL' : 'OPEN TO ALL' ?></span>
    </div>
</div>

<div class="bar">
    <button type="button" onclick="window.print()">Print</button>
    <button type="button" id="mode">Dark</button>
    <button type="button" onclick="window.close()">Close</button>
</div>
<p class="note">
    This page is the only place the event key exists outside the database.
    What you see is what prints, dark mode included.
</p>

<script>
// The mode is a per-operator preference, so it is remembered here rather
// than stored on the event: two people printing the same poster may want
// different ink. A browser that refuses storage just starts light.
(function () {
    var root = document.documentElement;
    var btn = document.getElementById('mode');
    function paint() {
        btn.textContent = root.classList.contains('dark') ? 'Light' : 'Dark';
    }
    try {
        if (localStorage.getItem('fok-poster-mode') === 'dark') root.classList.add('dark');
    } catch (e) { /* storage refused; light it is */ }
    paint();
    btn.onclick = function () {
        root.classList.toggle('dark');
        try {
            localStorage.setItem('fok-poster-mode',
                root.classList.contains('dark') ? 'dark' : 'light');
        } catch (e) { /* the choice still applies to this print */ }
        paint();
    };
})();
</script>
</body>
</html>
