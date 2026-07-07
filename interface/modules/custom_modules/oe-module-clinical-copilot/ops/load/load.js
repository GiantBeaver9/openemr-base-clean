// Clinical Co-Pilot — k6 load test (ARCHITECTURE.md §3.6, R9).
//
// Drives the two physician-facing hot paths under concurrency:
//   1. GET  doc.php?pid=  — the WARM pre-visit synthesis page.
//   2. POST chat.php      — one agent turn (a follow-up drill-down).
//
// Parameterized for 10 and 50 concurrent virtual users (VUS env var). Records
// p50/p95/p99 latency and error rate per path, plus a combined summary.
//
// SYNTHETIC PATIENTS ONLY (OPEN-1): point PID at a synthetic patient. Never run
// this against a database containing real PHI.
//
// Run (see ops/README.md for the openemr-cmd in-stack wrapper):
//   k6 run -e VUS=10 -e DURATION=1m -e BASE_URL=https://localhost:9300 -e PID=1 load.js
//   k6 run -e VUS=50 -e DURATION=1m -e BASE_URL=https://localhost:9300 -e PID=1 load.js
//
// The dev stack serves a self-signed cert, so TLS verification is disabled
// below (insecureSkipTLSVerify). Do NOT reuse this option against a real host.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

// ---- Parameters -------------------------------------------------------------
const BASE_URL = __ENV.BASE_URL || 'https://localhost:9300';
const MODULE_BASE =
  BASE_URL + '/interface/modules/custom_modules/oe-module-clinical-copilot/public';
const LOGIN_URL = BASE_URL + '/interface/main/main_screen.php';
const SITE = __ENV.SITE || 'default';
const USERNAME = __ENV.USERNAME || 'admin';
const PASSWORD = __ENV.PASSWORD || 'pass';
const PID = __ENV.PID || '1';
const VUS = parseInt(__ENV.VUS || '10', 10);
const DURATION = __ENV.DURATION || '1m';
const CHAT_MESSAGE =
  __ENV.CHAT_MESSAGE || 'What were the most recent lab results on file for this patient?';

// ---- Custom metrics ---------------------------------------------------------
const docLatency = new Trend('doc_latency', true);
const chatLatency = new Trend('chat_latency', true);
const bootstrapLatency = new Trend('bootstrap_latency', true);
const errorRate = new Rate('errors');
const chatServerErrors = new Counter('chat_server_errors');

// ---- Options ----------------------------------------------------------------
export const options = {
  insecureSkipTLSVerify: true,
  scenarios: {
    copilot: {
      executor: 'constant-vus',
      vus: VUS,
      duration: DURATION,
    },
  },
  // p50/p95/p99 requested by §3.6 — add med/p(50)/p(99) to the default stats.
  summaryTrendStats: ['avg', 'min', 'med', 'p(50)', 'p(95)', 'p(99)', 'max'],
  thresholds: {
    // Informational gates; tune against committed baselines (ops/RESULTS.md).
    errors: ['rate<0.05'],
    doc_latency: ['p(95)<15000'],
    chat_latency: ['p(95)<30000'],
  },
};

// ---- Per-VU state (each k6 VU is its own JS isolate) ------------------------
let csrfToken = null;

// Log in (session cookie lands in this VU's cookie jar) and scrape the CSRF
// token from the chat page. Returns the token, or null on failure.
function bootstrap() {
  const started = Date.now();

  const loginRes = http.post(
    `${LOGIN_URL}?auth=login&site=${encodeURIComponent(SITE)}`,
    {
      new_login_session_management: '1',
      authUser: USERNAME,
      clearPass: PASSWORD,
      languageChoice: '1',
      site: SITE,
    },
    { tags: { name: 'login' }, redirects: 5 },
  );
  const loggedIn = check(loginRes, {
    'login not on login screen': (r) => !/name="clearPass"/.test(r.body || ''),
  });

  const csrfRes = http.get(`${MODULE_BASE}/chat.php?pid=${encodeURIComponent(PID)}`, {
    tags: { name: 'fetch_csrf' },
  });
  bootstrapLatency.add(Date.now() - started);

  const body = String(csrfRes.body || '');
  let m = body.match(/name="csrf_token_form"\s+value="([^"]+)"/);
  if (!m) {
    m = body.match(/data-csrf="([^"]+)"/);
  }
  const token = m ? m[1] : null;
  if (!loggedIn || !token) {
    errorRate.add(1);
  }
  return token;
}

export default function () {
  if (csrfToken === null) {
    csrfToken = bootstrap();
    if (!csrfToken) {
      // Could not authenticate — count it and back off so a broken env does
      // not spin at full rate.
      sleep(1);
      return;
    }
  }

  // 1. Warm synthesis doc.
  const docRes = http.get(`${MODULE_BASE}/doc.php?pid=${encodeURIComponent(PID)}`, {
    tags: { name: 'doc_warm' },
  });
  docLatency.add(docRes.timings.duration);
  const docOk = check(docRes, { 'doc 200': (r) => r.status === 200 });
  errorRate.add(!docOk);

  // 2. One chat turn.
  const chatRes = http.post(
    `${MODULE_BASE}/chat.php`,
    {
      message: CHAT_MESSAGE,
      pid: PID,
      session_id: '0',
      csrf_token_form: csrfToken,
      transport: 'json',
    },
    { headers: { Accept: 'application/json' }, tags: { name: 'chat_turn' } },
  );
  chatLatency.add(chatRes.timings.duration);
  // A 4xx (clean refusal / guard) is a healthy non-crash; only 5xx is an error.
  const chatOk = check(chatRes, { 'chat < 500': (r) => r.status < 500 });
  if (chatRes.status >= 500) {
    chatServerErrors.add(1);
  }
  errorRate.add(!chatOk);

  sleep(1);
}
