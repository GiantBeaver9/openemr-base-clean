"""AgentForge CLI — run a Red Team campaign against the target.

Examples:
    # Offline dry-run (no network; uses the mock target) — works anywhere:
    python -m agentforge.cli redteam --dry-run --category prompt_injection

    # Live run against the deployed target (requires egress + target creds):
    python -m agentforge.cli redteam --category prompt_injection
"""
from __future__ import annotations

import argparse
import glob
import json
import sys
from pathlib import Path
from uuid import uuid4

ROOT = Path(__file__).resolve().parents[2]
CASES_DIR = ROOT / "evals" / "cases"

sys.path.insert(0, str(ROOT / "src"))

from agentforge import config as cfgmod          # noqa: E402
from agentforge.agents.redteam import RedTeamAgent, SeedCase  # noqa: E402
from agentforge.target.client import MockTargetClient, OpenEmrTargetClient  # noqa: E402


def _load_seed_cases(category: str | None) -> list[SeedCase]:
    seeds: list[SeedCase] = []
    for f in sorted(glob.glob(str(CASES_DIR / "*.json"))):
        for d in json.loads(Path(f).read_text()):
            if category and d["attack_category"] != category:
                continue
            # skip cases that need a deterministic (non-LLM) probe harness
            if d["target_surface"] in ("chat", "agent"):
                seeds.append(SeedCase.from_eval(d))
    return seeds


def _directive(category: str | None, max_attempts: int, max_turns: int) -> dict:
    return {
        "directive_id": f"dir-{uuid4().hex[:8]}",
        "campaign_id": f"camp-{uuid4().hex[:8]}",
        "correlation_id": f"camp-{uuid4().hex[:8]}",
        "attack_category": category or "prompt_injection",
        "target_surface": "chat",
        "rationale": "coverage_gap",
        "priority": 5,
        "max_turns": max_turns,
        "budget": {"max_attempts": max_attempts, "max_usd": 1.0},
    }


def cmd_redteam(args: argparse.Namespace) -> int:
    cfg = cfgmod.load()
    if args.dry_run:
        target = MockTargetClient(policy=args.mock_policy)
        print(f"[dry-run] mock target policy={args.mock_policy}")
    else:
        client = OpenEmrTargetClient(cfg)
        client.login()
        target = client
        print(f"[live] target={cfg.target.base_url}")

    seeds = _load_seed_cases(args.category)
    if not seeds:
        print("no seed cases for that category on an LLM surface", file=sys.stderr)
        return 2
    agent = RedTeamAgent(target=target, pinned_pid=args.pid)
    directive = _directive(args.category, args.max_attempts, cfg.budget.max_turns)
    attempts = agent.run_directive(directive, seeds)

    out = ROOT / "runs"
    out.mkdir(exist_ok=True)
    path = out / f"{directive['campaign_id']}.attempts.jsonl"
    with path.open("w") as fh:
        for a in attempts:
            fh.write(json.dumps(a) + "\n")

    print(f"ran {len(attempts)} attempts across {len(seeds)} seeds -> {path}")
    for a in attempts[: args.show]:
        target_turn = next((t for t in a["turns"] if t["role"] == "target"), {})
        print(f"  {a['attempt_id']} [{a['attack_technique']:8}] "
              f"{a['attack_category']:22} -> {target_turn.get('content','')[:70]!r}")
    return 0


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(prog="agentforge")
    sub = p.add_subparsers(dest="cmd", required=True)

    rt = sub.add_parser("redteam", help="run a Red Team campaign")
    rt.add_argument("--category", default=None, help="attack_category to focus on")
    rt.add_argument("--dry-run", action="store_true", help="use the offline mock target")
    rt.add_argument("--mock-policy", default="defended", choices=["defended", "leaky"])
    rt.add_argument("--pid", type=int, default=1, help="pinned patient id")
    rt.add_argument("--max-attempts", type=int, default=25)
    rt.add_argument("--show", type=int, default=10)
    rt.set_defaults(func=cmd_redteam)

    args = p.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
