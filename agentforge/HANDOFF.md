# AgentForge — Handoff

**Read this first.** It lets a fresh agent in the network-enabled environment
continue this build without re-deriving anything.

## TL;DR

- **Project:** AgentForge — a multi-agent adversarial security platform that
  attacks the OpenEMR Clinical Co-Pilot (Gauntlet Week 3).
- **Target (live):** `https://abundant-art-production-d560.up.railway.app`
- **Deadlines:** MVP **Tue 2026-07-21 23:59**, Final **Fri 2026-07-24 12:00**.
- **Branch:** `claude/agentforge-adversarial-platform-up8w9m` (all work lives in
  `/agentforge/`). **`git pull` this branch first** — a fresh clone won't have it.
- **Locked decisions (A/A/A):** self-contained `/agentforge/` dir · Python +
  LangGraph · Red Team is the live MVP agent.

## Why this handoff exists (environment note)

The session that built this ran in an environment whose egress policy is
**Trusted**, which does **not** include Railway — every request to the target
got a `403 CONNECT` (policy denial). Rather than route around it, the target-
independent work was built here and handed off. **A new cloud environment was
created with Custom egress allowlisting `*.up.railway.app` (+ defaults).** You
are (or should be) running in that environment. **First action: confirm the
target is reachable** (below). If it still 403s, the allowlist didn't take —
verify the env's Network access = Custom, `*.up.railway.app` + `*.railway.app`
listed, "include defaults" checked, and that you started a *new* session after
saving.

## Step 0 — verify reachability + auth (the one live-only task)

```bash
# a) reachability
curl -sS -o /dev/null -w "%{http_code}\n" https://abundant-art-production-d560.up.railway.app/interface/login/login.php
# expect 200 (not 000/403)
```

Then verify the **OpenEMR login + CSRF handshake**, which is the ONE piece of
the target client that was written to convention but not verified live
(`src/agentforge/target/client.py`, `OpenEmrTargetClient.login` + `_ensure_csrf`):

1. Get target credentials (default OpenEMR dev is `admin`/`pass`; confirm what
   the Railway deploy uses). Put them in `.env`
   (`AGENTFORGE_TARGET_USERNAME` / `_PASSWORD`).
2. Confirm the **login POST** path/fields. The client posts
   `authUser/clearPass/authProvider/languageChoice` to
   `interface/main/main_screen.php?auth=login`. If OpenEMR's login flow on this
   deploy differs (some versions post to `interface/main/main_screen.php` with a
   pre-fetched login CSRF, or require `new_login_session_management`), adjust
   `login()` until the session cookie is set.
3. Confirm the **CSRF form-token source**. `_ensure_csrf()` scrapes
   `name="csrf_token_form" value="..."` from `dashboard.php`. If that page
   doesn't render one for the logged-in user (dashboard is admin-gated), scrape
   it from a page the clinician role renders instead (e.g. the chat UI page that
   `ModuleManagerListener` wires into the patient menu). Point `_ensure_csrf` at
   whichever page actually embeds the token.
4. Smoke test live:
   ```bash
   PYTHONPATH=src python -m agentforge.cli redteam --category prompt_injection --max-attempts 4
   ```
   You should see real target responses (not the mock). Pick a real `pid` that
   exists on the deploy with `--pid`.

> Until step 0 passes, everything still runs offline via `--dry-run` (mock
> target), so you are never blocked from developing the other agents.

## What is DONE (all committed on the branch, tests green)

| Artifact | Path | Notes |
|---|---|---|
| Threat model | `THREAT_MODEL.md` | ~500-word summary + full surface map, OWASP-mapped. Grounded in real recon of the module. |
| Architecture | `ARCHITECTURE.md` | 4-agent design, mermaid diagram, orchestration, judge-drift control, build-vs-configure, AI-use disclosure. |
| Users | `USERS.md` | AppSec / Eng Lead / CISO + UC1–UC6. |
| Contracts (v1) | `contracts/v1/*.schema.json` | Orchestrator→RedTeam, RedTeam→Judge, Judge→Doc, typed errors. Versioning policy in `contracts/README.md`. |
| Contract tests | `tests/test_contracts.py`, `test_models_roundtrip.py` | schemas valid + golden examples + model round-trips. |
| Seed eval suite | `evals/cases/*.json` (17 cases, 5 categories) | OWASP-tagged; each maps to boundary/invariant/regression dimension. `evals/schema.json` enforces shape + unique ids. |
| Red Team agent | `src/agentforge/agents/redteam.py` | generate + 4 deterministic mutation operators (+ optional LLM variants); drives multi-turn; emits contract-valid `AttackAttempt`. |
| Target client | `src/agentforge/target/client.py` | `OpenEmrTargetClient` (live, needs step 0) + `MockTargetClient` (offline, defended/leaky policies). |
| CLI | `src/agentforge/cli.py` | `redteam` command, `--dry-run` offline mode. |
| Red Team e2e tests | `tests/test_redteam_e2e.py` | seed+mutations valid, budget respected, leaky target caught. |

