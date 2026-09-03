<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Scores.php';
require_once __DIR__ . '/src/Presence.php';

header('Cache-Control: no-store');
$scores = Scores::top();
$counts = Presence::counts();
// Shown in both the header and the footer.
$verline = 'FOK-server v' . FOK_SERVER_VERSION . ' (API v' . FOK_API_VERSION . ')'
    . (FOK_ENV === 'staging' ? ' STAGING' : '');
// The board holds up to FOK_TOP_SCORES; collapse it to the top few and let
// the visitor expand the rest (a pure-CSS toggle, see style.css).
$topN = 10;
$collapsible = count($scores) > $topN;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FOK-server</title>
<link rel="icon" type="image/svg+xml" href="assets/logo.svg">
<link rel="stylesheet" href="assets/style.css?v=<?= FOK_SERVER_VERSION ?>">
</head>
<body>
<header>
  <img class="logo" src="assets/logo.svg" alt="" width="72" height="72">
  <h1>FOK<span>-server</span></h1>
  <p>Central game server for <a href="https://poeggi.github.io/FOK-snake/">FOK Snake</a></p>
  <p class="stats"><span><?= $counts['online'] ?></span> online -
    <span><?= $counts['playing'] ?></span> playing 1:1 -
    <span><?= $counts['registered'] ?></span> client ids</p>
  <p class="version muted"><?= htmlspecialchars($verline) ?></p>
</header>
<main>
  <h2>Global Top <?= FOK_TOP_SCORES ?></h2>
  <?php if ($scores === []): ?>
  <p class="muted">No scores submitted yet. Be the first!</p>
  <?php else: ?>
  <?php if ($collapsible): ?>
  <input type="checkbox" id="showall" class="scoretoggle">
  <?php endif; ?>
  <table class="scores">
    <tr><th>#</th><th>Name</th><th>Score</th><th>Diff</th><th>Level</th><th>Date</th></tr>
    <?php $n = 0; foreach ($scores as $s): $n++; ?>
    <tr<?= $collapsible && $n > $topN ? ' class="extra"' : '' ?>>
      <td><?= $s['rank'] ?></td>
      <td><?= htmlspecialchars($s['name']) ?></td>
      <td><?= $s['score'] ?></td>
      <td><?= ['E', 'N', 'H'][$s['diff']] ?? 'N' ?></td>
      <td><?= $s['level'] ?><?php if ($s['completed']): ?> <span class="win" title="Completed the final level">&#9733;</span><?php endif; ?></td>
      <td><?= gmdate('d.m.y', $s['created']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php if ($collapsible): ?>
  <label for="showall" class="scoretoggle-btn">
    <span class="more">Show all <?= count($scores) ?></span>
    <span class="less">Show top <?= $topN ?></span>
  </label>
  <?php endif; ?>
  <?php endif; ?>
  <footer class="muted"><?= htmlspecialchars($verline) ?></footer>
</main>
</body>
</html>
