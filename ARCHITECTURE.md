# Clinical Co-Pilot — Architecture

## High-Level Architecture — One-Page Summary

The Clinical Co-Pilot is an AI agent embedded in this OpenEMR fork for one deliberately narrow user: an outpatient endocrinologist reviewing twenty type-2-diabetes follow-ups before clinic. It has two surfaces sharing one machinery: a **pre-warmed, cited pre-visit synthesis** per scheduled patient, and a **multi-turn chat agent pinned to that patient**, preloaded with the exact facts and narrative the physician is reading, able to answer follow-ups by invoking tools over the chart.

**Key decision 1 — the LLM narrates and navigates; it never extracts.** Five deterministic capabilities (ControlProxy, MedResponse, VitalsTrend, OverdueTests, PendingResults) read the chart through OpenEMR's own service layer and produce *typed facts with citations* — handling corrected labs, censored values (`<7.0`), unit conversion, and pending orders in tested PHP, not in a prompt. The LLM has exactly two jobs: write prose over facts it is handed (the synthesis), and *request* which capability the program should run next (the chat). It executes nothing itself — the program pulls everything, before the visit for the summary and the chat seed, and on request for drill-downs. It never parses raw rows, never adjudicates conflicting data, and can only mis-narrate — never invent — which is what makes verification tractable.

**Key decision 2 — verification is a deterministic gate, not prompt discipline.** Every LLM output — synthesis or chat turn — passes a verifier before display: every claim must cite a fact that exists in the session fact set; every clinical **value a claim pulls** (results, readings) must appear in a cited fact — narrative numbers (dates, frequencies, disease stage, doses) are exempt; every cited fact's patient ID must equal the session's pinned patient (the wrong-patient double-check); banned claim classes (causation, recommendations, diagnoses, dosing advice) are rejected as a lexical class, with paraphrase a named residual under adversarial evals. Failure is closed: one regeneration with verifier feedback, then facts-only degradation. Unverified prose is never rendered.

**Key decision 3 — patient pinning is structural.** A chat session is bound server-side to one patient; tools receive the pinned ID from the session, never from the model. The model cannot ask about another patient because no tool accepts a patient argument — enforced twice (injection on input, verification on output).

**Key decision 4 — freshness by content-addressing.** Facts are recomputed on every read and never cached; only narratives are cached, keyed by a digest of the facts. Serving prose over facts that no longer hold is structurally unreachable. A background worker pre-warms the day's schedule, resolving the speed-versus-completeness tradeoff for the sweep; chat turns accept a few seconds because tools fetch fresh facts.

**Key decision 5 — observability from the first request.** Every invocation gets a correlation ID threaded through every capability call, LLM call, verification verdict, and log line. Per-step spans (duration, status, error, tokens, cost) land in an append-only trace table inside the module's own database — PHI never leaves the EMR's boundary for a third-party observability SaaS. An in-app dashboard shows requests, errors, p50/p95 latency, tool calls, retries, and verification pass rate, with click-through from any request to its full trace.

