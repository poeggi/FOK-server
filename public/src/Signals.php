<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Util.php';

/**
 * Store-and-forward mailbox for matchmaking and WebRTC signaling.
 * The server never interprets SDP/ICE payloads; it only relays them
 * between player IDs. The game traffic itself is peer-to-peer, except
 * in relay-fallback mode, which runs over relay.php - not through here.
 *
 * The mailbox lives in APCu, and only in APCu. A signal is worthless
 * signal_ttl seconds after it is written and is deleted the moment it is
 * read, so none of it is ever worth an fsync: SQLite allows a single writer
 * for the whole database, and holding a 30-second handshake queue behind
 * that writer put every long poll - one indexed SELECT every 20 ms, ~450
 * per held poll per client - in contention with the scores, the ledger and
 * every other request in the server.
 *
 * There is deliberately no database transport to fall back to. A fallback
 * that is never exercised is a second implementation nobody tests, and a
 * host either has shared memory or cannot run the signaling layer at all -
 * see mustHaveApcu(), which says so loudly rather than quietly moving the
 * outage into the write lock.
 */
final class Signals
{
    private const PREFIX = 'fok:sg:';

    // The sequence counters must outlive the messages under them: they are
    // the mailbox's identity, and re-seeding one mid-handshake would replay
    // or strand messages. A day is far longer than any handshake.
    private const SEQ_TTL = 86400;

    // Client-sendable types. 'friend' (friendship notifications),
    // 'undelivered' (see sweep()), 'peer-net' (see
    // Presence::announceNet) and 'tourney' (see Tournament::flush) are
    // server-generated and deliberately NOT in this list, so a client cannot
    // forge them - a forged 'tourney' would let anyone rewrite a bracket on
    // someone else's screen.
    //
    // 'watch' is a spectator's request to be fed a match: peer-to-peer, like
    // every other signaling type here, and NOT in NEEDS_RECEIPT - a spectator
    // that gets no feed simply keeps watching the scoreboard, which is not
    // the failed-connection case a receipt exists for.
    // 'ices' (4.4) is 'ice' with a JSON ARRAY payload - one message for a
    // side's whole trickle instead of a request per candidate. A separate
    // type on purpose: a peer built before 4.4 has no case for it and drops
    // the message, which loses candidates it never had; reshaping 'ice' would
    // have made that same peer parse an array as one candidate and lose the
    // ones it DID have. The array is bounded in signal.php; its contents stay
    // opaque here, like every other payload.
    public const TYPES = ['invite', 'invite-relay', 'accept', 'accept-relay', 'decline', 'offer', 'answer', 'ice', 'ices', 'bye', 'chat', 'watch'];

    // Types that establish a connection: the sender is waiting for an
    // answer, so it MUST be told when one of these expires undelivered.
    private const NEEDS_RECEIPT = ['invite', 'invite-relay', 'accept', 'accept-relay'];

    /**
     * Shared memory is a hard requirement here, not a preference. Called
     * before any mailbox access: on a host without usable APCu the signaling
     * layer cannot work, and answering 503 with an alert is honest where a
     * silent SQLite fallback would only move the outage into the write lock.
     */
    private static function mustHaveApcu(): void
    {
        static $ok = null;
        if ($ok === true) {
            return;
        }
        if ($ok === null) {
            $ok = Caps::apcu();
        }
        if ($ok !== true) {
            Alerts::raise('perf', 'Signaling is unavailable: APCu is not usable on this '
                . 'host. The mailbox has no database transport by design - matchmaking '
                . 'and WebRTC signaling stay down until shared memory works.');
            Util::fail('signaling unavailable', 503);
        }
    }

    // ---- keys ---------------------------------------------------------
    // One mailbox per RECIPIENT: a player has many possible senders, so
    // unlike the relay the stream is keyed by its reader alone.
    private static function seqKey(string $to): string
    {
        return self::PREFIX . "$to:seq";
    }

    private static function ackKey(string $to): string
    {
        return self::PREFIX . "$to:ack";
    }

    private static function msgPrefix(string $to): string
    {
        return self::PREFIX . "$to:m:";
    }

    /**
     * The receipt watch for one connection message, keyed by the SAME
     * recipient and sequence as the message itself. That is what makes the
     * receipt exactly-once without a delivery marker: whoever delivers the
     * message deletes this entry by address, so whatever is still here when
     * its deadline passes is by definition a message nobody picked up.
     */
    private static function outKey(string $to, int $seq): string
    {
        return self::PREFIX . 'out:' . $to . ':' . sprintf('%012d', $seq);
    }

