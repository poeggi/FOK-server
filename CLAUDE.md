# FOK-server - notes for AI sessions

Read README.md for what this is; read docs/API.md before touching any
endpoint - it is the contract the FOK-snake client is built against.

## Hard rules

- ASCII only in every file. No smart quotes, em dashes, arrows or
  section signs (CI enforces this).
- Never put credentials anywhere in the repo: no FTP/deploy passwords,
  no admin credentials, no tokens, not even in commit messages or the
  admin hash itself (CI greps for leak patterns). Deploy credentials
  live in ~/.fok-server-deploy.json on the developer machine only.
- Every PHP file starts with declare(strict_types=1) (CI enforces).
- LF line endings (.gitattributes enforces); PowerShell scripts are the
  only CRLF exception.
- Runtime data (SQLite db, admin.hash, backups) lives ABOVE the docroot
  in ../fok-server-data/, never under public/.

## Architecture invariants

- Shared hosting: Apache + PHP-FPM only. No daemons, no WebSockets, no
  cron. Anything real-time must be client-polled HTTP or peer-to-peer
  WebRTC (the server only relays signaling; game traffic never touches
  the server).
- public/ mirrors the webroot 1:1; deploy is a dumb FTPS file copy
  (tools/deploy.ps1). No build step, no composer, no dependencies.
- SQLite via PDO in WAL mode, schema auto-created in src/Db.php. The
  FOK_DATA_DIR env var overrides the data location (tests rely on it).
- Player IDs are 8 lowercase hex chars (32-bit), validated everywhere
  with Util::isValidId. They are public identities, not secrets.
- Score entries must keep field parity with the FOK-snake local top-10
  entry: name (max 15 = client MAX_NAME), score, level, diff, color,
  shopItems, date (DD.MM.YY). Submissions store seed + inputs verbatim
  for future replay validation; validated stays 0 until that exists.
- The admin dashboard is modular: one self-contained object per card in
  MODULES (public/assets/admin.js). Extend by appending a module, never
  by special-casing the framework code.

## When changing the API

Update all four together or CI/state drifts: the endpoint, docs/API.md,
README.md's sketch, and the tests (test/unit.php for logic,
test/smoke.sh for the HTTP behavior).

## Workflow

- bash test/checks.sh runs everything CI runs (needs php CLI; the
  pre-commit hook in .githooks/ does this automatically and skips
  gracefully when php is missing).
- After deploying, verify against the live server with curl; the smoke
  test only covers php -S, and .htaccess behavior (src/ blocking,
  HTTPS redirect) exists only on the real Apache.
- One-time server maintenance (writing admin.hash, resetting the db)
  is done by uploading a temporary PHP script via FTPS, invoking it
  once over HTTPS, and deleting it immediately.
