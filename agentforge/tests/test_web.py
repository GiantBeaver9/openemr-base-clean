"""Web dashboard: read helpers, path-traversal guard, and a live server smoke
test that drives a dry-run campaign through the HTTP API (no network)."""
import json
import sys
import threading
import time
import urllib.request
from http.server import ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from agentforge import web


def test_categories_and_traversal_guard():
    cats = web._categories()
    assert "prompt_injection" in cats and "data_exfiltration" in cats
    # Path traversal is stripped to a basename -> the etc file never resolves.
    assert web._read_json_file("../../../../etc/passwd") is None
    assert web._read_json_file("does-not-exist.json") is None


def _free_port():
    import socket
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    port = s.getsockname()[1]
    s.close()
    return port


def test_dashboard_serves_and_runs_dry_campaign():
    port = _free_port()
    server = ThreadingHTTPServer(("127.0.0.1", port), web.Handler)
    t = threading.Thread(target=server.serve_forever, daemon=True)
    t.start()
    base = f"http://127.0.0.1:{port}"
    try:
        # index + state
        assert b"AgentForge" in urllib.request.urlopen(base + "/").read()
        state = json.loads(urllib.request.urlopen(base + "/api/state").read())
        assert "prompt_injection" in state["categories"]
        assert state["live_caps"]["max_attempts"] >= 1

        # launch a dry-run leaky campaign (offline mock -> guaranteed findings)
        req = urllib.request.Request(
            base + "/api/campaign",
            data=json.dumps({"dry_run": True, "mock_policy": "leaky",
                             "rounds": 1, "max_attempts": 3}).encode(),
            headers={"Content-Type": "application/json"}, method="POST")
        job_id = json.loads(urllib.request.urlopen(req).read())["job_id"]

        result = None
        for _ in range(50):  # up to ~5s
            job = json.loads(urllib.request.urlopen(base + f"/api/job/{job_id}").read())
            if job["status"] in ("done", "error"):
                result = job
                break
            time.sleep(0.1)
        assert result is not None and result["status"] == "done", result
        assert result["result"]["summary"]["open_findings"] >= 1
        assert result["result"]["reports"]              # leaky target -> reports
    finally:
        server.shutdown()


def test_unknown_job_is_404():
    port = _free_port()
    server = ThreadingHTTPServer(("127.0.0.1", port), web.Handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    try:
        try:
            urllib.request.urlopen(f"http://127.0.0.1:{port}/api/job/nope")
            assert False, "expected 404"
        except urllib.error.HTTPError as e:
            assert e.code == 404
    finally:
        server.shutdown()