**Main tradeoffs accepted:** an in-process PHP module (inheriting OpenEMR's auth, ACL, CSRF, and audit logging) over a separate service; **Google Gemini on Vertex AI** — the HIPAA-eligible, BAA-covered surface, chosen for provider-enforced JSON schemas and context caching that makes multi-turn cost sane (T18); vertical-first scaling on GCP (T20); deterministic verification that cannot catch misleading emphasis — bounded by facts-first rendering and adversarial evals; synthetic patients only until a real redaction story exists.

---

**Companion documents:** [USERS.md](USERS.md) (target user + use cases UC1–UC6 — the source of truth every capability traces to) · [ARCHITECTURE_COMPLETE.md](ARCHITECTURE_COMPLETE.md) (the complete build spec: fact layer, lab contracts, digest model, build units) · [docs/clinical-copilot-tradeoffs.md](docs/clinical-copilot-tradeoffs.md) (decision record T1–T21, rejected alternatives).

---

## System Overview

```
                    ┌──────────────────────── OpenEMR (host) ────────────────────────┐
                    │  auth/session · AclMain ACL · CsrfUtils · EventAuditLogger      │
                    │  clinical tables (READ-ONLY): procedure_*, prescriptions,      │
                    │  lists, form_vitals, patient_data, openemr_postcalendar_events │
                    └────────────────────────────┬────────────────────────────────────┘
                                                 │ host service classes (ProcedureService,
                                                 │ PrescriptionService, VitalsService, …)
   oe-module-clinical-copilot                    ▼
   ┌─────────────────────────────────────────────────────────────────────────────────┐
   │  FACT LAYER (deterministic — full spec in ARCHITECTURE_COMPLETE.md)             │
   │    5 capabilities → typed facts + citations → canonicalize → digest             │
   │                                                                                 │
   │  AGENT LAYER (this document)                                                    │
   │    Synthesis path:  digest hit → serve ledger doc │ miss → LLM reduce → VERIFY  │
   │    Chat path:       session(pid-pinned, preloaded facts+narrative)              │
   │                     ↔ agent loop: LLM ⇄ capability tools (schema'd, pid-pinned) │
   │                     → VERIFY → render (cited, clickable)                        │
   │                                                                                 │
   │  VERIFICATION GATE (§2): citations resolve · numbers grounded · pid match ·     │
   │                          banned-claim lint · fail-closed                        │
   │  OBSERVABILITY (§3): correlation IDs · trace spans · dashboard · alerts ·       │
   │                      /health //ready                                            │
   │  STORAGE (module-owned, append-only): mod_copilot_doc · mod_copilot_chat_* ·    │
   │                                       mod_copilot_trace · mod_copilot_cadence   │
   └─────────────────────────────────────────────────────────────────────────────────┘
                                                 │
                                                 ▼
                                  LLM provider (BAA assumed; see §4)
```

Placement: one additive custom module, `interface/modules/custom_modules/oe-module-clinical-copilot/`, PSR-4 namespace `OpenEMR\Modules\ClinicalCopilot\`, registered via `openemr.bootstrap.php` per the host's module contract (`src/Core/ModulesApplication.php`). Additivity is an enforced invariant (I9 in the complete spec): empty diff outside the module, byte-identical host behavior when disabled, clean uninstall.

---

## LLM platform (decided): Google Gemini on Vertex AI, hosted on GCP (T18)

- **Provider surface:** Gemini via **Vertex AI** — deliberately *not* the consumer AI-Studio API. Vertex is the HIPAA-eligible surface under the GCP BAA; AI-Studio API keys have no BAA path. This closes the "which provider, under what agreement" half of the PHI story (§4 boundary 3).
- **Models:** synthesis reduce + chat turns run the current stable **Gemini Pro tier** (2.5 Pro at time of writing); the advisory LLM-judge and other low-stakes tasks run **Gemini Flash**. Exact model version strings are **pinned** and fold into `prompt_version` — a digest input — so a model upgrade invalidates exactly the affected docs (E5 discipline) and is a deliberate, versioned event, never silent drift.
- **Structured output:** Vertex `responseMimeType: application/json` + `responseSchema` gives **provider-enforced constrained decoding** for the §2.1 claim schema. V1 schema rejections become rare by construction; client-side reject-and-retry remains the backstop, not the mechanism.
- **Tool calling:** native Gemini function calling (one `FunctionDeclaration` per §1.2 tool). The agent loop and its ≤5-call/≤3-round budget are ours, in module PHP — the provider proposes calls, the tool executor disposes.
- **Context & caching:** the Pro tier's large context window absorbs the preloaded fact set with headroom; **Vertex context caching** caches the session preamble (system prompt + fact set + narrative) across turns, so turns 2+ pay only incremental tokens — the load-bearing input to the multi-turn cost model (see the AI cost analysis).
- **Auth & integration (target):** the production posture that closes the PHI story is GCP service-account credentials via the official `google/auth` PHP library (ADC) — no API keys in code or config — calling the Vertex REST endpoints over HTTPS via Guzzle, pinning a versioned REST contract because the Vertex PHP SDK's generative-AI surface is thin (accepted risk, recorded in T18). This remains the decided production target; it is **not yet the realized code path** (see "As built" below).
- **Auth & integration (as built, v1):** the shipped read-path factories (`LlmClientFactory` / `ChatLlmClientFactory`, T23) use a plain Gemini API key (`CLINICAL_COPILOT_GEMINI_API_KEY`) **exclusively** — synthetic data only, no BAA. The Vertex/ADC branch was **removed** from these factories rather than carried as dead weight, so there is no "GCP project vs. API key" selection anymore: an API key present selects `GeminiApiLlmClient`, and its absence degrades to facts-only (I6). An optional second key (`CLINICAL_COPILOT_GEMINI_API_KEY_BACKUP`) lets `FailoverLlmClient` / `FailoverChatLlmClient` retry the backup automatically when the primary key fails (bad/expired key, quota exhaustion, transient provider/transport error) before falling through to facts-only degradation; a non-provider error still propagates unchanged. Moving to the Vertex/ADC target above is a future change to these factories, gated by OPEN-1 (real-PHI readiness), not a runtime toggle in the current code.
- **Hosting:** the deployed stack (OpenEMR + module + MySQL) runs on GCP; scaling is **vertical-first** — a resizable Compute Engine machine type, not horizontal app replicas (§3.6, T20).

## §1 Agentic Chatbot (UC6 — and the delivery surface for UC1–UC5)

The core interface is conversational: a chat panel rendered beside each patient's pre-visit synthesis, **pinned to that patient**, multi-turn, tool-invoking. It is not a search bar and not a report generator — the synthesis doc is the *first message* of the conversation, not the whole product.

### 1.1 Session model and context preloading

- A chat session is created lazily when the physician opens a patient's copilot page: `mod_copilot_chat_session (id, pid, user_id, doc_id, fact_digest, status, created_at)` — `status` is `active | frozen`; frozen is the verifier's sev-1 state (§2.3). The session is **preloaded** with the exact content-addressed doc the physician is reading — the canonical fact set (typed facts + citations, serialized by the same canonical serializer that feeds the digest) plus the verified narrative. Turn 1 therefore needs **zero retrieval**: the relevant data is already in the prompt.
- The system prompt contains: role and hard refusals (mirrors USERS.md §1 — no causation, no recommendations, no diagnoses, no general medical Q&A), the pinned patient's fact set, the narrative, tool definitions, and the citation-output contract (§2.1).
- Conversation context is maintained across turns: prior user/assistant turns plus tool results, within a token budget; oldest tool results are evicted first (they can be re-fetched fresh — facts are never cached, invariant I2), conversation turns are kept verbatim. This is what makes anaphoric follow-ups ("and the one before that?", "same for lipids") resolvable — the use-case justification for multi-turn lives in USERS.md UC6.
- **Mid-conversation freshness is a stated tradeoff (T19), not an oversight.** The session is **not** re-seeded or regenerated per turn: re-pulling the full context every turn is cost without benefit, and a result that landed one minute ago rarely changes the answer to the question being asked. What each turn *does* run is the cheap digest check (fact re-extraction is the inexpensive step — T5's economics; no LLM involved): on drift, the turn still answers, but renders under a visible banner — "the chart has changed since this summary — refresh to re-seed" — with a one-click re-seed that starts a fresh session off the new doc. Tool results always reflect live data regardless (facts are never cached, I2). So staleness mid-conversation is *detected and disclosed*, never silent — the same honesty rule as I5 — while the spend stays bounded. To keep citation resolution unambiguous when a preloaded fact and a re-fetched fact differ, `fact_id` includes the canonical value (fact schema, ARCHITECTURE_COMPLETE.md).
- Every turn and every tool call is persisted append-only in `mod_copilot_chat_turn (session_id, seq, role, content, tool_calls JSON, verification_verdict JSON, correlation_id, tokens_in, tokens_out, cost_usd)` — the same provenance-ledger philosophy as the doc store (T7): "what exactly did the physician see" is answerable byte-for-byte.

### 1.2 Tools — the five capabilities, schema'd and pinned

The agent's tools **are** the fact layer's five capabilities. No new data access is introduced by chat; the LLM gains navigation, never extraction (T1 as revised, T14).

**The LLM executes nothing — the program pulls everything (I13).** A "tool call" is a structured *request* the model emits; deterministic module code validates it against the input schema, pins the patient, runs the capability, and returns typed facts. The model has no query, network, or host-API access of any kind — every byte it ever sees was fetched by program code. And most turns need no request at all: **at session seed the program has already pulled all five capabilities** (that pre-pull *is* the preloaded fact set, warmed before clinic by the worker), so requests exist only for parameterized drill-downs beyond the preloaded envelope — a longer window, a per-drug filter, a raw value list.

| Tool | Wraps capability | Input schema (JSON Schema, strict) | Output |
|---|---|---|---|
| `get_control_trend` | ControlProxy | `{analyte: enum[a1c,glucose,lipids], window_months: int 1..60}` | typed facts + citations |
| `get_med_history` | MedResponse | `{drug_filter?: string, window_months: int}` | med events (both sources, T4) + paired labs |
| `get_vitals_trend` | VitalsTrend | `{metric: enum[weight,bp,bmi], window_months: int}` | typed facts + citations |
| `get_overdue` | OverdueTests | `{}` | overdue items + reorder-suppression notes |
| `get_pending` | PendingResults | `{}` | active unresulted orders + preliminaries |

- **No tool takes a patient identifier.** The tool executor injects the session's pinned `pid` server-side; on return, it asserts every produced fact carries that same `pid` before the fact enters the session fact set (defense in depth with §2.3).
- Tool inputs and outputs are validated against strict JSON Schemas (the canonical fact-object schema and the per-tool input schemas ship with the fact model — see "Fact object" in ARCHITECTURE_COMPLETE.md; contracts are the source of truth, engineering requirement R3). Schema-invalid tool calls are rejected back to the model with the validation error (one retry), then surfaced as a tool failure.
- **Tool chaining** is allowed to a bounded depth (max 5 tool calls per turn, max 3 rounds): "did her weight change after the insulin started?" = `get_med_history` → `get_vitals_trend` with the returned date. The chaining budget exists because unbounded loops are a latency and cost failure mode; hitting the budget degrades transparently ("I retrieved X and Y; I did not retrieve Z — ask again to continue").
- Tool results are facts + citations from the same deterministic code paths as the synthesis — including the exclusion accounting (invariant I5: "N excluded (reason)" facts pass through chat answers too).

### 1.3 Endpoint, auth, and rendering

- **Routing (decided):** all endpoints are **module pages**, session-authenticated — the only additive path this fork actually provides. (Verified against source: the host's REST route-extension event feeds the OAuth2 bearer-token API stack — the wrong auth model for an in-EMR panel and no place for a streamed response — and clean vanity paths would need rewrite rules outside the module directory, violating additivity I9.) Real URLs: `interface/modules/custom_modules/oe-module-clinical-copilot/public/{chat,doc,status,health,ready}.php`; the `/copilot/*` names in this doc are shorthand for those. Each page bootstraps `interface/globals.php`, then in order: CSRF (`CsrfUtils::checkCsrfInput`), ACL (`AclMain::aclCheckCore('patients','med')` — see §4), session identity (`SessionWrapperFactory` → `authUserID`), and the session's `user_id` must match the authenticated user on every turn. The chat endpoint is **contractually read-only-session** (never sets `$sessionAllowWrite`), so a long-held connection can never serialize the physician's other tabs. Consequence for the Bruno collection (R5): its first folder is a documented login + CSRF-token bootstrap pair, so graders run it without reading source.
- **Execution model — one executor, two views.** Every turn executes synchronously inside the `POST chat.php` request. There is no queue, no job daemon, no second execution engine whose state could disagree with the first: the request *is* the turn. SSE is that same request streaming staged status ("retrieving labs… verifying…"); the **verifier runs on the complete response before any prose token is shown** (streaming unverified text would defeat §2). The *polling fallback* — for proxies and buffering setups that break SSE — changes only the view, never the executor: the client fires the identical POST, then polls `GET status.php?cid=<correlation_id>`, which reads the **trace spans the turn is already writing** (I12) to render progress, and returns the finished turn from `mod_copilot_chat_turn` once the root span closes. The observability table double-duties as the progress feed, so the progress UI is *provably what happened*, not a parallel status variable that can drift. Consequences, stated: the turn survives a closed browser tab (it completes server-side and lands in the turn ledger — the poll picks it up on return); `max_execution_time` and the web server's timeout must be ≥ the 30 s turn deadline *on the chat endpoint only*; and a PHP worker is held for the turn's duration either way, which is priced into the vertical-first scaling stance (§3.6, T20).
- Every chart-data access from chat is audit-logged via the host `EventAuditLogger` (event `patient-record`, action `view`, description carrying the correlation ID) — chat inherits the EMR's HIPAA audit trail.
- **Latency budget (decided):** real-time seconds are acceptable *because they are the exception* — the primary path is pulled **prior**: the worker warms both the synthesis and the chat seed (the full five-capability pre-pull) before clinic, so live latency only ever applies to a follow-up drill-down. For those: hard per-turn deadline **30 s**, 20 s timeout per LLM call, 5 s per tool request; if verification fails and a regeneration would blow the deadline, skip the retry and go straight to facts-only (already a legal I11 outcome). The p95 chat alert sits at 15 s (a placeholder until R8 baselines replace it — typical turn ≈ one ~8 s generation, retried tail ~2×).
- Failure behavior (the case study's failure-modes hard problem): a tool failure is reported to the model *and* the user ("vitals lookup failed — answering from labs and meds only"), never silently absorbed; LLM unavailable ⇒ the chat degrades to a facts browser (the capabilities still run; prose is unavailable) — the same absolute degradation rule as the synthesis path (I6).

### 1.4 What the chat agent refuses

Refusals are product features (USERS.md §1): general medical knowledge ("what's the target A1c for her age?") → refused with a pointer to the physician's own guidelines; causation/recommendation/diagnosis phrasing → the lexical class is blocked by the verifier even if the model attempts it, and paraphrased attempts are a stated residual under adversarial-eval pressure (§2.4); any question about a different patient → structurally impossible (§1.2) and additionally refused in prose.

---

## §2 Verification System

Every response the agent produces — synthesis narrative or chat turn — passes a verification layer **after generation, before display**. Its two jobs, per the case study: source attribution and domain-constraint enforcement. Its extra job, per this design: proving the response is about the right patient.

### 2.1 Output contract

The LLM must emit structured output (provider-enforced via Vertex `responseSchema`, client reject-and-retry as backstop): a list of claim objects `{text, claim_type, citation_ids[], numeric_values[], flags[]}` plus ordering/emphasis metadata. `claim_type` is the closed enum V2 checks; `flags` is where conflict acknowledgment lives (V6). Free prose without claim structure is schema-rejected before any semantic check runs. This makes verification mechanical rather than interpretive.

### 2.2 Checks, in order (all deterministic)

| # | Check | Catches |
|---|---|---|
| V1 | **Schema gate** — output parses against the claim schema | malformed/unstructured output |
| V2 | **Citation resolution** — every claim's `citation_ids` resolve to facts present in the session fact set (preloaded facts ∪ this session's tool results) | fabricated sources; claims with no source. Zero-citation claims are allowed **only** for a closed `claim_type` enum — `greeting`, `refusal`, `retrieval_status` ("fetching vitals…"), `uncertainty_statement` ("I couldn't verify that") — declared by the model per claim and re-checked by the verifier: any claim mentioning an analyte, medication, numeric value, date, or patient attribute is clinical regardless of its declared type and must cite |
| V3 | **Patient identity guard** — every resolved fact's `pid` equals the session's pinned `pid`; independently re-checked here even though the tool executor already asserted it on ingest | **wrong-patient data** — the double-check: pid is injected server-side on the way in (§1.2) and every citation is re-verified on the way out |
| V4 | **Numeric grounding** — every actual clinical **value a claim pulls** (a lab result, a reading, a count that has a fact) must appear in a cited fact, after canonicalization (units, decimal normalization). Scoped to the data pulls — the medications, results, and readings — because ordinary clinical English is full of numbers that are *not* data pulls and have no fact to cite: **dates, ages, frequencies/durations** ("every 3 months", "twice daily"), a **disease type or stage** ("type 2"), and a **medication dose** ("1000 mg", carried by the cited prescription, not a numeric result fact) are exempt. Grounding *those* only produced false failures on well-formed answers; a value stated in prose but **not** exempt (an ungrounded "7.9") is still caught, so every genuine medical pull stays verified. Derived numbers — deltas, counts, spans, expected-return dates — are legal **only** as citations of `derived_*` facts computed deterministically by capabilities (fact schema): the verifier never does arithmetic, and neither does the model | the classic hallucination: right citation, wrong number; transposed A1c values; LLM-invented arithmetic ("rose 0.6" with no derived fact behind it) |
| V5 | **Banned-claim lint** — deterministic pattern classes: causation ("because", "due to", "caused", "led to" over med↔lab pairings), treatment recommendations ("should start/increase/stop"), diagnoses, dosage advice, drug-interaction assertions not present as facts. The lexicon (trigger patterns + analyte/drug terms drawn from the module's own code sets and med facts, not a general medical dictionary) is version-pinned config. V5 rejects the **lexical** class; paraphrased violations are a named residual — see §2.4 | domain-constraint violations (USERS.md §1 refusals; case-study "clinical rules") — as phrased in the banned lexicon |
| V6 | **Conflict passthrough** — two presence checks over a **closed set**: (i) any claim citing a conflict-flagged fact must carry `conflict` in its `flags[]` (chat and synthesis); (ii) on the synthesis path only, every conflict-flagged fact in the input must be cited by ≥1 claim — checkable there because the doc enumerates all facts; chat answers are scoped to the question, so only (i) applies. This is *not* general omission detection: it works precisely because conflicts are an enumerable input set, which is why general omission stays in §2.4's not-caught list | the LLM adjudicating data conflicts (invariant I8); a synthesis that quietly drops a flagged conflict |

### 2.3 Failure handling — fail closed

- Any check fails → **one** regeneration with the verifier's specific findings appended to the prompt ("claim 3 cites fact F17 which does not contain the value 8.4").
- Second failure → the response is **discarded**, never shown. Synthesis path: facts-only rendering ("narrative unavailable"). Chat path: "I couldn't produce a verifiable answer — here are the facts I retrieved," with the tool results rendered as cited fact tables.
- A V3 (patient identity) failure is different in kind: it is treated as a **severity-1 incident**, not a retry — the response is discarded, the session is frozen, the event is alerted (§3.5) and audit-logged. If patient pinning ever fails, something is wrong upstream of the LLM, and continuing the conversation is the wrong move.
- Every verdict — pass, retry, fail, and per-check detail — is recorded on the turn/doc row and in the trace (§3), feeding the verification pass/fail rate metric.

### 2.4 Where verification sits, and known limitations (stated honestly)

Verification runs **between generation and rendering** — nothing else sees model output first. Upstream of it, the fact layer is verified by construction (deterministic, fixture-tested per the lab contract C1–C4); downstream, rendering shows citations as clickable references to the underlying chart records, so the physician can always audit a claim in one click.

What it **catches:** uncited claims, unresolvable citations, wrong-patient citations, ungrounded numbers, causation/recommendation/diagnosis language, silently resolved conflicts.

What it **does not catch:** misleading *emphasis* or ordering (all claims true, priority wrong); qualitative paraphrase that is subtly wrong without tripping a numeric or banned-pattern check ("roughly stable" over a rising trend); **paraphrased banned claims** — causation or recommendation asserted without lexicon trigger words ("the rise tracks the missed refills") — hunted by adversarial evals seeded with paraphrase attacks, but not deterministically blocked; **omission** of a relevant fact the physician would have wanted (except the closed conflict set, V6).

### 2.5 Verification the physician can do — and a second pass by a second agent

Two rendering decisions turn the residuals above into something a human can catch:

- **The actual data lives on its own tab / side panel, always.** The full fact table renders beside the narrative and beside every chat answer — not behind a click into another module, but as the adjacent view — and every citation in prose click-throughs to its row. The badge on every response reads **"citations checked"** (hover: exactly which checks V1–V6 ran and their verdicts), never "verified" — the UI must not claim more than the gate delivers. A synthesis that was retried or served degraded says so *on the doc itself*, not only on the ops dashboard.
- **A second-pass reviewer agent looks at that same data.** A separate model instance (Gemini Flash, T18) with no stake in the first answer re-reads the rendered response against the session fact set from scratch and annotates: *second review: concurs* / *flagged: claim 3 emphasis*. Its verdict displays next to the badge, is stored on the turn/doc row, and feeds the dashboard and evals. It is deliberately **advisory, never the blocking gate** — T15's reasoning stands (a second model as the last line of defense is a second hallucination surface) — but as a *reader-facing signal* it covers exactly the territory the deterministic checks can't: emphasis, paraphrase, omission.
- **Over-reliance is measured, not assumed away:** citation click-through rate and facts-panel opens are dashboard metrics (§3.3). If they decay over weeks, that is the leading indicator that the residual risks above are landing on a physician who has stopped looking — and it pages product, not on-call. These are real limits of deterministic verification. They are bounded, not solved, by: facts-first rendering (the full fact table is always on the same page — prose is never the only view), the eval suite's narrative checks (known-answer fixtures where emphasis is asserted), and an optional LLM-judge scoring pass that feeds evals and dashboards but is deliberately **never the blocking gate** (a second model is a second hallucination surface — T15).

---

## §3 Observability

Wired in from the first request, not retrofitted. Design goal: the four case-study questions — *what did the agent do and in what order, how long did each step take, did tools fail and why, what did it cost* — answerable from stored data at any time, for any request, by correlation ID alone.

### 3.1 Correlation IDs (engineering requirement R2)

- Every agent invocation — synthesis read, worker warm, chat turn, health probe — mints a UUIDv7 `correlation_id` at the entry point.
- It is threaded through **every** capability call, LLM request, verification verdict, audit-log entry, and PSR-3 log line (`SystemLogger` context array — never string-interpolated), and stored on `mod_copilot_doc`, `mod_copilot_chat_turn`, and every trace span.
- A full trace is reconstructable from the trace table alone; logs are corroboration, not the primary record.

### 3.2 Trace spans

`mod_copilot_trace` (append-only, module-owned):

```
correlation_id · span_id · parent_span_id · kind (extract | digest | cache_lookup |
llm_reduce | chat_turn | tool_call | verify | render | warm | alert_eval)
· started_at · duration_ms · status (ok | error | retried | degraded)
· error_class · error_detail · model · tokens_in · tokens_out · cost_usd
· pid · user_id · payload_ref
```

- Spans nest: a chat turn parents its tool calls, LLM calls, and verification span, so "what happened, in what order" is one indexed query.
- `payload_ref` points at stored request/response payloads (prompts, tool args, tool results, verifier findings). **PHI boundary decision (T16):** payloads contain PHI, so they live in the module's tables inside the EMR's MySQL — the same protection domain as the chart itself — and are *not* shipped to a third-party observability SaaS. Access to the trace UI is ACL-gated (admin) and itself audit-logged. A self-hosted Langfuse exporter is an optional deploy-time add-on; the trace table remains the source of truth.
- **Every** path writes spans — cache hits, degraded reads, and failures included (the gap in the previous design: only LLM misses left a record).

### 3.3 Metrics and dashboard (R4)

An in-app module page (Twig + the host's Bootstrap stack, auto-refreshing) computes from the trace table in real time:

- total requests (by kind), error count and rate, **p50/p95 latency** (per kind and per step), tool call counts and failure rate per tool, LLM retry counts, **verification pass/fail rate** (per check V1–V6), second-pass reviewer concurrence rate (§2.5), cache (digest) hit rate, degradation count, tokens and **cost** per request / per day / cumulative, worker lag (appointments due vs. warmed), and the over-reliance leading indicators: **citation click-through rate and facts-panel opens** (§2.5).
- **Click-through, end to end:** dashboard tile → filtered request list → single request's span waterfall → click any span → full payload (prompt, tool args/results, verifier findings, log lines for that correlation ID). This is the "see what's going on and click through to the logs" surface, in-app, no external tooling required.
- **Run evals, on demand.** A "Run evals" button (CSRF-checked, audit-logged) runs the 50-case boolean-rubric eval gate from the dashboard itself, through the exact same deterministic engine as the CLI/CI gate — `Ops\Eval\EvalGate`, extracted so `ops/eval/run-evals.php` and `dashboard.php`'s `run_evals` POST action share one implementation, no live model or DB touched — and renders a pass/fail badge, per-rubric pass rate vs. baseline, and any regressions inline, so the gate is re-checkable without a shell into the container.

### 3.4 Health and readiness (R6)

- `GET /copilot/health` — **unauthenticated liveness**: returns only module-enabled + module version. Deliberately checks no dependencies — a DB outage must not fail liveness and get the app pointlessly restarted by an orchestrator.
- `GET /copilot/ready` — genuine dependency checks with timeouts: DB round-trip through `QueryUtils`, module tables writable (INSERT+ROLLBACK probe on trace table), LLM provider reachable via a Vertex `countTokens` call (exercises service-account auth and endpoint reachability at zero generation cost — the concrete answer to "is every probe billable": no), background worker heartbeat fresh, circuit-breaker state (§3.7). Degraded-but-serving states are reported honestly (`llm: unreachable → facts-only mode`), matching I6: LLM-down is degraded, not dead. **Unauthenticated but redacted**: status enums only (`llm: ok | circuit-open | unreachable`) — no latencies, no config values, no PHI — and per-IP rate-limited. External uptime probes point here; this is the worker's dead-man switch (§3.5).
- **Scope correction, recorded:** an earlier draft claimed registration with the host's `meta/health` (`/livez`, `/readyz`) prober via `HealthCheckInterface`. Verified against source: that check list is hard-coded with no extension point, so integration would require a core diff — an I9 violation. The module endpoints stand alone; upstreaming an extension event to the host prober is a candidate OSS contribution, not a this-phase dependency.

### 3.5 Alerts (R7) — meaning and on-call response documented

| Alert | Threshold (initial) | Means | On-call response |
|---|---|---|---|
| **Wrong-patient guard trip** (V3) | any single occurrence | pinning failed upstream of the LLM | Sev-1. Freeze module (feature flag), preserve session + trace, diff tool-executor pid injection vs. citations before re-enable |
| p95 latency | > 15 s over 15 min (chat turns — placeholder until R8 baselines; derived from one ~8 s generation with a ~2× retried tail, §1.3); warm-miss rate > 20% at 8:00–9:00 (synthesis) | physician-visible slowness; worker not keeping up | check trace step breakdown: LLM latency vs. extraction vs. queue; verify worker heartbeat; scale/interval-tune worker |
| Error rate | > 5% over 15 min | systemic failure (DB, LLM, schema drift) | check `error_class` distribution in traces; if LLM-side, confirm degradation is engaging (users see facts, not errors) |
| Tool failure rate | > 2% per tool over 1 h | a capability breaking on real data shapes | pull failing spans' payloads; usually a data-quality edge — add the case to fixtures before fixing |
| Verification failure rate | > 10% over 1 h | model or prompt regression (drift, provider-side change) | compare per-check failure mix vs. baseline; pin/roll back model version; replay evals |
| **LLM spend** | hourly burn > 2× trailing-7-day average for 1 h, or daily site cap reached (§3.7) | runaway loop, warm storm, or hostile-but-authenticated user | hard cap trips the circuit breaker automatically (§3.7); rank correlation IDs by `cost_usd` in traces to find the burner before reset |
| **Worker heartbeat stale** | no worker span for > 2× tick interval | cron missing or dead — warm sweep **and** alert evaluation are down | verify the cron entry (hard deployment requirement — worker spec in ARCHITECTURE_COMPLETE.md); this alert cannot ride the worker that died: it surfaces via `/copilot/ready` and the dashboard, and an external uptime probe on `/ready` is the recommended dead-man switch |

Alert evaluation runs on the module's background-service tick; firing writes an `alert_eval` span, surfaces a dashboard banner, and logs at `error` severity (pluggable email/webhook at deploy time). The heartbeat alert is the stated exception — a dead worker can't report itself, so its detection lives on the pull paths (`/ready`, dashboard, external probe).

### 3.7 Rate limits and cost circuit breakers

Cost is not only observed (§3.3) — it is **limited**. All limits are initial values in versioned config rows (module-owned; version is a digest-style config input, E5 discipline), tuned from trace data:

- **Per session:** one active turn at a time — a second `POST` while a turn is running is rejected (HTTP 409 + client hint), which also makes double-submit and two-tabs behavior deterministic; max 30 turns per session (then: "start a fresh session from the current summary").
- **Per user:** max 3 active sessions; max 60 turns/hour.
- **Per site:** daily LLM spend cap and hourly burn-rate cap; a per-tick LLM budget for the warm worker (a chart-churn storm degrades warm *coverage*, never blows the cap — cold patients fall back to read-time generation, I7).
- **Trip behavior (automatic):** breaker state lives in the module config table and is checked before every LLM call. Open breaker ⇒ chat degrades to the facts browser with a banner, synthesis serves cache hits and facts-only on miss — the I6/I11 degradation paths, reused, so a tripped breaker is *degraded, never broken*. `/copilot/ready` reports `llm: circuit-open`; the dashboard shows it; reset is automatic at window rollover, manual reset is ACL-gated and audit-logged.

### 3.6 Baselines and load (R8, R9)

Before the agent ships: capture baseline CPU/memory/latency/throughput of the deployed stack (synthesis read warm/cold, chat turn with 0/1/3 tool calls). Load tests at **10 and 50 concurrent users** against the deployed environment, recording p50/p95/p99 and error rate per level, plus DB connection and PHP-FPM worker saturation. Results are committed with the eval dataset; future changes are measured against them.

**Scaling stance (decided, T20): vertical-first.** The app does not scale horizontally — it scales by resizing the server. OpenEMR is a session-holding monolith and the module is in-process by design (T2); each open chat connection holds a PHP worker for the turn's duration, so concurrency is bought with worker count, which is bought with cores and RAM on a bigger GCE machine type — not with app replicas (which would immediately raise shared-session, worker-singleton, and sticky-connection problems the monolith isn't built for). The load-test saturation numbers tell us the worker/RAM sizing per N users; the honest ceiling is stated rather than hidden: when a deployment outgrows the biggest sensible machine (multi-clinic scale), the next moves are read replicas for fact extraction and pulling the chat loop out of process — a deliberate re-opening of T2, recorded in T20, not an emergency.

---

## §4 Authorization, PHI, and trust boundaries (the case study's who-is-asking and HIPAA hard problems)

- **Who is asking:** every request is authenticated by the host session; identity = `authUserID`. Access to any copilot surface requires `AclMain::aclCheckCore('patients','med')`; the module additionally registers its own ACL section so a site can grant/deny the copilot independently of chart access. v1 *product* scope is physicians (USERS.md §4 excludes nurse/resident workflows), but the *authorization architecture* models the role: a nurse or supervised resident hitting the endpoint is cleanly denied by ACL, not by obscurity — and every denial is logged with the correlation ID.
- **ACL granularity is chart-wide, not per-patient (accepted, matches stock OpenEMR):** `aclCheckCore('patients','med')` is a role-level, chart-wide grant — it authorizes *whether* a user may read charts, not *which* patients. There is no per-patient, care-team, or facility scoping in the copilot surfaces; any user who clears the ACL can address any `pid`, exactly as stock OpenEMR's own chart-access model already allows (chart-wide ACL rather than per-assignment). This is a **deliberate decision to match the host EMR's accepted convention**, not an oversight: adding care-team scoping only here would diverge from the rest of the fork while the underlying chart screens stay chart-wide. The one caveat worth naming: the copilot hands that same broad read access to an *LLM call* (with its cost and blast-radius), not just a page view — so a site that wants stricter-than-stock scoping should add a care-team/facility check in the capability/controller layer, and that is the intended extension point if this fork ever tightens the model. Until then, chart-wide ACL is the accepted authorization model for the copilot (security-audit finding #4).
- **Trust boundaries:** (1) browser ↔ module endpoint — host session + CSRF; (2) module ↔ clinical tables — read-only, through host services, every read audit-logged via `EventAuditLogger`; (3) module ↔ LLM provider — the only place data leaves the process; BAA assumed per the case-study ground rules, **synthetic patients only** in this phase (OPEN-1 in the complete spec), and a named redaction/BAA review is a hard gate before any real-PHI deployment; (4) trace/ledger storage — same MySQL protection domain as the chart, ACL-gated UI, no third-party telemetry (T16).
- **Read-only is enforced, not asserted:** the load-bearing layer, wired today, is (1) a **module-scoped PHPStan rule** (same custom-rule pattern the repo already uses in `tests/PHPStan/Rules/`) that forbids every write API — `sqlInsert`, INSERT/UPDATE/DELETE through any wrapper, service `insert`/`update` methods — outside the whitelisted `mod_copilot_*` repositories, enforced in the module's CI gate beside the repo-diff check (U9). A second, defense-in-depth layer is **planned but not yet provisioned (as-built gap, security-audit finding #20):** running capability reads on a dedicated **SELECT-only MySQL user** so even a defect couldn't write clinical tables. The current deploy path (`railway-preinstall-db.sh`) provisions a single application DB user with full privileges on the app schema — the module writes its own `mod_copilot_*` tables and, on the ingest commit path, core procedure tables via `ChartWriter`, so a naive single SELECT-only user would break those write paths; a real split requires two DB roles (SELECT-only for the read/capability path, a scoped writer for `ChartWriter`/`mod_copilot_*`). Until that split lands, the static-analysis rule in layer (1) is the enforced control and the SELECT-only user is a documented target, not a live guarantee. And (3) **LLM egress redaction (decided):** what is *posted to the LLM* is obscured — direct identifiers (name, MRN, DOB, address) are replaced with stable per-session pseudonym tokens before any LLM call and re-hydrated in the rendered answer after verification. The model reasons over clinical values, never over identity; a leaked prompt or completion exposes tokens, not a person. Honest scope: quasi-identifiers (dates, rare lab values) remain, so this is *minimization*, not full de-identification — it is the first concrete piece of OPEN-1's redaction story shipped in v1, and OPEN-1 still gates real PHI.
- **Transmission:** the module adds no transport of its own. Browser ↔ module rides the host's TLS (production deployments are TLS-only, per the host's Apache config; the dev stack already serves https). Module ↔ LLM provider is HTTPS with certificate verification enforced in the HTTP client. PHI never appears in URLs or query strings (POST bodies only), never in exception messages, and never inline in log lines — trace payloads live behind `payload_ref` in the ACL-gated trace store, not in the log stream.
- **Retention and disposal:** the append-only ledgers (doc, chat turns, trace payloads) hold PHI-derived content indefinitely *by design* — that is the T7 provenance decision, and it is in deliberate tension with HIPAA retention/disposal discipline. Named position: retention follows the site's medical-record retention policy; disposal happens only through site-level export-then-purge tooling operated by an administrator, never through row deletes in application code; module uninstall requires explicit confirmation and offers export-before-drop (OPEN-2 in the complete spec).
- **Prompt injection** (chart free-text reaching the LLM): tool outputs are *typed facts*, not raw note text — the fact layer is itself the injection filter. Free-text fields that do pass through (e.g., lab `result` verbatim strings) are length-bounded, rendered as data in delimited blocks, and the verifier ignores any "instructions" a response claims to have followed: an uncited or banned claim fails regardless of why the model produced it.

## §5 Evaluation (summary — full suite in ARCHITECTURE_COMPLETE.md)

The deterministic evals E1–E7 (digest/freshness/append-only) and per-capability known-answer fixtures carry over unchanged. The agent layer adds: **verification evals** (seeded wrong-number, wrong-patient, uncited, causation-phrased outputs must be blocked — fixtures drive a stub LLM so the gate is tested without a live model); **chat evals** (multi-turn anaphora fixtures, tool-chaining known answers, chaining-budget exhaustion); **adversarial evals** (cross-patient requests via forged tool args and via prompt content, ACL-denied users, prompt-injection strings embedded in seeded lab free-text — all must refuse and log); **boundary evals** (empty patient record, all-tools-failing, LLM-down degradation, **capability-crash ⇒ no digest, no ledger write, banner renders** — §6.1's rule proven, not assumed; malformed chat input — empty, oversized, and garbage messages must get a clean refusal, not a stack trace); and **regression evals** as a named category — every defect found in evals or production gets a replayable fixture before its fix, and committed baselines (verification pass rate, per-check failure mix, latency) are diffed run-over-run so drift is a test failure, not a surprise. Every eval documents the failure mode it guards (same naming discipline as digest evals E1–E7). Pass/fail is deterministic wherever possible; LLM-judge scores are tracked for narrative quality but never gate CI alone.

---

## §6 Failure model — why things fail, and the way around

The design's governing principle here is **recovery asymmetry**:

> **The synthesis is disposable. The conversation is not.** A summary is idempotent and stateless — content-addressed by its facts, written append-only — so a failed generation leaves *nothing* behind and can be instantly rerun, up to a bounded number of times, with zero risk. A conversation is stateful and mid-thought — it cannot be "rerun." So the design never lets the conversation be the only surface: **the verified summary and the full facts panel live on the same screen as the chat, and they are served from the ledger and deterministic reads, not from the live LLM.** Any chat failure degrades to a working reference surface the physician can cross-check historical data against. The chat can die; the chart-review job can always complete.

### 6.1 Synthesis failures — cheap, bounded, rerun freely

*Why rerunning is safe:* generation has no side effects to corrupt. Facts are recomputed fresh each attempt (I2), the ledger only ever gains rows (I3), and the digest addresses the slot — two successful runs over the same facts produce the same doc. A failed run inserts nothing.

| Failure | Why it happens | What happens |
|---|---|---|
| LLM timeout / 429 / 5xx on reduce | provider load, network, quota | **auto-retry up to 3** (versioned config, breaker-aware, inside the request deadline); then facts-only + "narrative unavailable" (I6); the worker retries on its next tick; a manual **Regenerate** button is always present — rerunning is free by construction |
| Verification fails twice | model/prompt drift, provider-side change | facts-only render; verification-failure alert trends it (§3.5); regenerate manually or after model pin/rollback |
| **A capability crashes during extraction** | data-shape surprise the lab contract didn't anticipate; DB error | **no digest, no ledger write — a synthesis is never computed over a partial fact set.** A narrative silently missing an entire domain (all vitals, all meds) is worse than no narrative: it reads as "nothing notable there," the exact silence-reads-as-normal failure I5 exists to kill. Instead: facts from the surviving capabilities render under a named banner ("VitalsTrend unavailable — synthesis paused"), the error span carries the correlation ID, and the tool-failure alert catches recurrence |
| Worker dead / cron missing | deployment gap | latency only, never correctness (I7): reads compute cold; heartbeat alert + `/ready` + external probe (§3.4–3.5) |
| Cold patient at 8:50 (add-on/walk-in) | not on yesterday's schedule when warmed | staged status while generating; facts render first; the retry rules above apply |

### 6.2 Chat failures — the concerning territory, and its failsafe

*Why chat is different:* a turn holds conversation context that cannot be regenerated, the physician is mid-thought, and failures here are visible in a way a background warm-miss never is. Every row below ends the same way on purpose — **the summary and facts panel beside the chat keep working**, because they never depend on the live LLM.

| Failure | Why | What the physician sees | The way around |
|---|---|---|---|
| LLM dies mid-turn | provider outage, timeout past retries | "turn failed" in the panel; error span in trace | ask again (fresh turn), or read the adjacent summary/facts — the historical data is already on-screen |
| Tool request fails | data-shape edge, DB hiccup | named in the answer itself ("vitals lookup failed — answering from labs and meds only"), never silently absorbed | the facts panel still shows the *preloaded* vitals from seed time; re-ask later |
| Verification fails twice on a turn | drift, adversarial phrasing | "couldn't produce a verifiable answer" + the turn's tool results rendered as cited fact tables | the facts are the answer; the prose was optional |
| Session frozen (V3 sev-1) | pinning failed upstream — never continue | chat locked with explanation | the summary remains valid (it passed its own verification when generated); incident response per §3.5 |
| Circuit breaker open (§3.7) | spend cap / runaway protection | chat becomes a facts browser with a banner | summary cache hits still serve; warm generation pauses; breaker resets at window rollover |
| Server restart mid-turn | ops | turn lands as an error span, never hangs; completed turns are already in the ledger | re-ask; nothing in history is lost (append-only turns) |
| Stale session mid-conversation | chart changed at 9:05 (T19) | banner: "chart changed — refresh to re-seed"; answers keep flowing | one-click re-seed; tool results were live the whole time |

### 6.3 Knowing *why*: the five root-cause classes

Every failure in the tables above traces to one of five classes, each with its own detection and first move — this is the triage card:

1. **Provider-side** (LLM latency, outage, quota, model drift) — detected by error-rate + verification-failure alerts; first move: check `error_class` distribution in traces, confirm degradation is engaging (physicians see facts, not errors), consider model pin/rollback (version is a digest input — rollback is clean, T18).
2. **Data-shape surprises** (free-text statuses, units, in-place mutations the contract didn't anticipate) — detected by per-tool failure alert; first move: pull the failing span's payload, add the case to the U2 fixture landmines *before* fixing — every data-quality surprise becomes a permanent eval.
3. **Infrastructure** (DB, cron absent, worker dead, FPM saturation) — detected by `/ready`, heartbeat alert, saturation baselines (R8); first move: the §3.5 on-call column.
4. **Verification rejections** (the gate doing its job) — not incidents individually; *trends* are (alert at >10%/h); first move: per-check failure mix vs. baseline.
5. **Adversarial/user-driven** (rate-limit trips, cross-patient attempts, injection strings) — detected by 409/refusal counts and the V3 alert; first move: rank correlation IDs by user; these are the traces worth reading end-to-end.

---

# Part 2 (Week 2) — Document ingestion, the one sanctioned chart write, and the external knowledge store

Everything above (the LLM platform section and §1–§6) is **Part 1**: a strictly read-only agent. It narrates and navigates the chart, and the read-only invariant is enforced, not asserted (§4) — a module-scoped PHPStan rule made a core-table write a static-analysis error *everywhere*.

Part 2 adds the write side, deliberately and narrowly. Two new capabilities need to put data *into* the world: extracting structured facts from uploaded documents, and grounding synthesis in external guideline knowledge. Neither is allowed to erode the Part 1 posture. The organizing decisions (recorded in the module's `docs/decisions.md`, P1–P3):

- **Additivity still holds (P1).** All Part 2 code lives under `interface/modules/custom_modules/oe-module-clinical-copilot/`; the CI gate `ops/ci/check-additivity.sh` still fails on any diff that escapes the module directory. Draft state lives in two new module-owned tables (`mod_copilot_extraction`, `mod_copilot_extracted_fact`), joining the `mod_copilot_*` family from Part 1.
- **The chart write is confined to one class (P1).** Week 2 relaxes the read-only invariant in *exactly one place*: `Ingest/ChartWriter`. The same custom PHPStan rule that proved Part 1 never wrote — `tests/PHPStan/Rules/ForbiddenWriteOutsideRepositoriesRule` — now names `ChartWriter` as the sole `SANCTIONED_CORE_WRITERS` entry; from inside it both raw `QueryUtils` writes and host-service `insert`/`update` calls are permitted, from anywhere else they remain a static-analysis error. A reviewer auditing "what can this module write to a real chart" reads exactly one class.
- **Degrade cleanly, never dead-end (P2)** and **PHI stays in the BAA boundary (P3)** carry over verbatim — see §12 and the per-flow analysis in `docs/ingestion-failure-modes.md`.

## §7 Multimodal document ingestion (extract structured facts from an upload)

A physician uploads a PDF or image; a **vision model (Gemini, the same Vertex/BAA surface as Part 1's LLM platform)** transcribes it into *structured facts under a strict JSON schema*, exactly mirroring Part 1's Key-decision-1 discipline — the model extracts into a provider-enforced schema, it does not free-form. Two document types are supported, each with its own schema and downstream commit path:

- **Intake forms** → new-patient demographics + clinical fields (chief concern, meds, allergies, family hx), each carrying a page/quote/bbox `SourceCitation`.
- **Lab reports** → discrete results (test/value/unit/range/flag) with per-field citations and a document-header patient name/DOB (used by the identity guard, §9).

**Classes.** `Ingest/UploadedDocument` (parses the `$_FILES` entry) → `Controller/IngestController` → `Ingest/AttachAndExtract` (the orchestrator: `previewIntake` + `commitReviewedIntake` for intake's deferred save; `ingestLab` for labs) → `Ingest/ExtractionClient::extract` (drives the vision model via the reused `ReadPath/LlmClientFactory`) → `Ingest/ExtractionSchema` (`validate` / `parse` / `blankExtraction`). **Labs** stage a draft through `Ingest/ExtractionStore` into `mod_copilot_extraction` (one header row per upload) and `mod_copilot_extracted_fact` (one row per field). **Intake persists nothing at upload** — extraction only prefills a review screen; the patient row is created later, at the human Save (§8).

**Degradation is by construction — and now explains itself.** `AttachAndExtract::tryExtract` swallows `LlmUnavailableException` / `SchemaValidationException` and substitutes a **blank draft** rather than surfacing an error or a provider message — no LLM configured (the documented default), a failed call, or a schema reject all land the reviewer on the same manual-entry review screen, never a 500. No exception message reaches the user on either clinical path (P3 / §4's "never expose `getMessage()`"). Two refinements narrow how often that blank draft is reached and how legible it is when it is: `ExtractionSchema::validate` now requires the page+quote citation only for `DocType::LabPdf` (labs drive the click-to-source bbox overlay) — intake fields never need one, since the review screen has no overlay, just a form beside the source PDF — so a single intake value the model returned without a clean citation no longer rejects the whole extraction and blanks an otherwise-successful read. And when the intake review form does come up unpopulated, `intake_upload.php` no longer leaves it unexplained: from the `vision_used`/`schema_rejected` flags `previewIntake` already returns, it shows a banner distinguishing "the AI model is not configured or did not respond" from "the document was read but the extracted data did not match the expected format" from "no values could be read from this document" — so the reviewer (and on-call) can tell those apart instead of guessing at an empty screen.

## §8 Human-in-the-loop review → the one chart write

The single place this module writes the core chart is a human-gated commit, and `Ingest/ChartWriter` is the only class that performs it. The two doc types reach the chart by **different** paths — intake through a deferred save at the review screen, labs through a lock on a staged draft — but both require an explicit human action and both funnel through `ChartWriter`.

**Intake — deferred save (decision D2).** The patient is **not** created at upload time, and intake **stages no draft** (no `mod_copilot_extraction` row). Upload → `IngestController::previewIntake` → `AttachAndExtract::previewIntake` extracts and returns the fields but persists nothing → a two-pane review screen (`public/intake_upload.php`, `templates/oe-module-clinical-copilot/intake_review.html.twig`) prefills the new-patient fields on the left with the source PDF on the right → the human edits and clicks **Save** → *only then* does `AttachAndExtract::commitReviewedIntake` call `ChartWriter::tryCreatePatient` (via the host `PatientService::insert` + `PatientValidator`, non-throwing so validation errors re-render the form) to create the patient, then `storeSourceDocument` and `addChartListLines` for reviewed allergies/medications. Post-create writes are best-effort — a failure there is logged, never reported as a total failure that could prompt a re-save and duplicate the patient. Nothing is persisted before the human confirms, so the failure mode is "a blank form to hand-fill," never a half-created patient. (The source PDF rides the page as base64 — decision D3 — with no server-side temp stash; an oversize edge is a 413 guard.) A brand-new intake patient gets the extracted facts only, **not** a generated narrative (decision D4): a narrative at first contact would pre-frame the encounter before the clinician has done their own fact-finding.

**Labs — commit down the core procedure chain on "Verify & lock."** Labs *do* stage a draft (`ExtractionStore` → `mod_copilot_extraction` / `_extracted_fact`), reviewed on `public/extraction_review.php` / `extraction_review.html.twig`. The commit entry point is `Ingest/ExtractionReview::lock`, which for a lab dispatches to `commitLabs` → `ChartWriter::commitLabResults`, writing the standard OpenEMR chain: `procedure_order → procedure_order_code → procedure_report → procedure_result` (one `procedure_result` per verified field), each bound to the stored source `document_id` for provenance and stamped with the human-entered draw/collection date. No chart write happens at ingest; the only commit is at lock, so every degraded path is safe. `commitLabResults` is idempotent per-field.

Compact data-flows:

```
INTAKE  intake_upload.php (action=upload) → IngestController::previewIntake
        → AttachAndExtract::previewIntake → ExtractionClient::extract (vision, intake schema)
        → ExtractionSchema::parse   [NOTHING PERSISTED]
        → intake_review.php prefilled form  [HUMAN edits, clicks Save (action=save)]
        → IngestController::commitReviewedIntake → AttachAndExtract::commitReviewedIntake
        → ChartWriter::tryCreatePatient (PatientService::insert) + storeSourceDocument + addChartListLines
        —— degraded (no/failed LLM, schema reject) ⇒ empty field map ⇒ blank form, hand-fill (banner explains why)

LAB     lab_upload.php (pid>0) → IngestController::ingestLab → AttachAndExtract::ingestLab
        → ExtractionClient::extract (vision, lab schema) → ExtractionStore draft → storeSourceDocument
        → AttachAndExtract::recordLabIdentity (§9)
        → extraction_review.php  [HUMAN verifies rows + draw date, "Verify & lock"]
        → ExtractionReview::lock → commitLabs
        → ChartWriter::commitLabResults: procedure_order → _code → _report → _result (per field)
        —— degraded ⇒ zero-row draft ⇒ reviewer adds rows by hand (AttachAndExtract::startManualLab)
```

## §9 Lab identity guard (PHI-mixing prevention)

A lab PDF is uploaded *onto* a chosen patient's chart, yet the file itself names a patient. If those disagree, the wrong person's results are about to be attached — a PHI-mixing incident. `Ingest/AttachAndExtract::recordLabIdentity` compares the document-header `patient_name`/`patient_dob` against the target chart's `fname`/`lname`/`DOB` via `Ingest/LabIdentityMatcher`, and persists a verdict (`match` / `mismatch` / `unknown`) on the extraction header through `ExtractionStore::setIdentity` — stored in `mod_copilot_extraction.identity_status` with a human-readable `identity_detail` for the banner.

The review template turns the verdict into a **persistent banner**: red on `mismatch` ("may belong to a different patient — do NOT lock"), amber on `unknown` ("could not confirm"), quiet green on `match`. The matcher is deliberately **conservative** (decision D1): any concrete name or DOB conflict is a `mismatch` even if the other field agrees; a name match requires both first and last as whole tokens; **nothing on the document to compare is `unknown`, never a silent pass**. It is best-effort — a failure reading the chart is logged and swallowed, so the guard can never block an upload the reviewer must still verify by eye. It flags for a human; it does not hard-block and it does not auto-reroute (inferring identity and then acting on it is exactly the move that mixes PHI). `identity_detail` is PHI and stays in the module's MySQL protection domain (T16) — it never crosses to the knowledge boundary (§10).

## §10 External knowledge store (RAG) with a hard PHI boundary

Part 1's synthesis and chat cite the patient's chart. Part 2 adds a *general medical knowledge* evidence layer — paraphrased, widely-published guideline summaries — grounded in RAG. That knowledge is a fundamentally different data class from PHI, and the module keeps the two **physically separate** (decision K1, `docs/knowledge-base.md`):

| Domain | Store | Holds | Under BAA |
|---|---|---|---|
| PHI | OpenEMR's MySQL | chart, extractions, telemetry, chat | Yes |
| General medical knowledge | a **separate Postgres** (Railway in prod) | `guideline_chunks` — PHI-free guideline text | Not required |

These are distinct connections to distinct servers: a query against the knowledge Postgres can never reach a chart row. The boundary is defended in depth:

1. **Separate physical store.** `Knowledge/KnowledgeBaseConnection` is its own PDO to its own server, configured by its own env vars, with no handle on OpenEMR's DB.
2. **Read path is SELECT-only.** The request path reaches the store only through `Knowledge/KnowledgeQueryRunner::select()`; the write path is a separate role/interface (`KnowledgeWriteConnection` / `KnowledgeWriteRunner`).
3. **PHI-scrubbed queries in transit.** A retrieval query can be a raw chat question ("why is Jane's A1c 9.4 on 3/2?"). `Knowledge/KnowledgeQueryScrubber` reduces it to non-PHI clinical keywords (dropping names, numbers, dates, emails; keeping analyte codes and structured tags) **before it leaves the process** — nothing patient-identifying reaches the non-BAA store.

**Retrieval is vector-first with a full-text fallback (hybrid, decision K3).** `Knowledge/PostgresGuidelineRetriever` ranks by pgvector cosine (`embedding <=> :query`, plus a boost when a chunk's `tags` overlap the requested analytes) and **falls through to Postgres full-text** (`ts_rank` over `websearch_to_tsquery`, keywords OR-combined) when no embedding key is configured, a query embed fails, or no embedded rows match. Vector search is an upgrade layered on full-text, never a hard dependency. The choice of retriever is made **once, from env**, by `Rag/GuidelineRetrieverFactory::createDefault()`, riding the existing `Rag/RetrieverInterface` seam so no consumer (`Rag/PatientEvidenceService`, the Supervisor) changed: knowledge Postgres configured ⇒ `PostgresGuidelineRetriever`; unconfigured ⇒ `Rag/HybridRetriever` over the offline in-repo corpus at `src/Rag/corpus/` (currently `endocrinology.json`). Configured-but-unreachable surfaces an honest "degraded" (empty evidence, visible in the trace/dashboard), never a silent mask.

**Embeddings (decision K4).** The default is `gemini-embedding-001` requested at **1536 dims** (`Knowledge/GeminiEmbeddingClient`; `EmbeddingClientFactory` returns `UnavailableEmbeddingClient` with no key ⇒ full-text only). 1536 is finer-grained than the older 768-wide models yet stays under **pgvector's 2000-dim HNSW ceiling for the standard `vector` type**, so the fast index still applies; stored as `vector(1536)`. `gemini-embedding-001` is a Matryoshka model, so the module asks for a 1536-wide slice of its native 3072 via `outputDimensionality`; no client-side re-normalization is needed because retrieval ranks by cosine (magnitude-invariant). Changing the model or dimension is a **re-embed, not a config flip** — embeddings across widths/models aren't comparable and `ADD COLUMN IF NOT EXISTS` won't resize a column.

**Ingestion is PHP, in-module (decision K2).** Chunk-and-store runs at a **Maintenance → Knowledge Base (RAG)** admin page (`public/knowledge_upload.php`, `src/Bootstrap.php`) and a CLI (`ops/knowledge/ingest_document.php`), both driving `Knowledge/KnowledgeDocumentIngestor`: `DocumentTextExtractor` gets plain text (text/markdown/HTML directly; PDF/image via `DocumentTranscriber` reusing the Gemini vision seam — no new PDF dependency) → `DocumentChunker` splits on heading/paragraph boundaries at a **per-document chunk size** (chosen per upload, not a global constant — decision K5) and auto-detects analyte tags → `KnowledgeChunkWriter::write` upserts in one transaction, **replacing the document's previous chunks by `source`** so a corrected version supersedes the old one. Each chunk is **embedded on write**. A knowledge-upload failure surfaces a single calm generic notice (decision K6), unlike the rich hand-entry fallback of the clinical paths — it is an admin action over a reproducible corpus with nothing to salvage.

```
KNOWLEDGE RETRIEVAL
  Supervisor / PatientEvidenceService → GuidelineRetrieverFactory::createDefault()  [once, from env]
    ├─ knowledge Postgres CONFIGURED → PostgresGuidelineRetriever
    │     query → KnowledgeQueryScrubber (strip PHI) → [embed query] → KnowledgeQueryRunner::select()
    │       → pgvector cosine + tag boost   ─ enough hits ─→ evidence snippets
    │                                        └ few/none, or no embed key ─→ full-text (ts_rank) fallback
    └─ NOT configured → HybridRetriever over src/Rag/corpus/*.json  (fully offline, unchanged from before)
```

## §11 Deploy and ops (the knowledge Postgres is a distinct service)

- **Distinct service.** In production the knowledge store is a separate Postgres (Railway); for dev it is a local `pgvector/pgvector` container standing next to the app via the compose overlay `ops/local/compose.knowledge.yml`, brought up with `ops/local/knowledge-up.sh` (one command; identical schema to prod, zero cloud dependency — decision L1).
- **`pdo_pgsql` on the app image.** OpenEMR core is MySQL-only, so its stock image ships without the Postgres PDO driver the knowledge store needs. A tiny derived image (`ops/local/knowledge/Dockerfile`, `FROM openemr/openemr:flex` + install `pdo_pgsql`, verified at build time) adds just the driver for dev (decision L2); deployed images install it in their own build — `Dockerfile.railway` at the repo root bakes it into the production image the same way, auto-detecting the base image's PHP version (`apk add "php$(php -r 'echo PHP_MAJOR_VERSION, PHP_MINOR_VERSION;')-pdo_pgsql"`) and failing the build loudly if the driver doesn't load, rather than shipping a silent fallback to the offline corpus.
- **Self-seeding on deploy.** `ops/knowledge/seed_from_corpus.php` both **ensures pgvector** (`ops/knowledge/schema.sql` runs `CREATE EXTENSION IF NOT EXISTS vector` and creates `guideline_chunks` with the `vector(1536)` HNSW-cosine index, a GIN full-text index, and a GIN tags index) and **upserts the in-repo corpus**, idempotently. Railway has no `releaseCommand` hook in `railway.toml` (the build is a plain `DOCKERFILE` build off `Dockerfile.railway`), so the seed does not run as a pre-deploy step — it runs from `railway-install-copilot.sh`, the same script that installs the module, which `railway-entrypoint.sh` launches in the background once the OpenEMR base install finishes and Apache is about to start. It fires only when `CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL` / `..._DB_HOST` is set, and it is best-effort — a failure is logged (to the Railway log and to `/tmp/copilot-install.log`, since Railway drops app logs under load) and never fails the deploy; retrieval falls back to the offline corpus either way. Honest scope: the seed loads **full-text rows only** (it runs without the OpenEMR/Gemini runtime, so it does **not** embed); to vector-index the corpus, ingest it through the UI/CLI, which embeds on write. A seeded-but-unembedded corpus is still searched (full-text), consistent with the vector-first/full-text-fallback contract. From a laptop, `ops/knowledge/deploy_railway.sh` applies the schema via host `psql` (or a throwaway pgvector container) and seeds if `php`+`pdo_pgsql` are present.
- **Health check.** `ops/knowledge/check.php` is a one-shot, standalone script (its own autoloader, no OpenEMR bootstrap — runs anywhere, including as root in the container) that walks every link vector search depends on and prints a PASS/FAIL/WARN line for each: the `pdo_pgsql` driver, `CLINICAL_COPILOT_KNOWLEDGE_*` configured, the connection opens, the `pgvector` extension is enabled, the table + `embedding` column width matches `EMBED_DIM`, the corpus is seeded (row count > 0), and embeddings are present (WARN rather than FAIL when absent — full-text search still works without them). Exit code is 0 only when every required link (1–6) passes. Run it after a deploy, or whenever retrieval looks degraded, as `php <module>/ops/knowledge/check.php`.

## §12 Cross-cutting invariants preserved from Part 1

Part 2 is additive in behavior as well as in code — the Part 1 guarantees still hold:

- **Additivity (P1).** Module-directory-only; `ops/ci/check-additivity.sh` fails the build on any escaping diff. Core writes are confined to `ChartWriter` and proven so by `ForbiddenWriteOutsideRepositoriesRule` (§8) — the same enforcement mechanism as Part 1's read-only proof, now with exactly one sanctioned writer.
- **Degrade cleanly, never dead-end (P2).** Every new dependency has a defined fallback: no LLM ⇒ facts-only / manual-entry review (§7–§8); no knowledge Postgres ⇒ offline in-repo corpus (§10); no embeddings key ⇒ full-text search (§10). Nothing in a request path throws to the user because a best-effort capability was unavailable. The per-flow failure analysis lives in `docs/ingestion-failure-modes.md`.
- **PHI stays in the BAA boundary (P3 / §4).** Chart data, extractions (including the identity guard's `identity_detail`), and telemetry live only in OpenEMR's MySQL. The knowledge Postgres is PHI-free by construction and its query path is scrubbed before egress (§10). LLM egress on the read paths remains pseudonymized per §4.

---

## Case-study compliance map

| Requirement | Where |
|---|---|
| LLM / framework selection (provider, model, structured output, context, cost basis) | LLM platform section; T18 |
| Agentic chatbot (multi-turn, context, tools) | §1; USERS.md UC6 (multi-turn + chaining justification) |
| Verification (attribution + domain constraints + limitations) | §2 |
| Observability (4 questions, from the start) | §3.1–3.3 |
| Evaluation (boundaries, invariants, adversarial) | §5; ARCHITECTURE_COMPLETE.md build units |
| Authorization / who-is-asking | §4 |
| Speed vs completeness | Summary decision 4; T5/T11/T17 |
| HIPAA / BAA / PHI | §4; T16; OPEN-1 |
| Failure modes / degradation (why + the way around) | **§6** (recovery asymmetry, failure tables, root-cause triage); §1.3, §2.3, §3.4–3.5; I6/I7 |
| Correlation IDs (R2) | §3.1 |
| Schema contracts (R3) | §1.2, §2.1; fact-object schema in ARCHITECTURE_COMPLETE.md |
| Dashboard (R4) · health/ready (R6) · alerts (R7) · baselines/load (R8–R9) | §3.3 · §3.4 · §3.5 · §3.6 |
| Runnable API collection (R5) | Bruno collection over `/copilot/chat`, `/copilot/doc/:pid`, `/copilot/health`, `/copilot/ready` — ships with the module (build unit U13) |

R-numbers label the case study's engineering requirements (R1 tests-document-failure-modes … R9 load tests); digest evals keep their original E1–E7 names in ARCHITECTURE_COMPLETE.md — two namespaces, deliberately distinct.

Separate submission artifacts (tracked, not part of this doc). **Delivered:** `AUDIT.md` (with its own ~500-word summary); AI cost analysis at 100/1K/10K/100K users (`interface/modules/custom_modules/oe-module-clinical-copilot/ops/cost-analysis.md`); deployed URL — https://abundant-art-production-d560.up.railway.app (see `README.md`). **Still owed:** demo video, social post; and empirical R8/R9 load/baseline numbers (harness ships in `ops/load/`, not yet run against the live stack).
