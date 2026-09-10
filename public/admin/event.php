<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Events.php';
require_once __DIR__ . '/../src/Qr.php';

/**
 * The event's poster: name, description, dates and the QR that carries its
 * KEY. Print to PDF and that is the export.
 *
 * THIS IS THE ONLY PLACE THE KEY EXISTS OUTSIDE THE DATABASE. No JSON answer
 * this server can produce carries it, for anybody - not the admin API, not
 * the organizer's client. A page behind the login, sent no-store, is where an
 * operator sees it, and paper is where it goes next.
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
$svg = Qr::svg($url, 'M', 0, -1, 8, 4);
$e = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$when = static function (?int $t): string {
    return $t === null ? '' : gmdate('D d M Y, H:i', $t) . ' UTC';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($card['name']) ?> - event QR</title>
<style>
/* Deliberately NOT admin.css: this page is meant for paper, and the
   dashboard's dark surface would print as a black rectangle. It carries its
   own handful of rules instead of a variable nobody else would use. */
* { box-sizing: border-box; }
body {
    margin: 0; padding: 32px 24px;
    background: #f4f4f6; color: #111;
    font: 15px/1.5 system-ui, sans-serif;
    display: flex; flex-direction: column; align-items: center;
}
.sheet {
    background: #fff; width: 100%; max-width: 520px;
    padding: 40px 36px 32px; border-radius: 8px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.12);
    text-align: center;
}
h1 { margin: 0 0 6px; font-size: 27px; line-height: 1.15; }
.descr { margin: 0 auto 4px; max-width: 42ch; color: #444; }
.when { margin: 10px 0 0; color: #555; font-size: 14px; }
.qr { margin: 26px auto 14px; width: 260px; max-width: 100%; }
.qr svg { display: block; width: 100%; height: auto; }
.code {
    font: 600 15px/1.4 ui-monospace, SFMono-Regular, Menlo, monospace;
    letter-spacing: 0.06em; word-break: break-all;
}
.how { margin: 8px 0 0; color: #555; font-size: 14px; }
.foot { margin: 22px 0 0; padding-top: 14px; border-top: 1px solid #ddd;
        color: #777; font-size: 12px; }
.bar { margin: 20px 0 0; display: flex; gap: 10px; }
.bar button {
    font: inherit; padding: 8px 16px; cursor: pointer;
    border: 1px solid #bbb; border-radius: 6px; background: #fff;
}
/* Paper: no chrome, no shadow, no controls, and never a page break through
   the code itself. */
@media print {
    body { background: #fff; padding: 0; }
    .sheet { box-shadow: none; max-width: none; border-radius: 0; }
    .bar { display: none; }
    .qr { break-inside: avoid; }
}
</style>
</head>
<body>
<div class="sheet">
    <h1><?= $e($card['name']) ?></h1>
    <?php if ($card['descr'] !== ''): ?>
        <p class="descr"><?= $e($card['descr']) ?></p>
    <?php endif; ?>
    <?php if ($card['starts'] !== null || $card['ends'] !== null): ?>
        <p class="when">
            <?php if ($card['starts'] !== null): ?>
                From <?= $e($when($card['starts'])) ?><br>
            <?php endif; ?>
            <?php if ($card['ends'] !== null): ?>
                Until <?= $e($when($card['ends'])) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <div class="qr"><?= $svg ?></div>
    <p class="code"><?= $e($card['eid'] . '.' . $card['ekey']) ?></p>
    <p class="how">Scan to join. Point any camera at it.</p>

    <p class="foot">
        <?= $e($card['eid']) ?>
        <?= $card['closed'] ? ' - the organizer approves each request'
                            : ' - a scan joins straight away' ?>
    </p>
</div>

<div class="bar">
    <button onclick="window.print()">Print</button>
    <button onclick="window.close()">Close</button>
</div>
</body>
</html>
