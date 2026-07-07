# Clinical Co-Pilot — Ops Artifacts (U13)

Runnable operational artifacts for the Clinical Co-Pilot module: an API
collection (R5) and a load/baseline harness (R8, R9).

> **SYNTHETIC PATIENTS ONLY (OPEN-1).** Everything here targets synthetic
> patients. Never point these tools at a database containing real PHI. A named
> redaction/BAA review is a hard gate before any real-PHI deployment
> (ARCHITECTURE.md §4).

```
ops/
├── README.md            ← this file
├── RESULTS.md           ← committed placeholder tables for baseline + load numbers
├── bruno/               ← Bruno API collection (R5)
│   ├── bruno.json
│   ├── collection.bru
│   ├── environments/Local.bru
│   ├── Auth Bootstrap/  ← login + CSRF token bootstrap (run first)
│   └── Endpoints/       ← health, ready, doc, chat
└── load/                ← load + baseline harness (R8, R9)
    ├── load.js          ← k6 script (10 & 50 VUs)
    ├── load.sh          ← portable bash+curl fallback (no k6 needed)
    └── baseline.sh      ← single-user CPU/mem/latency baselines
```

## The four endpoints (real module-page URLs)

The design doc's `/copilot/*` names are shorthand for these real pages under
`interface/modules/custom_modules/oe-module-clinical-copilot/public/`:

| Shorthand | Real URL | Method | Auth |
|-----------|----------|--------|------|
| `/copilot/doc/:pid` | `public/doc.php?pid=<pid>` | GET | session |
| `/copilot/chat` | `public/chat.php` | POST | session + CSRF |
| `/copilot/health` | `public/health.php` | GET | none |
| `/copilot/ready` | `public/ready.php` | GET | none |

## Auth bootstrap (why the first folder exists)

Every Co-Pilot surface is session-authenticated, and `chat.php` also requires a
CSRF token (ARCHITECTURE.md §1.3). So a grader cannot just fire the endpoints —
they must first:

1. **Log in.** POST credentials to `interface/main/main_screen.php?auth=login&site=default`
   with `new_login_session_management=1`, `authUser`, `clearPass`, `languageChoice=1`.
   OpenEMR's new-login path needs **no** CSRF token; on success it sets a fresh
   session cookie.
2. **Fetch the CSRF token.** GET `public/chat.php?pid=<synthetic_pid>` and scrape
   the `csrf_token_form` hidden field (the chat page renders it). That token is
   submitted by the chat POST; without it the POST is rejected (HTTP 400).

Both the Bruno collection and the load scripts do exactly this, in that order.

## Running the Bruno collection (R5)

The collection lives in `ops/bruno/` and runs with the Bruno CLI (`bru`) —
no need to read module source.

```bash
npm install -g @usebruno/cli        # provides the `bru` command

cd ops/bruno
# Edit environments/Local.bru: set base_url and synthetic_pid for your stack.

# The dev stack serves a self-signed cert, so pass --insecure.
bru run --env Local --insecure
```

`bru run` executes the folders in order: **Auth Bootstrap** (Login → Fetch CSRF
Token) then **Endpoints** (Health → Ready → Doc → Chat turn). The login response
cookie is carried automatically by Bruno's cookie jar; the CSRF token is stored
into the `csrf_token` env var by a post-response script and reused by the chat
request.

Run a single request or folder:

```bash
bru run "Endpoints/Health.bru" --env Local --insecure
bru run "Auth Bootstrap" --env Local --insecure
```

Or open `ops/bruno/` in the Bruno desktop app, pick the **Local** environment,
and run requests interactively.

Notes:
- The environment file is `environments/Local.bru` — Bruno's native `.bru`
  format (not JSON). `bruno.json` (the collection manifest) is JSON.
- `synthetic_pid` defaults to `1`. Set it to your synthetic patient. The Doc and
  Chat requests need a valid synthetic pid; Health and Ready need none.
- `csrf_token` is a runtime secret var, populated by the bootstrap.

## Running the load tests (R9)

Both drivers hit the **warm doc** + **one chat turn** per iteration and report
p50/p95/p99 latency and error rate. Run at **10 and 50** concurrent users.

### k6 (preferred)

```bash
# Install k6: https://k6.io/docs/get-started/installation/
cd ops/load

k6 run -e VUS=10 -e DURATION=1m -e BASE_URL=https://localhost:9300 -e PID=1 load.js
k6 run -e VUS=50 -e DURATION=1m -e BASE_URL=https://localhost:9300 -e PID=1 load.js
```

k6 prints a summary with `p(50)`, `p(95)`, `p(99)` for the `doc_latency` and
`chat_latency` trends plus the `errors` rate. TLS verification is disabled in
the script (self-signed dev cert).

Env vars: `BASE_URL`, `PID`, `VUS`, `DURATION`, `SITE`, `USERNAME`, `PASSWORD`,
`CHAT_MESSAGE`.

### bash + curl fallback (no k6)

```bash
cd ops/load
VUS=10 DURATION=60 BASE_URL=https://localhost:9300 PID=1 ./load.sh
VUS=50 DURATION=60 BASE_URL=https://localhost:9300 PID=1 ./load.sh
```

Each virtual user logs in independently (its own session), fetches a CSRF
token, then loops for `DURATION` seconds. Aggregates p50/p95/p99 + error rate
per path with `awk`. `INSECURE=1` (default) passes `-k` to curl for the
self-signed cert.

### Baselines (R8)

```bash
cd ops/load
BASE_URL=https://localhost:9300 WARM_PID=1 COLD_PID=2 \
  DOCKER_CONTAINER=development-easy-openemr-1 ./baseline.sh
```

Captures single-user latency for warm/cold synthesis and chat with 0/1/3 tool
calls, plus an app-container CPU/mem sample when `DOCKER_CONTAINER` is set
(otherwise `n/a`). Tool-call counts are nominal (prompt intent) — confirm the
actual count from the turn's trace via `status.php?cid=<correlation_id>`.

## Capturing in-stack via openemr-cmd

Per §3.6 the numbers are captured against the deployed stack. The scripts are
host-agnostic HTTP drivers, so run them from the host pointed at the stack URL,
or from inside the app container. For CPU/mem, pass the app container name via
`DOCKER_CONTAINER` (in-stack, `openemr-cmd` resolves the container for you). To
run inside the container:

```bash
# example — adjust to your worktree / stack
openemr-cmd e 'bash /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-clinical-copilot/ops/load/baseline.sh'
```

Paste the measured output into `ops/RESULTS.md` (which currently holds only
placeholders — do not fabricate numbers).
