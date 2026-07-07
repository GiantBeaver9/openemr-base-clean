# Clinical Co-Pilot — Test Fixtures (U2)

Synthetic type-2-diabetes patients and **raw chart rows** in the exact column
shape the host tables return, so the deterministic contract tests (U4 LabSlice,
U5 Capabilities) run **isolated — with no database**. A `FixtureReader` impl
loads these JSON files and filters by `patient_id` / `pid` exactly as the `Db`
impl would query the host tables; pure logic is identical either way.

All patients, rows, and expected answers are **synthetic** (OPEN-1 / non-goals:
no real PHI). Stable pids `9001`–`9004`; every seeded row is tagged so the seed
is idempotent (see `../Seed/SeedRunner.php`, marker `pubpid = CCPILOT-<pid>`).

Today for overdue math is **2026-07-07** (matches the build date).

## Files (one JSON array per host table)

| File | Table | Key columns used by capabilities |
|---|---|---|
| `patient_data.json` | `patient_data` | `pid`, `pubpid` (idempotency marker) |
| `procedure_order.json` | `procedure_order` | `procedure_order_id`, `patient_id`, `activity`, `date_collected`, `order_status` |
| `procedure_report.json` | `procedure_report` | `procedure_report_id`, `procedure_order_id`, `date_collected`, `date_report`, `report_status` |
| `procedure_result.json` | `procedure_result` | `procedure_result_id`, `procedure_report_id`, `result_code` (LOINC), `result`, `units`, `result_data_type`, `result_status`, `abnormal`, `range`, `date` |
| `prescriptions.json` | `prescriptions` | `id`, `patient_id`, `drug`, `dosage`, `start_date`, `end_date`, `active` |
| `lists.json` | `lists` | `id`, `pid`, `type='medication'`, `title`, `begdate`, `activity` |
| `form_vitals.json` | `form_vitals` | `id`, `pid`, `date`, `bps`, `bpd`, `weight`, `BMI`, `activity` |
| `mod_copilot_cadence.json` | `mod_copilot_cadence` | `config_key`, `config_value`, `version` — intervals, unit conversions, thresholds, turnaround |
| `expected/landmines.json` | — | machine-readable known-answer for every landmine |

Keys `_note` / `_about` are documentation only; real host rows never carry them,
so readers must ignore unknown `_`-prefixed keys (or the reader should
whitelist the real columns).

The 3-table lab join mirrors host `ProcedureService`, `activity = 1` only:
`procedure_order (patient_id, activity=1)` → `procedure_report (procedure_order_id)`
→ `procedure_result (procedure_report_id, result_code = LOINC)`. `procedure_result`
has **no pid** — patient scoping is only reachable through the join.

LOINC codes used: A1c `4548-4`, glucose `2345-7`, LDL `13457-7`, urine ACR `9318-7`.

## Patients

| pid | name | carries |
|---|---|---|
| 9001 | Diego Rivera | rising A1c trend, med-dose-vs-A1c mismatch, mmol/mol A1c |
| 9002 | Mei Chen | overdue urine ACR, corrected lab (new-row variant), drawn-but-unresulted order |
| 9003 | Amara Okafor | `<7.0` censored value, unitless value, preliminary result, unrecognized status |
| 9004 | Rafael Santos | corrected lab (in-place UPDATE variant), late-arriving/backdated lab (C1 fallback) |

## Landmine → known-answer map

Each row is the contract eval's known-answer. Full detail (parsed values,
flags, derived facts) lives in `expected/landmines.json`.

| # | Landmine | pid | rows | Expected conclusion |
|---|---|---|---|---|
| L1 | Rising A1c trend | 9001 | `procedure_result` 6101,6102,6103 | ControlProxy trend 7.1% → 7.8% → 8.5% (last converted from mmol/mol); derived_delta +1.36 rising, each point cites its row |
| L2 | Med-dose-vs-A1c mismatch | 9001 | `prescriptions` 7101 / `lists` 8101 + A1c trend | MedResponse pairs stable 500 mg metformin with rising A1c, cites both, **asserts no causation**; union dedups the duplicate med |
| L3 | Overdue urine ACR | 9002 | `procedure_result` 6201 + cadence `code_set:acr` | OverdueTests: last ACR 2024-11-15 + 365 d < 2026-07-07 ⇒ **overdue**; no pending ACR ⇒ reorder note **not** suppressed |
| L4 | Late-arriving / backdated lab | 9004 | `procedure_result` 6402 (report 5402, order 4402) | clinical_date = 2026-03-05 with `date_source = fallback` (report+order date_collected NULL); sorts earlier in trend; re-extract changes digest (E1) |
| L5 | Corrected lab — in-place UPDATE | 9004 | `procedure_result` 6401 | Single row, `status = corrected`, value 7.4 (was 8.9); **no** superseded sibling; presented as corrected (E2 digest change) |
| L6 | Corrected lab — new-row correction | 9002 | `procedure_result` 6202,6203 | corrected 6203 (7.6) wins over final 6202 (8.0), same code+date; 6202 flagged `superseded_1` + excluded; citation "supersedes 1 prior" |
| L7 | Drawn-but-unresulted order | 9002 | `procedure_order` 4203 + `procedure_report` 5203, **no results** | PendingResults: in-flight pending_order; never a result, never resets overdue clock; derived expected_result_date 2026-07-04 (turnaround:a1c=2 d) |
| L8 | `"<7.0"` censored value | 9003 | `procedure_result` 6301 | comparator `lt`, `censored` flag; only the direction is provable — no exact numeric claim |
| L9 | Unitless value | 9003 | `procedure_result` 6302 | `units=''` ⇒ `status=excluded`, reason `no_unit`; **visible** but excluded from thresholds/trends; counts toward unitless-exclusion rate (I5) |
| L10 | mmol/mol A1c value | 9001 | `procedure_result` 6103 + cadence `unit:a1c` | 69 mmol/mol → 8.46% NGSP; fact carries original + canonical unit + `conversion_version conv:a1c@1` (digest input) |
| L11 | Unrecognized result_status | 9003 | `procedure_result` 6304 | `result_status='transcribed'` ⇒ `status=excluded`, reason `unrecognized_status`; visible; does not reset overdue clock |
| L12 | Preliminary result | 9003 | `procedure_result` 6303 | `status=preliminary`, kind `preliminary_result`; in-flight section only, **not** a trend point, does **not** reset overdue clock |

### C1 date-precedence coverage

- **collected** (authoritative): all rows except L4 take
  `procedure_report.date_collected` (present).
- **fallback**: L4 (row 6402) has report+order `date_collected = NULL`, so the
  clinical date falls through to `procedure_result.date` and the fact carries
  `date_source = fallback`.

### Digest eval anchors (used by U8 end-to-end E1–E7)

- **E1 late arrival** — L4: inserting/altering row 6402 (backdated) changes the digest.
- **E2 in-place correction** — L5: row 6401 value/status change changes the digest.
- **E4 irrelevant churn** — untracked LOINC / other-patient rows leave the digest unchanged (add such a row in the E4 test; fixtures deliberately keep every row inside a tracked slice).
- **E5 config drift** — bump `mod_copilot_cadence.version` ⇒ affected docs invalidate.
