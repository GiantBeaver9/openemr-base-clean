# Clinical Co-Pilot test seed

`copilot_test_seed.sql` populates a realistic outpatient-endocrinology day so you
have data to exercise the Clinical Co-Pilot's pre-visit synthesis and the
patient-pinned chat agent (see `ARCHITECTURE.md`). It seeds a type-2-diabetes
cohort, a scheduled clinic, and — for each patient — the exact clinical material
the co-pilot's five deterministic capabilities read.

## What it creates

- **1 provider** — Dr. Alan Grant, Endocrinology (`users.id = 90`, shows on the calendar).
- **10 type-2-diabetes patients** (`pid 9001–9010`), each with a distinct story.
- **A full clinic day of appointments** in `openemr_postcalendar_events`: 7 today,
  3 tomorrow. Dates are **relative to load time** (`CURDATE()` / `NOW()`), so the
  schedule always lands on the day you load the file — the seed never goes stale.
- Per patient: historical **encounters + vitals**, an **A1c series**, **medications**
  (problem list + prescriptions), and **lab orders/reports/results**.

Each patient targets a capability branch:

| pid  | Patient        | Exercises |
|------|----------------|-----------|
| 9001 | Aaron Delgado  | ControlProxy — latest A1c reported **censored `<7.0`** |
| 9002 | Bianca Okafor  | MedResponse — **0 refills / fill gap**; VitalsTrend rising BP + weight |
| 9003 | Carlos Nguyen  | ControlProxy — a **corrected** A1c (11.2 → 8.1) |
| 9004 | Deborah Ellis  | OverdueTests — lipids 14mo, microalbumin 19mo, eye exam 26mo |
| 9005 | Emilio Ross    | PendingResults — labs drawn 2 days ago, **pending / unreviewed** |
| 9006 | Farida Haddad  | Newly started basal insulin on top of metformin |
| 9007 | Gregory Payne  | Diabetic CKD, renal-adjusted metformin, low eGFR (tomorrow) |
| 9008 | Hannah Weiss   | A1c running low on a sulfonylurea — hypoglycemia risk (tomorrow) |
| 9009 | Ibrahim Cole   | Newly diagnosed, first A1c only, never had an eye exam (tomorrow) |
| 9010 | Julia Santos   | MedResponse — **polypharmacy** (4 meds), stable control |

All data is synthetic. No real PHI.

## Loading it

Load **after** a normal OpenEMR install (the base schema and the default
`facility` row must already exist):

```bash
mysql -u <user> -p <database> < sql/copilot_test_seed.sql
```

Docker dev stack:

```bash
docker compose -f docker/development-easy/docker-compose.yml \
  exec -T mysql mysql -uopenemr -popenemr openemr < sql/copilot_test_seed.sql
```

The script is **re-runnable**: it clears its own reserved id ranges
(`pid 9001–9101`, provider `id 90`, procedure ids `700000+`/`710000+`) before
inserting, so loading it twice is safe.

To see the data in the UI, log in, open the **Calendar** for provider *Alan Grant*
for today, and click any appointment through to the patient chart (labs under
Reports → Procedures, meds on the patient summary, vitals in the encounter).

## Regenerating

The SQL is emitted by `copilot_test_seed.gen.py` (plain Python 3, no deps). Edit
the `COHORT` list to change patients/stories, then:

```bash
python3 sql/copilot_test_seed.gen.py > sql/copilot_test_seed.sql
```
