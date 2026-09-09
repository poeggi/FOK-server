<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/Settings.php';

final class Util
{
    // One key per worker process, marking that it has answered before, and
    // how long that mark stands (see claimWorker). Its own prefix, because
    // clearing the traffic statistics must not make every worker look new.
    private const WORKER_KEY = 'fok:pid:';
    private const WORKER_TTL = 3600;
    // One player's requests in flight. Its own prefix, and a TTL well clear
    // of the longest request there can be (a held poll, FOK_POLL_WAIT_MAX),
    // so a worker killed mid-request cannot leave a count high for good.
    private const FLIGHT_PREFIX = 'fok:flight:';
    private const FLIGHT_TTL = FOK_POLL_WAIT_MAX + 60;
    // The gate for the abandoned-tournament sweep. Bare prefix like the rest
    // of the tournament keys: none of it comes from or goes back into the
    // database, so it is left un-namespaced on purpose (see Config.php).
    private const TSWEEP_KEY = 'fok:tsweep';

    /**
     * Keeps the "every response is JSON with ok" contract when something
     * fails: with display_errors=0 an uncaught exception (locked database,
     * full disk) would end the request with an EMPTY 500 body, blowing up
     * the client's response.json() instead of telling it what happened.
     */
    public static function installFaultHandler(): void
    {
        set_exception_handler(static function (Throwable $e): void {
            error_log('FOK fault: ' . $e);
            // The request is already lost, and the usual reason is a locked
            // database - which is exactly what the queued bookkeeping would
            // go on to retry. Running it here piles more writes onto the
            // jammed writer and logs a second fault for work nobody awaits.
            self::$deferred = [];
            // What the database cost is still booked, because this is the
            // request that cost the most: a write that ran out of
            // busy_timeout is the largest access there is, and the gauge
            // that exists to show it must not be the one thing that misses
            // it. Load::flush writes to shared memory only, so it is the
            // one piece of the queue above that cannot make a jammed writer
            // any worse.
            require_once __DIR__ . '/Load.php';
            try {
                Load::flush();
            } catch (Throwable $ignored) {
            }
            if (!headers_sent()) {
                self::jsonOut(['ok' => false, 'error' => 'server fault'], 500);
            }
        });
        // Exception handlers do not see fatals, and an execution timeout
        // hits the long polls first.
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
            if ($e !== null && ($e['type'] & $fatal) !== 0 && !headers_sent()) {
                self::$deferred = [];
                self::jsonOut(['ok' => false, 'error' => 'server fault'], 500);
            }
            // Endpoints that answer without jsonOut (backup download) would
            // otherwise drop their queue on the floor.
            self::runDeferred();
        });
    }

    // Player IDs are 32-bit values as 8 lowercase hex chars (see FOK-snake js/storage.js).
    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{8}$/', $id) === 1;
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '?';
    }

    /**
     * Classifies a server-observed IP for the peer-net hint (see
     * Presence::announceNet): its address family, so two peers can tell
     * whether a direct same-family path is even possible. An IPv4-mapped
     * IPv6 form (::ffff:a.b.c.d) counts as IPv4 and is returned dotted.
     * family is 4, 6, or 0 when the address is unknown/unparseable.
     * @return array{ip:string,family:int}
     */
    public static function ipInfo(string $ip): array
    {
        if (str_starts_with($ip, '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = substr($ip, 7);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ip' => $ip, 'family' => 4];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return ['ip' => $ip, 'family' => 6];
        }
        return ['ip' => $ip, 'family' => 0];
    }

    /**
     * The NETWORK an address belongs to, as a comparison key - what "two
     * devices in the same room" has to mean when the two addresses are not
     * the same string.
     *
     * IPv4 is NATed, so a whole household leaves through one public address
     * and the address IS the network. IPv6 is not: with no NAT, every device
     * on the same LAN carries its own global address out of the site's /64,
     * so comparing the addresses compares two values that were never going
     * to be equal. The /64 is where they meet, and it is one link by
     * RFC 4291 rather than a guess. The suffix is kept so a network can
     * never be confused with a bare address.
     *
     * A mapped ::ffff:a.b.c.d is unwrapped first (see ipInfo), so one v4
     * client is one network however the host presents it. A v4 address and
     * a v6 one are never the same network - nothing in the two strings says
     * they share a room - which is why a player is remembered on one
     * network PER FAMILY rather than on one network (see Presence::seenOn).
     */
    public static function ipNet(string $ip): string
    {
        $info = self::ipInfo($ip);
        if ($info['family'] !== 6) {
            return $info['ip'];
        }
        $bin = @inet_pton($info['ip']);
        if (!is_string($bin) || strlen($bin) !== 16) {
            return $info['ip'];
        }
        $net = @inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8));
        return is_string($net) ? $net . '/64' : $info['ip'];
    }

    /**
     * Whether an address is one the public internet could have routed to
     * us - the only kind worth recording as a network.
     *
     * It exists for the addresses a CLIENT reports about itself (see
     * Presence::seenOn): a server-observed REMOTE_ADDR is public by
     * construction, but an ICE candidate list is full of things that are
     * not - link-local, unique-local, RFC 1918, loopback, and Chrome's
     * mDNS .local placeholders, which are not addresses at all. Two devices
     * that both claim 192.168.0.0 are not in the same room, they are in two
     * different houses behind the same default router range, so a private
     * address is worse than no address here.
     */
    public static function isPublicIp(string $ip): bool
    {
        $info = self::ipInfo($ip);
        if ($info['family'] === 0) {
            return false;
        }
        return filter_var(
            $info['ip'],
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * The oldest stamp that still counts as within $window seconds of $now:
     * the window plus the jitter second a late beat may take. Every "still
     * here" test compares >= this, so none of them reads a client, a duel
     * or a signal as gone before the window and its grace have both passed.
     */
    public static function since(int $window, ?int $now = null): int
    {
        return ($now ?? time()) - $window - FOK_BEAT_JITTER;
    }

    public static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }

    /**
     * Validates a client-sent PTS (server-clock timestamp, ms). Clients
     * report events that already happened, so by the time a PTS arrives
     * it must lie in the past - a future value means a broken sync or a
     * fabricated event and is rejected and logged as bogus.
     */
    public static function checkPts(mixed $pts, string $who): ?int
    {
        if ($pts === null) {
            return null;
        }
        if (!is_int($pts) || $pts < 0) {
            self::fail('invalid pts');
        }
        if ($pts > self::nowMs()) {
            self::bump('bogus');
            Alerts::raise('bogus', "Bogus client event: future PTS from $who (" . self::clientIp() . ')');
            self::fail('bogus pts: in the future');
        }
        return $pts;
    }

    public static function cors(): void
    {
        // Dynamic responses must never be cached by browsers or proxies.
        header('Cache-Control: no-store');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, FOK_ALLOWED_ORIGINS, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 86400');
            // A browser blanks the Resource Timing breakdown of a
            // cross-origin response unless the server allows it: the
            // protocol, the connect and TLS marks and the transfer sizes all
            // read empty or zero. A client needs them to tell a cold
            // connection from a warm one before it anchors its clock. It
            // names the caller rather than answering any origin because it
            // also discloses the response SIZE, and who may read an answer is
            // exactly what the allowlist governs.
            header('Timing-Allow-Origin: ' . $origin);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * True when the SERVER ITSELF saw a TLS connection: its own HTTPS variable,
     * or the port it answered on. Only its own view counts. This host
     * terminates TLS directly, so a header like X-Forwarded-Proto is set by the
     * caller and not by any proxy - trusting it would hand the caller the
     * switch that turns the gate below off. If TLS ever does terminate upstream,
     * add that proxy's header HERE and nowhere else, and only once the proxy is
     * known to overwrite it.
     */
    public static function isSecureTransport(string $https, string $port): bool
    {
        return ($https !== '' && strtolower($https) !== 'off') || $port === '443';
    }

    /**
     * Refuse a cleartext request. In normal operation this never fires: a
     * redirect answers http:// long before PHP runs - the vhost's own 301, and
     * behind it the 308 this repo ships in public/.htaccess. Neither is
     * something the smoke suite can exercise, and the vhost rule is not even
     * version-controlled, so PHP keeps its own backstop: if both redirects are
     * ever lost, the API refuses cleartext instead of quietly serving it.
     *
     * The command line has no transport to judge, so both CLI SAPIs are exempt:
     * php -S (the smoke suite) and the CLI test runner. Neither can occur on the
     * live host, which serves through FPM.
     */
    public static function requireHttps(): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
            return;
        }
        if (!self::isSecureTransport(
            (string)($_SERVER['HTTPS'] ?? ''),
            (string)($_SERVER['SERVER_PORT'] ?? '')
        )) {
            self::fail('HTTPS required', 426);
        }
    }

    /**
     * True only for a transport we can positively identify as pre-1.2 (SSLv2/3,
     * TLS 1.0/1.1). Empty/unknown is fail-open: TLS may terminate upstream where
     * PHP never sees it, so we never block what we cannot judge.
     */
    public static function tlsBelow12(string $proto): bool
    {
        return $proto !== ''
            && (str_starts_with($proto, 'SSL') || preg_match('/^TLSv1(\.[01])?$/', $proto) === 1);
    }

    /**
     * Refuse a request served over pre-1.2 TLS. The host already rejects old
     * TLS at the handshake, so this is the backstop for the day that changes.
     */
    public static function requireModernTls(): void
    {
        if (self::tlsBelow12($_SERVER['SSL_PROTOCOL'] ?? '')) {
            self::fail('TLS 1.2 or higher required', 426);
        }
    }

    /**
     * The request body, hard-capped: the only other bound is PHP's
     * post_max_size (8M default), i.e. anyone could make a worker buffer
     * megabytes. Rejected loudly (413), never silently trimmed.
     */
    public static function jsonBody(): array
    {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > FOK_MAX_BODY) {
            self::fail('body too large', 413);
        }
        $raw = file_get_contents('php://input', false, null, 0, FOK_MAX_BODY + 1);
        if ($raw === false) {
            return [];
        }
        if (strlen($raw) > FOK_MAX_BODY) {
            self::fail('body too large', 413);
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** @var list<callable> */
    private static array $deferred = [];
    private static ?string $caller = null;
    private static int $depth = 0;

    /**
     * Runs $fn AFTER the response has been handed to the client. The
     * server's own bookkeeping is not the caller's work and must not sit
     * in its latency: under FPM the response is flushed first, so the
     * client is gone before any of it runs.
     *
     * ONLY for work the client never observes (monitoring, sweeps). A
     * client may issue its next request the instant the response lands,
     * and that request can overtake this work - anything it could read
     * back has to happen before the answer, not here.
     *
     * Without FPM (CLI, the php -S test server) there is nothing to flush
     * and the work simply runs inline: same behaviour, only the timing
     * differs, so the tests still see it.
     */
    public static function defer(callable $fn): void
    {
        self::$deferred[] = $fn;
    }

    /**
     * Throws the queued tail away. Everything in it describes the database
     * as it was when it was queued, so after a restore it would write
     * pre-restore counts and presence over the rows just brought back.
     */
    public static function cancelDeferred(): void
    {
        self::$deferred = [];
    }

    /**
     * Names the player this request belongs to, and counts it against that
     * player's requests in flight.
     *
     * The caller cannot simply be read back later. queueWho() runs after the
     * response is already sent, and by then php://input is spent: jsonBody()
     * consumed it, and on FPM a second read yields nothing. So the endpoint
     * states it HERE, once, at the point where it has already parsed and
     * validated it - which is also the only point that knows an id in a
     * query string and an id in a JSON body are the same thing.
     *
     * The depth is taken NOW and kept, rather than read again when the
     * bookkeeping runs, because by then this request's siblings may have
     * finished. Read it for what it is: how many of this player's requests
     * were open when this one STARTED. It is NOT how many were open while it
     * queued - that is the stretch no PHP was running to see, and the reason
     * a self-inflicted wait can still read as one deep.
     */
    public static function noteCaller(string $id): void
    {
        if (self::$caller !== null) {
            return;
        }
        self::$caller = $id;
        require_once __DIR__ . '/Caps.php';
        if (Caps::apcu() !== true) {
            return;
        }
        $key = self::FLIGHT_PREFIX . $id;
        $n = apcu_inc($key, 1, $ok, self::FLIGHT_TTL);
        if ($ok !== true) {
            return;
        }
        self::$depth = (int)$n;
        // Shutdown rather than the foot of the endpoint: a request leaves
        // through jsonOut(), through fail() or through a fatal, and a count
        // handed back only where control falls through would drift upwards
        // for good. Dropping the key at zero keeps the keyspace to the
        // players actually talking, and heals a count a killed worker left.
        register_shutdown_function(static function () use ($key): void {
            if (apcu_dec($key, 1) < 1) {
                apcu_delete($key);
            }
        });
    }

    /** Idempotent: jsonOut runs the queue, the shutdown handler catches
     *  whatever exits by another path (readfile, a fatal). */
    public static function runDeferred(): void
    {
        if (self::$deferred === []) {
            return;
        }
        $queue = self::$deferred;
        self::$deferred = [];
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        foreach ($queue as $fn) {
            try {
                $fn();
            } catch (Throwable $e) {
                // The response is already sent: bookkeeping cannot be
                // allowed to become the client's problem.
                error_log('FOK deferred: ' . $e);
            }
        }
    }

    public static function jsonOut(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Cache-Control: no-store');
        header('Content-Type: application/json');
        echo json_encode($data);
        self::runDeferred();
        exit;
    }

    public static function fail(string $msg, int $code = 400): never
    {
        if ($code === 400) {
            self::defer(static fn() => self::noteInvalid());
        }
        self::jsonOut(['ok' => false, 'error' => $msg], $code);
    }

    /**
     * Per-hour counters feed the admin load statistics; the per-minute
     * request counter feeds the traffic alert.
     *
     * Deferred, because none of it is the caller's: every 25th request a
     * threshold sweep, and once an hour the whole player expiry.
     */
    public static function bump(string $metric): void
    {
        self::defer(static fn() => self::bumpNow($metric));
    }

    /**
     * Books what this request waited for a worker before it started (see
     * Load::queueUs). Deferred, so the client never pays for the bookkeeping.
     *
     * Three metrics, because a mean and a peak answer different questions:
     * the sum and the count divide into the average wait over the bucket, and
     * the maximum is the stall that average would otherwise hide. The count
     * is its own metric rather than the request total, because only requests
     * that could be measured belong in the divisor.
     */
    public static function noteQueue(): void
    {
        self::defer(static function (): void {
            // Claimed before anything below can return early: the mark says
            // this worker has answered before, so EVERY request it serves
            // has to set it - including the admin ones dropped next.
            $fresh = self::claimWorker();
            if (self::isAdminScript()) {
                return;
            }
            require_once __DIR__ . '/Load.php';
            $us = Load::queueUs();
            if ($us === null) {
                return;
            }
            require_once __DIR__ . '/Counters.php';
            Counters::add('n:q_us', $us);
            Counters::add('n:q_n', 1);
            Counters::max('q_us', $us);
            Counters::worst('q_us', $us, self::queueWho($fresh));
        });
    }

    /**
     * Whether this is the admin dashboard asking. Its polling is not game
     * traffic, and it runs only while somebody is watching the very gauge
     * these metrics feed: left in, the observer fills its own measurement,
     * which is precisely what it did on the day the list was built. Judged
     * by the script, the one thing here that a client cannot choose.
     */
    private static function isAdminScript(): bool
    {
        return str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/');
    }

    /**
     * Marks this worker as having answered something, and reports whether
     * it had not. A worker that PHP has just started pays for its own
     * startup before it can answer, and that cost lands in the queue
     * reading as though the pool had been busy - so a list of the worst
     * readings has to be able to tell the two apart.
     *
     * The mark is per process id and it expires, which makes this a proxy
     * and not a fact: a child recycled onto a process id whose mark is
     * still standing reads as reused. It is accurate enough for the only
     * question being asked of it, which is whether a pool is forever
     * spawning or never does.
     */
    private static function claimWorker(): bool
    {
        if (!function_exists('apcu_add')) {
            return false;
        }
        return apcu_add(self::WORKER_KEY . getmypid(), 1, self::WORKER_TTL) === true;
    }

    /**
     * Who a queued request was, for the worst-case list under the queue
     * gauge. Only what is already to hand at this point: the script is in
     * the environment, and the player id is in the query string of the
     * endpoints that carry one there - poll.php above all, which is the one
     * that holds a worker longest and books no counter of its own.
     *
     * A POST body is deliberately NOT read. jsonBody() consumes
     * php://input, the endpoint has already had it, and a second read
     * yields nothing on FPM - so a POST is identified by its address alone.
     */
    private static function queueWho(bool $fresh): array
    {
        return self::who() + ['w' => $fresh ? 1 : 0];
    }

    /**
     * Which request this is, for any worst-case list: the script, the
     * address and the player where one is known. One builder, so the queue
     * list and the database list name a request the same way.
     *
     * @return array<string, mixed>
     */
    public static function who(): array
    {
        $who = [
            's' => basename((string)($_SERVER['SCRIPT_NAME'] ?? '')),
            'ip' => self::clientIp(),
        ];
        if (self::$caller !== null) {
            $who['id'] = self::$caller;
            if (self::$depth > 0) {
                $who['d'] = self::$depth;
            }
            return $who;
        }
        // The fallback for anything that never named itself. Nothing should
        // lean on it: an endpoint that takes an id and does not state it
        // reads as an anonymous row, which is the one thing this list must
        // not be full of.
        $id = (string)($_GET['id'] ?? '');
        if (preg_match('/^[0-9a-f]{8}$/', $id) === 1) {
            $who['id'] = $id;
        }
        return $who;
    }

    private static function bumpNow(string $metric): void
    {
        // Counting is a shared-memory increment; the database sees one
        // write per minute rather than one per request (see Counters).
        require_once __DIR__ . '/Counters.php';
        $reqPerMin = Counters::hit($metric);
        // The session fold rides here too, rate-gated inside (see Presence).
        require_once __DIR__ . '/Presence.php';
        Presence::fold();
        // Threshold checks are cheap but not free; sample every 25 requests.
        // The > 0 guard matters: a miss must not read as "every request".
        if ($reqPerMin > 0 && $reqPerMin % 25 === 0) {
            self::watch($reqPerMin);
        }
        // Tournaments nobody is at any more. Not on the hourly gate: an hour
        // is longer than the thing it is cleaning up stands for, and not on
        // pulse either, because pulse needs a SEATED player to ask and the
        // whole condition here is that none of them do (see
        // Tournament::sweep). Its own gate, and it is taken HERE so that the
        // ordinary request does not even include the tournament code.
        self::sweepTourneys();
        // The hourly work is NOT on that sample. The count it reads is per
        // MINUTE, so a server that never sees 25 requests inside one minute
        // never reaches it - and then the expiry, the sweeps, the gauge
        // samples and the counter pruning never run at all. Its gate is one
        // shared-memory add of its own (see hourly).
        self::hourly();
    }

    /**
     * The gate for the abandoned-tournament sweep: at most one every
     * tournament_sweep_secs across the whole server, claimed with the same
     * apcu_add() the tournament store uses for every other claim. 0 is a
     * sweep on every request, which is what a test wants and what a host with
     * no shared memory gets anyway (nothing to sweep without it).
     *
     * The dashboard is excluded: reading the tournaments must not be what
     * ends one, which is the promise Tournament::listLive and ::detail make.
     */
    private static function sweepTourneys(): void
    {
        if (self::isAdminScript() || !function_exists('apcu_add')) {
            return;
        }
        $gap = Settings::int('tournament_sweep_secs');
        if ($gap > 0 && !apcu_add(self::TSWEEP_KEY, 1, $gap)) {
            return;
        }
        require_once __DIR__ . '/Tournament.php';
        Tournament::sweep();
    }

    /**
     * How many CPUs the host reports, and 1 when it will not say. There is no
     * shell and no phpinfo on shared hosting, so /proc/cpuinfo is the only
     * place to ask and open_basedir may still refuse it - hence the guarded
     * read. The fallback is deliberately 1: an unknown core count then makes
     * a per-core threshold stricter, never laxer.
     */
    public static function cores(): int
    {
        static $n = 0;
        if ($n === 0) {
            $n = 1;
            $txt = @file_get_contents('/proc/cpuinfo');
            if (is_string($txt)) {
                $hits = preg_match_all('/^processor\s*:/mi', $txt);
                if ($hits > 0) {
                    $n = $hits;
                }
            }
        }
        return $n;
    }

    // Inline monitoring - shared hosting has no daemons, so thresholds
    // are checked while serving regular requests.
    private static function watch(int $reqPerMin): void
    {
        if ($reqPerMin > Settings::int('alert_req_per_min')) {
            Alerts::raise('traffic', "Excessive traffic: $reqPerMin requests in the current minute");
        }
        if (function_exists('sys_getloadavg')) {
            // A load average only means something per CPU: 8 is a saturated
            // 8-core box and a quiet morning on a 48-core one. Both numbers
            // here describe the WHOLE machine - this is shared hosting, so
            // the load includes every neighbour's traffic and nothing we do
            // clears it. That is why the threshold is per core and loose: it
            // is a "the host is thrashing" signal, not a capacity gauge.
            // Our own saturation is the check below, and the more actionable
            // of the two: requests queueing behind the worker pool is a
            // condition we cause, can see and can act on, where a shared
            // host's load average is none of the three.
            $load = sys_getloadavg()[0] ?? 0.0;
            $cores = self::cores();
            if ($load / $cores > Settings::int('alert_load_per_core')) {
                Alerts::raise('overload', sprintf(
                    'System overload: 1-minute load average %.1f over %d core(s), %.1f per core',
                    $load,
                    $cores,
                    $load / $cores
                ));
            }
        }
        // The saturation we cause ourselves, which no host load average can
        // show: every slot in the long-poll budget is taken, so the workers
        // left for ordinary requests are the ones the budget held back. It is
        // not a failure - the budget did its job and those polls answered
        // early instead of queueing - but sustained it is the signal that a
        // cap wants lowering, or the pool raising.
        require_once __DIR__ . '/Holds.php';
        $budget = Settings::int('hold_max_workers');
        if ($budget > 0 && Holds::inUse() >= $budget) {
            Alerts::raise('overload', "Long-poll saturation: all $budget hold slots taken");
        }
        require_once __DIR__ . '/Presence.php';
        $online = Presence::counts()['online'];
        if ($online > Settings::int('alert_online')) {
            Alerts::raise('connections', "Excessive connections: $online players online");
        }
    }

    /**
     * The once-an-hour work: player expiry, the sweeps, a reading of the
     * levels the dashboard graphs, and the pruning of the counter buckets
     * nothing reads back. Runs in exactly one worker per hour and opens no
     * database in any other (see claimHourly).
     */
    private static function hourly(): void
    {
        if (!self::claimHourly()) {
            return;
        }
        require_once __DIR__ . '/Presence.php';
        require_once __DIR__ . '/Housekeeping.php';
        require_once __DIR__ . '/Counters.php';
        // Every task takes the writer WITHOUT waiting and is skipped when
        // another request holds it (see Db::tryWrite). None of this is
        // urgent and all of it is idempotent, so a skip costs nothing - and
        // waiting would cost a worker out of a pool of about twenty, on a
        // request whose client already has its answer. One task per lock, so
        // the writer is free again between them.
        $done = Db::tryWrite(static function (): void {
            $n = Presence::expireStale();
            if ($n > 0) {
                Alerts::raise('expiry', "Expired $n player(s) not seen for "
                    . Settings::int('player_ttl_days') . ' days; friendships cancelled');
            }
        });
        // The rows no reader can reach any more: the pair a finished duel
        // leaves behind, alerts that have been read and settings keys no
        // release knows (see Housekeeping).
        $done = Db::tryWrite(static fn() => Housekeeping::sweep()) && $done;
        // A reading of the levels the dashboard graphs, which no counter
        // accumulates (see Counters::sampleGauges).
        $done = Db::tryWrite(static fn() => Counters::sampleGauges()) && $done;
        $done = Db::tryWrite(static fn() => self::pruneCounters()) && $done;
        // The hour is closed only by a pass that did all of it. One that was
        // sent away comes back in a minute rather than an hour, and finds
        // the tasks that did run with nothing left to do.
        if ($done) {
            $done = Db::tryWrite(static function (): void {
                Db::get()->prepare(
                    "INSERT INTO counters (bucket, metric, value) VALUES ('meta', 'player_sweep', ?)
                     ON CONFLICT (bucket, metric) DO UPDATE SET value = excluded.value"
                )->execute([time()]);
            });
        }
        if (!$done && Caps::apcu()) {
            apcu_store(FOK_APCU_NS . 'sweep:hourly', 1, 60);
        }
        // The pruning above is the largest writer this server has, so the
        // write-ahead log is at its longest right here - and this tail is
        // the one place folding it back costs nobody anything (see
        // Db::drainWal).
        Db::drainWal();
    }

    /**
     * The counter buckets nothing reads back any more.
     *
     * Minute buckets go at 2 h: a minute stamp is twelve digits where an
     * hour stamp is ten, so this leaves the history and the lifetime totals
     * alone, and they are pure bloat once the Live tab has moved past them.
     * Hour buckets go at 30 days - GLOB matches the whole string, so the
     * lifetime totals and the meta rows, whose buckets are not numeric, are
     * never touched, and every endpoint books four of them an hour now that
     * it also carries its cost.
     */
    private static function pruneCounters(): void
    {
        $db = Db::get();
        $db->prepare(
            "DELETE FROM counters
             WHERE bucket GLOB '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]'
               AND bucket < ?"
        )->execute([gmdate('YmdHi', time() - 7200)]);
        $db->prepare(
            "DELETE FROM counters
             WHERE bucket GLOB '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]' AND bucket < ?"
        )->execute([gmdate('YmdH', time() - 30 * 86400)]);
    }

    /**
     * Wins for exactly one worker per hour. The marker row is the horizon
     * and stays the record - so a flushed cache cannot make the hourly work
     * run twice in one hour - but reading it is not atomic with moving it,
     * and two workers that read the same value would both proceed. The
     * shared-memory add settles that: it succeeds for one caller. The marker
     * is moved by a pass that finished, not by the claim (see hourly).
     */
    private static function claimHourly(): bool
    {
        require_once __DIR__ . '/Caps.php';
        // Shared memory answers first, so a request that is not the one
        // doing the work costs one apcu_add and never opens the database.
        if (Caps::apcu() && !apcu_add(FOK_APCU_NS . 'sweep:hourly', 1, 3600)) {
            return false;
        }
        $db = Db::get();
        $st = $db->prepare("SELECT value FROM counters WHERE bucket = 'meta' AND metric = 'player_sweep'");
        $st->execute();
        $last = (int)$st->fetchColumn();
        $st->closeCursor();
        return $last < time() - 3600;
    }

    // Counts invalid (HTTP 400) requests per IP per minute and alerts on
    // clients that keep sending garbage (spam, oversized or malformed).
    private static function noteInvalid(): void
    {
        try {
            require_once __DIR__ . '/Caps.php';
            // A monitor with a one-minute horizon, so it lives in shared
            // memory and nowhere else. Counting it in the database took the
            // single writer on every REJECTED request - which is what a
            // flood is made of, so the guard against one was also its
            // amplifier. Without APCu there is nothing to count into, and
            // the guard is simply off; the request is still rejected.
            if (!Caps::apcu()) {
                return;
            }
            $ip = self::clientIp();
            $ok = false;
            $n = apcu_inc(FOK_APCU_NS . 'inv:' . $ip . ':' . gmdate('YmdHi'), 1, $ok, 600);
            if ($n > Settings::int('alert_invalid_per_min')) {
                Alerts::raise('spam', "Client spam: $n invalid requests this minute from $ip");
            }
        } catch (Throwable $e) {
            // Monitoring must never turn an invalid request into a 500.
        }
    }
}

Util::installFaultHandler();
// Every request reaches PHP through Util, so the transport is judged once,
// here. Cleartext first - is this TLS at all - then the version floor
// (fail-open when the version is not visible, e.g. CLI tests or upstream
// termination).
Util::requireHttps();
Util::requireModernTls();
// And, from the same place, record what the request waited to get here. This
// measurement cannot live in an endpoint: the queue is a property of the
// worker pool rather than of any script, and the endpoints that do not count
// themselves are exactly the ones whose wait matters most - poll.php holds a
// worker for nine seconds and deliberately books nothing (see its header).
Util::noteQueue();