    /** @return bool false when the recipient's mailbox is full (flood cap) */
    public static function send(string $from, string $to, string $type, string $payload): bool
    {
        self::mustHaveApcu();
        if (self::pending($to) >= Settings::int('mailbox_cap')) {
            return false;
        }
        $ttl = Settings::int('signal_ttl');
        $now = time();
        $seq = apcu_inc(self::seqKey($to), 1, $ok, self::SEQ_TTL);
        $stored = apcu_store(
            self::msgPrefix($to) . sprintf('%012d', $seq),
            ['f' => $from, 't' => $type, 'p' => $payload, 'c' => $now],
            $ttl
        );
        if ($stored !== true) {
            // Shared memory is full. The sequence was already bumped; that
            // gap is harmless (take() acks to the high water mark and heals
            // it). What matters is reporting the refusal - a dropped invite
            // must never hide behind an ok:true.
            Alerts::raise('perf', 'Signal store failed: APCu shared memory is full. '
                . 'A signal was refused and the sender will retry; raise the APCu '
                . 'size or lower the signal limits.');
            return false;
        }
        if (in_array($type, self::NEEDS_RECEIPT, true)) {
            // Outlives the message on purpose: the sweep can only tell that
            // a message died undelivered if its watch is still there once
            // the message itself has gone.
            apcu_store(self::outKey($to, $seq), [
                'f' => $from,
                'to' => $to,
                't' => $type,
                'due' => $now + $ttl,
            ], $ttl + 300);
        }
        return true;
    }

    /**
     * "Anything for me?" - the long poll's hold condition, so it runs every
     * FOK_POLL_CHECK_USEC for the whole length of the poll and must never
     * take a lock or touch the database.
     *
     * Two shared-memory reads bound the window, and the messages in it are
     * checked by their own timestamp. The stamp is what decides, not the
     * cache TTL, because any() and take() have to agree on expiry exactly -
     * if this said yes to a message take() then dropped, poll.php would
     * answer 200 with an empty list and the client would poll straight
     * back. The walk is bounded by mailbox_cap and is normally zero or one
     * entry long.
     */
    public static function any(string $to): bool
    {
        self::mustHaveApcu();
        $hi = (int)apcu_fetch(self::seqKey($to));
        $lo = (int)apcu_fetch(self::ackKey($to));
        if ($hi < $lo) {
            // Two different things reach here and only one of them is a
            // fault, so only one of them is worth waking anybody for.
            //
            // The sequence is created by apcu_inc, whose TTL argument
            // applies to the key it CREATES and not to the ones it goes on
            // to increment; the ack is written with apcu_store, which
            // resets the TTL on every write. A mailbox in use for longer
            // than SEQ_TTL therefore loses its sequence on schedule while
            // its ack keeps being pushed forward - ordinary expiry, with
            // nothing pending behind it (a signal outlives its own
            // signal_ttl by hours less than this), and re-seeding the ack
            // is the whole of the repair.
            //
            // A sequence that is present and still below the ack is the
            // other thing. That one cannot happen without an eviction, and
            // it is what the alert is for.
            if ($hi > 0) {
                Alerts::raise('perf', 'Signal seq/ack desync: APCu evicted a mailbox '
                    . 'counter under memory pressure. Raise the APCu size if this recurs.');
            }
            apcu_store(self::ackKey($to), $hi, self::SEQ_TTL);
            return false;
        }
        $cut = time() - Settings::int('signal_ttl');
        $prefix = self::msgPrefix($to);
        for ($seq = $lo + 1; $seq <= $hi; $seq++) {
            $v = apcu_fetch($prefix . sprintf('%012d', $seq), $ok);
            if ($ok && is_array($v) && (int)$v['c'] >= $cut) {
                return true;
            }
        }
        return false;
    }

    /** Messages outstanding for a reader - the flood cap and the admin card. */
    public static function pending(string $to): int
    {
        // Not mustHaveApcu(): send() has already insisted, and the admin
        // card is only reporting. Without shared memory there is no mailbox
        // to have anything in it, which is what zero says.
        if (Caps::apcu() !== true) {
            return 0;
        }
        $gap = (int)apcu_fetch(self::seqKey($to)) - (int)apcu_fetch(self::ackKey($to));
        return $gap > 0 ? $gap : 0;
    }