Run everything: `cd agentforge && PYTHONPATH=src pytest tests/ -q` → **17 passed**.

## What is NEXT (priority order)

### To finish the MVP (by Tue night)
The MVP hard gates are already largely met on paper (threat model ✅, evals ✅,
one agent role ✅, architecture ✅). Remaining for a *live* MVP:
1. **Step 0** — get the Red Team firing against the live target. This is the
   only thing between "runs on mock" and the MVP "agent running live against the
   deployed target" gate.
2. Capture a short live run's `runs/*.attempts.jsonl` as evidence.
3. Submit the deployed URL with the checkpoint (hard gate, every checkpoint).

### For the Final (Wed–Fri) — specified in ARCHITECTURE.md, not yet built
4. **Judge agent** (`agents/judge.py`) — independent frontier model; consumes
   `AttackAttempt`, emits `Verdict` (schema already exists). Add the versioned
   rubric + a ground-truth set of labeled attempts to detect drift.
5. **Orchestrator** (`agents/orchestrator.py`) — coverage/severity scoring over
   the observability store; emits `AttackCampaignDirective`; budget + halt
   (`no_findings_in_window`, `budget_exceeded`); triggers regression on target
   version change.
6. **Documentation agent** (`agents/documentation.py`) — `Verdict(success)` →
   structured vuln report; data-quality validation (unique id, required fields,
   no dup attack sequences); **human gate on critical**.
7. **Regression harness** — promote confirmed exploits to deterministic cases;
   pass = the *invariant* holds (not a string match); re-run siblings to catch
   cross-category regressions.
8. **Observability store** — append-only run log keyed by `correlation_id`;
   answers coverage/pass-rate/trend/open-findings/cost/timeline. This is also
   the Orchestrator's input — build it before/with the Orchestrator.
9. **LangGraph wiring** — connect the four agents as a graph with the typed
   messages as edges.
10. **Deterministic probes** (configure, don't build) — a small HTTP probe
    harness for the unauth endpoints (`health.php`, `ready.php`) and the
    IDOR/forged-pid cases; these are cheaper than LLM attempts (see threat model
    §5, §2).

### Submission artifacts still owed (Final)
- **≥3 vulnerability reports** (Documentation output; format in ARCHITECTURE).
- **AI cost analysis** at 100/1K/10K/100K runs → `docs/COST_ANALYSIS.md`
  (there's an existing co-pilot `ops/cost-analysis.md` to reference for style).
- **Triage exercise** — simulated 10-finding scan report (crit/high/med/false-pos)
  with validate/remediate/defer/document decisions.
- **ATO-style evidence packet** + **integration packet** + baseline perf +
  100-case load test with identified bottleneck.
- **Demo video (3–5 min)** + **social post** (final only, tag @GauntletAI).

## Credentials / config the next agent needs

Fill `agentforge/.env` (copy from `.env.example`):
- `AGENTFORGE_TARGET_USERNAME` / `_PASSWORD` — the deploy's login (try `admin`/`pass`).
- `REDTEAM_*` — a **local/open** model endpoint (Ollama `llama3.1:8b` by
  default) so the Red Team won't refuse offensive prompts and stays cheap. If no
  local model is available, the Red Team still runs on the deterministic
  mutation operators.
- `JUDGE_*` — an **independent** frontier model API key (kept separate from the
  Red Team by design). Needed once you build the Judge (item 4).

## Gotchas (from recon — see THREAT_MODEL.md)

- **`agent.php` is the soft target:** tools are live there and it is **not**
  per-user rate-limited (only $50/day, $10/hr caps + circuit breaker). Best
  surface for tool/DoS tests — but that means *you* can burn the target's budget
  too; keep `--max-attempts` low on live runs and respect the breaker.
- **Chat tools are dormant** — don't waste tool-tampering attempts on `chat.php`.
- **Prompt-only refusals** (other-patient, general-medical, diagnosis) have **no
  verifier backstop** — these are the highest-yield live targets (`AF-EXF-001`,
  `AF-ID-001`).
- **Don't over-invest in the guarded invariants** (forged pid, fact-citation);
  recon shows they're structural. A couple of invariant tests each, then move on.
- **Never disable TLS / unset the proxy.** A `403 CONNECT` is a policy denial,
  not a bug to route around — fix the egress allowlist instead.

## Branch & push

All work is on `claude/agentforge-adversarial-platform-up8w9m`. Commit with
Conventional Commits + `Assisted-by: Claude Code`. Push with
`git push -u origin claude/agentforge-adversarial-platform-up8w9m`. Do not open
a PR unless asked.
