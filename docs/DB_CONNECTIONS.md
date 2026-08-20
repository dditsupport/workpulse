# `Too many connections` — where the connections come from

`mysqli_sql_exception: Too many connections` means MySQL refused a new
connection because the account was already at `max_user_connections` (or the
server at `max_connections`). It is a *concurrency* limit, not a leak in the
usual sense: PHP closes every connection when the script ends, so nothing
survives a request. What blows the limit is **how many requests are in flight
at the same time, and how long each one holds its connection.**

That is why "close the connection after each query" is not the fix here — and
would make things worse. Reconnecting per query multiplies handshakes without
lowering the peak, and PHP would still hold one open the whole request. The
levers that actually work are:

1. **One connection per request.** Not one per query, not one per helper.
2. **Open it late, drop it early.** Never hold a connection while waiting on
   something slow that does not need it (SMTP, SMS, an HTTP API).
3. **Fewer requests.** A background poller that fires every few seconds costs
   a connection every few seconds, per open browser tab.
4. **No persistent connections.** `PDO::ATTR_PERSISTENT` / `p:` DSNs keep a
   connection alive between requests, so idle workers sit on slots. On shared
   hosting this is the single most common cause of this exact error.

## Where the connection layer lives

`getDb()` and `getSetting()` are **not in this repository**. They are defined
in the credentials file kept outside the web root, the one located by
`wp_f/config.php` and `wp_f/api/_bootstrap.php`:

```
/home/<user>/config_wp/config.php     # DB_HOST, DB_NAME, DB_USER, DB_PASS, API_KEY
                                      # + getDb(), getSetting()
```

Two consequences worth being explicit about:

- Every `getDb()` call site in `modules/` is only as good as that file. If
  `getDb()` connects eagerly at include time, or returns a *new* PDO per call,
  every page view costs one or more connections no matter what the modules do.
- **There is no `mysqli` anywhere in this repository.** All tracked SQL goes
  through PDO. A `mysqli_sql_exception` can therefore only be raised by that
  out-of-web-root `config.php`, which means it still opens a mysqli handle —
  almost certainly a legacy `$conn = new mysqli(...)` at the top of the file,
  in addition to the PDO one. That is **two connections per request**, one of
  which nothing in this codebase uses. Deleting it halves the footprint.

## What `config.php` should look like

```php
<?php
define('DB_HOST', '…');
define('DB_NAME', '…');
define('DB_USER', '…');
define('DB_PASS', '…');
define('API_KEY', '…');

// One PDO per request, created on first use — never at include time, so a
// request that only touches the session (the policy heartbeat, a redirect,
// a 403) costs no connection at all.
function getDb(): PDO {
    if (Db::$pdo === null) {
        Db::$pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // Must stay false. A persistent connection outlives the
                // request and is what fills the account's slots.
                PDO::ATTR_PERSISTENT         => false,
            ]
        );
    }
    return Db::$pdo;
}

// Drop the connection early. Safe to call at any point where no transaction
// is open: the next getDb() just reconnects. Used by SmtpQueue::flush() in
// modules/helpers.php, which would otherwise sit on a connection for the
// whole post-response mail drain.
function closeDb(): void {
    Db::$pdo = null;
}

final class Db { public static ?PDO $pdo = null; }

// getSetting() must cache per request — it is called a dozen times on some
// pages, and each uncached call is a round trip.
function getSetting(string $key, string $default = ''): string { /* … */ }
```

The handle has to live on a class property (or a global), **not** a `static`
inside `getDb()`. A function-local static cannot be reset from outside, so
`closeDb()` becomes impossible to write.

Also remove any `new mysqli(...)` / `mysqli_connect(...)` from that file —
nothing in this repository uses it.

## What was changed in this repository

- `wp_f/api/_bootstrap.php` — the API's PDO moved off a function static onto
  `ApiDbHandle::$pdo` so `api_dbDisconnect()` can release it. Persistent
  connections explicitly disabled. `api_getSetting()` now caches per request
  (it previously issued a fresh query on every call, a dozen per OTP send),
  and `api_warmSettings()` fetches a batch in one round trip.
- `wp_f/api/send_otp.php` — releases the connection before the MSG91 / SMTP
  send. That send can block for 10–15s, and at shift change every device asks
  for an OTP at once; the connection is no longer held across the wait.
- `modules/helpers.php` — `SmtpQueue` reads its SMTP settings at enqueue time,
  so the post-response drain touches the database not at all, and calls
  `closeDb()` (once `config.php` provides it) before draining. The drain also
  has a 100s budget so one unreachable mail server cannot pin a worker for the
  full 120s limit.
- `modules/policies.php` — the policy-reader heartbeat polled every 5s. Each
  beat is a full `index.php` request. It only reports the read position, which
  is already pushed the moment that position changes, so the idle fallback now
  runs every 60s.

## Still holding a connection across slow I/O

These are on the `modules/` surface, so they can only be fixed once
`config.php` exposes `closeDb()`. Each blocks on the network with a live
connection in hand:

- `modules/offer.php` — up to two MSG91 sends per coupon (10s each).
- `modules/policies.php` — `doRequestPolicyOtp()` MSG91 send (10s).
- `modules/attendance.php` — `doRequestLocationOtp()` synchronous
  `sendSmtpEmail()` (up to 15s).

The pattern for each is the same as `wp_f/api/send_otp.php`: finish the DB
work, read whatever settings the sender needs, `closeDb()`, then send.