    /**
     * Drains all pending messages for a player, oldest first. Each message is
     * claimed with apcu_delete(), which exactly one racing poll can win: two
     * overlapping polls (a retry over a slow link) must never both be handed
     * the same message, because a replayed invite or input desyncs the game.
     */
    public static function take(string $to): array
    {
        self::mustHaveApcu();
        self::sweep();
        $cut = time() - Settings::int('signal_ttl');
        $prefix = self::msgPrefix($to);
        // Deliver the window (ack, hi]. hi is read BEFORE draining, so
        // everything at or below it is accounted for afterwards - delivered
        // or expired - and acking to it also clears messages that died
        // untaken instead of leaving any() true forever. Each message is
        // addressed by its sequence, so this fetches only the handful
        // actually pending (bounded by mailbox_cap) rather than scanning
        // shared memory on every delivery.
        $lo = (int)apcu_fetch(self::ackKey($to));
        $hi = (int)apcu_fetch(self::seqKey($to));
        if ($hi < $lo) {
            $lo = 0;   // an evicted, re-seeded counter (see any())
        }
        $out = [];
        $ackTo = $hi;
        for ($seq = $lo + 1; $seq <= $hi; $seq++) {
            $k = $prefix . sprintf('%012d', $seq);
            $v = apcu_fetch($k, $ok);
            if (!$ok) {
                // A hole. send() bumps the sequence a beat BEFORE it stores
                // the message, so a concurrent drain can see a high water
                // mark whose message has not landed yet: never ack past the
                // top, or that message would be skipped for good. A hole
                // below the top is permanent - an expired or refused message
                // - so ack past it and move on.
                if ($seq === $hi) {
                    $ackTo = $hi - 1;
                }
                continue;
            }
            if (!apcu_delete($k) || !is_array($v)) {
                continue;   // another poll won the claim
            }
            if ((int)$v['c'] < $cut) {
                // Past its TTL: drop it, and leave the receipt watch alone.
                // The watch is what turns this into an 'undelivered' for the
                // sender, so settling it here would be the exact bug the
                // receipt exists to prevent - a dead invite evaporating.
                continue;
            }
            // Handed over for real, so its receipt watch is settled. Deleted
            // by address, O(1), and only one racing drain can win it.
            apcu_delete(self::outKey($to, $seq));
            $out[] = [
                'from' => (string)$v['f'],
                'type' => (string)$v['t'],
                'payload' => (string)$v['p'],
                'created' => (int)$v['c'],
            ];
        }
        apcu_store(self::ackKey($to), $ackTo, self::SEQ_TTL);
        return $out;
    }

    /**
     * Emits the 'undelivered' receipts for connection messages that expired
     * with nobody listening. A NEEDS_RECEIPT message dying is a failed
     * connection attempt, so its sender gets a signal naming the peer and the
     * type - otherwise an invite nobody picks up evaporates behind its
     * ok:true and the inviter waits forever.
     *
     * APCu expires the messages itself and cannot announce it, so what is
     * swept are the watch entries (see outKey). That is a keyspace scan,
     * hence the once-a-second gate across the whole pool: apcu_add() succeeds
     * for exactly one caller per window, which bounds the scan by time rather
     * than by traffic.
     */
    private static function sweep(): void
    {
        if (apcu_add(self::PREFIX . 'sweep', 1, 1) !== true) {
            return;
        }
        $now = time();
        $due = [];
        foreach (new APCUIterator('/^' . preg_quote(self::PREFIX . 'out:', '/') . '/') as $e) {
            $v = $e['value'];
            if (!is_array($v) || $now < (int)$v['due']) {
                continue;
            }
            // apcu_delete wins for exactly one caller, so the receipt is sent
            // once even if two sweeps overlap on the second boundary.
            if (apcu_delete($e['key'])) {
                $due[] = $v;
            }
        }
        foreach ($due as $v) {
            // FROM the peer that never picked it up, so the client can
            // correlate it. Written past the mailbox cap on purpose: a flood
            // must not swallow the message that says the connection failed.
            // Bounded - one per connection message the player sent itself.
            $to = (string)$v['f'];
            $seq = apcu_inc(self::seqKey($to), 1, $ok, self::SEQ_TTL);
            apcu_store(
                self::msgPrefix($to) . sprintf('%012d', $seq),
                [
                    'f' => (string)$v['to'],
                    't' => 'undelivered',
                    'p' => (string)json_encode([
                        'event' => 'undelivered',
                        'peer' => (string)$v['to'],
                        'type' => (string)$v['t'],
                    ]),
                    'c' => $now,
                ],
                Settings::int('signal_ttl')
            );
        }
    }

    /**
     * Drops every mailbox. Test-suite housekeeping only: there is no
     * production caller and none should be added - a live server clearing
     * all mailboxes would strand every handshake in flight.
     */
    public static function purgeAll(): void
    {
        foreach (new APCUIterator('/^' . preg_quote(self::PREFIX, '/') . '/') as $e) {
            apcu_delete($e['key']);
        }
    }

    /**
     * Places a message in a mailbox stamped in the past, so a test can reach
     * the expiry paths without sleeping. Test-suite only, like purgeAll().
     */
    public static function sendAged(string $from, string $to, string $type, string $payload, int $ageSec): void
    {
        $created = time() - $ageSec;
        $ttl = Settings::int('signal_ttl');
        $seq = apcu_inc(self::seqKey($to), 1, $ok, self::SEQ_TTL);
        // The entry keeps a live cache TTL even though its stamp is old:
        // take() filters on the stamp, which is what the contract is about.
        apcu_store(
            self::msgPrefix($to) . sprintf('%012d', $seq),
            ['f' => $from, 't' => $type, 'p' => $payload, 'c' => $created],
            $ttl
        );
        if (in_array($type, self::NEEDS_RECEIPT, true)) {
            apcu_store(self::outKey($to, $seq),
                ['f' => $from, 'to' => $to, 't' => $type, 'due' => $created + $ttl], $ttl + 300);
        }
    }

    /** Lets a test run the receipt sweep without waiting out its 1 s gate. */
    public static function sweepNow(): void
    {
        apcu_delete(self::PREFIX . 'sweep');
        self::sweep();
    }
}
