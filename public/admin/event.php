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
 * the event and it should look like the thing it lets them into. The
 * description is set in it too: it is the room's motto, the one line the
 * game's own event screen draws in that face, so the poster says it the
 * same way - and a long one steps down and is cut on a line, never mid-glyph.
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

$url = FOK_GAME_URL . '#event=' . $card['ekey'];
// PINNED to version 3 / level L / mask 0, which is not a preference: the
// game's own scanner falls back to a decoder built for exactly that shape
// (FOK-snake js/qr.js) wherever the browser has no BarcodeDetector, and a
// poster nobody can scan in the app is the wrong poster. It costs the
// sturdier level M correction, so the code is printed large instead.
$svg = Qr::svg($url, 'L', 3, 0, 8, 2);
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
}
/* The dark paper IS the title block's black, so the block dissolves into the
   sheet and one black stands behind the wordmark. */
html.dark {
    --ink: #e6edf3;
    --ink-soft: #8b98a5;
    --paper: #0b0f14;
    --line: #2a3442;
    --accent: #3ddc84;
    --snake: #7fff7f;
}

* { box-sizing: border-box; }

html, body { margin: 0; padding: 0; background: #55606c; }

body {
    font: 14px/1.55 system-ui, sans-serif;
    display: flex; flex-direction: column; align-items: center;
    padding: 18px 12px 40px;
    color: var(--ink);
}

/* The sheet IS A4, exactly - not a floor it may grow past. Everything on it
   is placed in millimetres, so what the screen shows is what the paper gets,
   at any zoom, and the page it gets is ONE. The height is fixed and the
   overflow hidden because a poster that runs onto a second sheet is not a
   poster; every block below is sized so that the sheet's own content cannot
   reach that clip. */
.sheet {
    width: 210mm; height: 297mm; overflow: hidden;
    padding: 15mm 18mm 12mm;
    background: var(--paper);
    color: var(--ink);
    display: flex; flex-direction: column; align-items: center; text-align: center;
    box-shadow: 0 2px 18px rgba(0,0,0,0.45);
}

/* The face has no emoji, so one in a name or a motto falls through to the
   platform's own emoji face - named, so the fallback is the same face on
   every machine the poster is opened on. */
.pixel {
    font-family: 'Press Start 2P', 'Segoe UI Emoji', 'Apple Color Emoji',
        'Noto Color Emoji', 'Courier New', monospace;
}

.titleblock {
    width: 100%; padding: 7mm 6mm 6mm; margin-bottom: 6mm;
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
    margin: 0; font-size: 9.5mm; line-height: 1.35;
    text-wrap: balance; overflow-wrap: anywhere;
}
/* A long name would push the QR off the page, so it steps down instead. The
   face is a square em, so 18 characters at 9.5mm is one line of the 174mm
   the sheet has and the two longer tiers each wrap to two. */
h1.long { font-size: 7mm; }
h1.longer { font-size: 5.4mm; }

/* The motto sits close under the name, as the game's event screen puts it.
   The face is a square em, so a tier is a character count per line times a
   line count, and the cut is on a LINE (line-clamp), never through a glyph:
   3 lines of 30 at 4.6mm, 5 of 46 at 3mm, 7 of 60 at 2.4mm. */
.descr {
    margin: 3mm auto 0; max-width: 140mm;
    font-size: 4.6mm; line-height: 1.5; color: var(--ink-soft);
    display: -webkit-box; -webkit-box-orient: vertical; overflow: hidden;
    -webkit-line-clamp: 3; line-clamp: 3;
}
.descr.long { font-size: 3mm; -webkit-line-clamp: 5; line-clamp: 5; }
.descr.longer { font-size: 2.4mm; -webkit-line-clamp: 7; line-clamp: 7; }
.when {
    margin: 5mm 0 0; font-size: 3.2mm; letter-spacing: 0.08em;
    color: var(--ink-soft);
}
.when span { white-space: nowrap; }

/* The QR is ALWAYS dark on light, in both modes. An inverted code is a
   gamble on the scanner, and the one job this page has is being scanned - so
   in dark mode it sits on its own lit panel instead, which reads as a screen
   and is the point of the page. */
.qr {
    margin: 8mm auto 0; padding: 5mm;
    background: #fff; border-radius: 2mm;
    width: 96mm; max-width: 100%;
}
html.dark .qr { box-shadow: 0 0 0 0.8mm var(--accent); }
.qr svg { display: block; width: 100%; height: auto; }

.code {
    margin: 6mm 0 0; font-size: 5mm; letter-spacing: 0.06em;
    color: var(--ink); word-break: break-all;
}
.how { margin: 4mm 0 0; font-size: 3.6mm; color: var(--ink-soft); }
/* The box is wider than the snake so its glow has room; the snake itself is
   the same 62mm it always was. */
.snake { display: block; width: 68mm; max-width: 100%; margin: 7mm auto 0; }

.foot {
    margin-top: auto; padding-top: 6mm; width: 100%;
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
        width: 210mm; height: 297mm; box-shadow: none;
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
$descr = (string)$card['descr'];
$dsize = strlen($descr) > 220 ? ' longer' : (strlen($descr) > 90 ? ' long' : '');
?>
<div class="sheet">
    <div class="titleblock">
        <div class="wordmark pixel">S N A K E</div>
        <div class="edition pixel">F O K   E D I T I O N</div>
    </div>
    <h1 class="pixel<?= $size ?>"><?= $e($name) ?></h1>

    <?php if ($descr !== ''): ?>
        <p class="descr pixel<?= $dsize ?>"><?= $e($descr) ?></p>
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
    <p class="code pixel"><?= $e($card['ekey']) ?></p>
    <p class="how">Scan to join. Point any camera at it.</p>

    <!-- The snake as the game draws it (js/render.js drawSnakeG): a
         20-unit grid, 18x18 segments inset by 1 so the cells stay
         separate, the body darkening towards the tail, the head
         brighter and rounder with its two eyes, and the whole of it lit
         the way the wordmark is. Ahead of it the gem as drawGem draws
         the plain one: a cyan diamond, white at the tip, in its halo.
         The box is the grid plus room for the glow on every side. -->
    <svg class="snake" viewBox="-14 -5 310 108"
         role="img" aria-label="A snake, chasing a gem">
        <defs>
            <filter id="snakeglow" x="-15%" y="-40%" width="130%" height="180%">
                <feGaussianBlur in="SourceAlpha" stdDeviation="4" result="b"/>
                <feFlood flood-color="#7fff7f" flood-opacity="0.9"/>
                <feComposite in2="b" operator="in" result="g"/>
                <feMerge>
                    <feMergeNode in="g"/><feMergeNode in="g"/>
                    <feMergeNode in="SourceGraphic"/>
                </feMerge>
            </filter>
            <filter id="gemglow" x="-150%" y="-100%" width="400%" height="300%">
                <feGaussianBlur in="SourceAlpha" stdDeviation="3.5" result="b"/>
                <feFlood flood-color="#00ffff" flood-opacity="0.9"/>
                <feComposite in2="b" operator="in" result="g"/>
                <feMerge>
                    <feMergeNode in="g"/><feMergeNode in="g"/>
                    <feMergeNode in="SourceGraphic"/>
                </feMerge>
            </filter>
            <linearGradient id="gemfill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#ffffff"/>
                <stop offset="0.35" stop-color="#00ffff"/>
                <stop offset="1" stop-color="#006688"/>
            </linearGradient>
            <radialGradient id="gemhalo">
                <stop offset="0" stop-color="#00ffff" stop-opacity="0.35"/>
                <stop offset="1" stop-color="#00ffff" stop-opacity="0"/>
            </radialGradient>
        </defs>
        <g filter="url(#snakeglow)">
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
        </g>
        <circle cx="270" cy="70" r="20" fill="url(#gemhalo)"></circle>
        <polygon points="270,61 275.9,70 270,79 264.1,70" fill="url(#gemfill)"
                 filter="url(#gemglow)"></polygon>
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
