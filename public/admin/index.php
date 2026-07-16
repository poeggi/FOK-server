<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Util.php';

Auth::startSession();

if (($_POST['do'] ?? '') === 'login') {
    $ok = Auth::login((string)($_POST['user'] ?? ''), (string)($_POST['pass'] ?? ''), Util::clientIp());
    header('Location: index.php' . ($ok ? '' : '?failed=1'));
    exit;
}
if (($_GET['do'] ?? '') === 'logout') {
    Auth::logout();
    header('Location: index.php');
    exit;
}

$loggedIn = Auth::isLoggedIn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>FOK-server admin</title>
<link rel="icon" type="image/svg+xml" href="../assets/logo.svg">
<link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<header>
  <h1><img class="logo" src="../assets/logo.svg" alt="" width="22" height="22"> FOK-server <span>admin</span></h1>
  <?php if ($loggedIn): ?><nav><a href="index.php?do=logout">Logout</a></nav><?php endif; ?>
</header>
<?php if (!$loggedIn): ?>
<main class="login">
  <form method="post" action="index.php">
    <input type="hidden" name="do" value="login">
    <?php if (isset($_GET['failed'])): ?><p class="error">Login failed.</p><?php endif; ?>
    <label>User <input type="text" name="user" autocomplete="username" required></label>
    <label>Password <input type="password" name="pass" autocomplete="current-password" required></label>
    <button type="submit">Login</button>
  </form>
</main>
<?php else: ?>
<main id="dashboard" class="dashboard"></main>
<script src="../assets/admin.js"></script>
<?php endif; ?>
</body>
</html>
