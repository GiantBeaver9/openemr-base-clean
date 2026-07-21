# AgentForge — Adversarial AI Security Platform

A multi-agent system that continuously discovers, evaluates, documents, and
regression-guards vulnerabilities in the OpenEMR **Clinical Co-Pilot**
(`oe-module-clinical-copilot`).

- **Target under test:** https://abundant-art-production-d560.up.railway.app
- **Architecture:** [`ARCHITECTURE.md`](ARCHITECTURE.md) — the 4-agent design
- **Threat model:** [`THREAT_MODEL.md`](THREAT_MODEL.md) — the attack surface
- **Users:** [`USERS.md`](USERS.md)
- **Contracts:** [`contracts/`](contracts/) — versioned inter-agent JSON Schemas
- **Seed attack suite:** [`evals/`](evals/) — 17 cases across 5 categories
- **Continue this build:** [`HANDOFF.md`](HANDOFF.md)

## Status

| Component | State |
|---|---|
| Threat model | ✅ complete |
| Inter-agent contracts (v1) + tests | ✅ complete, tests green |
| Seed eval suite (5 categories, OWASP-tagged) | ✅ complete |
| Red Team agent | ✅ **verified live** against the deployed target |
| Target HTTP client (OpenEMR auth) | ✅ **auth + CSRF handshake verified live** |
| Judge agent (rubric `1.0.0` + ground-truth drift check) | ✅ complete, tests green |
| Orchestrator (coverage/severity scoring + budget/halt) | ✅ complete, tests green |
| Documentation agent (report + regression case + human gate) | ✅ complete, tests green |
| Regression harness (invariant replay + siblings) | ✅ complete, tests green |
| Observability store (append-only, deterministic rollups) | ✅ complete, tests green |
| LangGraph pipeline wiring (4 agents over typed edges) | ✅ complete (`pipeline.py`) |

All four agents and the deterministic substrate are implemented — **39 passing
tests** — and the full loop has been run live against the deployed co-pilot
(which defended the seeded attacks). Remaining work is submission packaging
(cost analysis, triage exercise, ATO/load evidence, demo) — see
[`HANDOFF.md`](HANDOFF.md).

## Quick start

```bash
cd agentforge
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt

# Run the contract + agent tests (no network needed):
PYTHONPATH=src pytest tests/ -q

# Run the FULL multi-agent loop against the OFFLINE mock target (no network):
PYTHONPATH=src python -m agentforge.cli campaign --dry-run --mock-policy defended
# Model a regressed build that leaks, to see the Judge confirm + Docs report it:
PYTHONPATH=src python -m agentforge.cli campaign --dry-run --mock-policy leaky

# Red Team only, or re-judge / inspect a captured run:
PYTHONPATH=src python -m agentforge.cli redteam --dry-run --category prompt_injection
PYTHONPATH=src python -m agentforge.cli judge runs/<campaign>.attempts.jsonl
PYTHONPATH=src python -m agentforge.cli dashboard runs/<campaign>.observability.jsonl
```

## Run against the live target

Requires (a) network egress to the Railway host and (b) target credentials.

```bash
cp .env.example .env      # fill in AGENTFORGE_TARGET_USERNAME/PASSWORD (admin/pass on the dev deploy)
# Full loop (Orchestrator -> Red Team -> Judge -> Documentation), low budget:
PYTHONPATH=src python -m agentforge.cli campaign --pid 1 --rounds 2 --max-attempts 4
```

> The live client performs the OpenEMR login + CSRF-token handshake (verified
> against the deployed module's own bruno auth flow): login posts to
> `interface/main/main_screen.php?auth=login`, and the CSRF form token is scraped
> from the module's `doc.php?pid=<pid>` chat panel. Keep `--max-attempts` low on
> live runs — `agent.php`/`chat.php` run real LLM calls behind a shared budget
> breaker. See [`HANDOFF.md`](HANDOFF.md) → "Step 0".

## Layout

```
agentforge/
  THREAT_MODEL.md ARCHITECTURE.md USERS.md HANDOFF.md README.md
  contracts/v1/          # versioned inter-agent message schemas + errors
  evals/cases/           # seed adversarial suite (JSON, schema-validated)
  evals/ground_truth.json# labeled attempts pinning the Judge rubric (drift check)
  src/agentforge/
    config.py            # env -> typed config
    contracts/models.py  # pydantic models mirroring the JSON Schemas
    target/client.py     # OpenEMR live client (verified) + offline mock
    observability/store.py  # append-only run log + deterministic rollups
    agents/redteam.py    # Red Team: generate + mutate + drive target
    agents/judge.py      # Judge: independent verdict + rubric + drift check
    agents/documentation.py # Documentation: report + regression case + human gate
    agents/orchestrator.py  # Orchestrator: scoring + budget/halt + regression trigger
    regression.py        # invariant-based regression replay
    pipeline.py          # wires the 4 agents (LangGraph-compatible)
    cli.py               # redteam | campaign | judge | dashboard
  tests/                 # contracts, models, redteam, observability, judge,
                         # documentation, orchestrator+pipeline, regression (39 green)
```
