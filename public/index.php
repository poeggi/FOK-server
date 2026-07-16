<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Scores.php';

$scores = Scores::top();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FOK-server</title>
<link rel="icon" type="image/svg+xml" href="assets/logo.svg">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header>
  <img class="logo" src="assets/logo.svg" alt="" width="72" height="72">
  <h1>FOK<span>-server</span></h1>
  <p>Central game server for <a href="https://poeggi.github.io/FOK-snake/">FOK Snake</a></p>
</header>
<main>
  <h2>Global Top <?= FOK_TOP_SCORES ?></h2>
  <?php if ($scores === []): ?>
  <p class="muted">No scores submitted yet. Be the first!</p>
  <?php else: ?>
  <table>
    <tr><th>#</th><th>Name</th><th>Score</th><th>Level</th><th>Date</th></tr>
    <?php foreach ($scores as $s): ?>
    <tr>
      <td><?= $s['rank'] ?></td>
      <td><?= htmlspecialchars($s['name']) ?></td>
      <td><?= $s['score'] ?></td>
      <td><?= $s['level'] ?></td>
      <td><?= gmdate('d.m.y', $s['created']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</main>
</body>
</html>
