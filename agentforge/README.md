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
| Red Team agent | ✅ runs end-to-end (offline mock + ready for live target) |
| Target HTTP client (OpenEMR auth) | ⚠️ built; **auth handshake needs live verification** |
| Judge / Orchestrator / Documentation agents | 🔜 specified in ARCHITECTURE, scheduled next |
| Regression harness / observability store | 🔜 specified, scheduled next |

## Quick start

```bash
cd agentforge
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt

# Run the contract + agent tests (no network needed):
PYTHONPATH=src pytest tests/ -q

# Run a Red Team campaign against the OFFLINE mock target (no network):
PYTHONPATH=src python -m agentforge.cli redteam --dry-run --category prompt_injection
# Model a regressed build that leaks, to see attempts the Judge would flag:
PYTHONPATH=src python -m agentforge.cli redteam --dry-run --mock-policy leaky
```

## Run against the live target

Requires (a) network egress to the Railway host and (b) target credentials.

```bash
cp .env.example .env      # fill in AGENTFORGE_TARGET_USERNAME/PASSWORD
PYTHONPATH=src python -m agentforge.cli redteam --category prompt_injection
```

> The live client performs an OpenEMR login + CSRF-token handshake. That
> handshake is the one part that must be validated against the running target —
> see [`HANDOFF.md`](HANDOFF.md) → "Target auth".

## Layout

```
agentforge/
  THREAT_MODEL.md ARCHITECTURE.md USERS.md HANDOFF.md README.md
  contracts/v1/          # versioned inter-agent message schemas + errors
  evals/cases/           # seed adversarial suite (JSON, schema-validated)
  src/agentforge/
    config.py            # env -> typed config
    contracts/models.py  # pydantic models mirroring the JSON Schemas
    target/client.py     # OpenEMR live client + offline mock
    agents/redteam.py    # Red Team agent (generate + mutate + drive target)
    cli.py               # `python -m agentforge.cli redteam ...`
  tests/                 # contract tests + red-team e2e (all green)
```
