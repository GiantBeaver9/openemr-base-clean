#!/usr/bin/env python3
"""Generate an evergreen clinical seed for the Clinical Co-Pilot LLM.

Emits SQL that populates a realistic outpatient endocrinology day: a cohort of
type-2-diabetes patients, each with the exact clinical material the co-pilot's
five capabilities read (A1c series, meds, vitals trend, overdue tests, pending
results) plus a scheduled appointment so the "today's schedule" surface has
patients to pre-warm and chat about.

Dates are RELATIVE (CURDATE()/NOW()) so the appointments always land on the day
the file is loaded -- the seed never goes stale.
"""

# ---- fixed ID blocks (base install has no clinical rows, so explicit ids are safe) ----
PROVIDER_ID = 90          # the endocrinologist
PID_BASE = 9001
ENC_BASE = 90000          # encounter numbers: ENC_BASE + pid_offset*10 + visit
VITALS_BASE = 800000
LIST_BASE = 810000
RX_BASE = 820000
PORDER_BASE = 700000
PREPORT_BASE = 710000
PRESULT_BASE = 720000
EVENT_BASE = 5000

FACILITY_ID = 3
FACILITY_NAME = "Your Clinic Name Here"
PROVIDER_NAME = "Alan Grant"   # username egrant

# LOINC codes used
LOINC = {
    "a1c": ("4548-4", "Hemoglobin A1c/Hemoglobin.total in Blood"),
    "glucose": ("2345-7", "Glucose [Mass/volume] in Serum or Plasma"),
    "chol": ("2093-3", "Cholesterol [Mass/volume] in Serum or Plasma"),
    "ldl": ("13457-7", "Cholesterol in LDL [Mass/volume] estimated"),
    "hdl": ("2085-9", "Cholesterol in HDL [Mass/volume] in Serum or Plasma"),
    "trig": ("2571-8", "Triglyceride [Mass/volume] in Serum or Plasma"),
    "egfr": ("33914-3", "GFR/1.73 sq M predicted"),
    "creat": ("2160-0", "Creatinine [Mass/volume] in Serum or Plasma"),
    "microalb": ("14959-1", "Microalbumin/Creatinine [Mass Ratio] in Urine"),
}


def sqlstr(v):
    if v is None:
        return "NULL"
    if isinstance(v, Raw):
        return v.expr
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"


class Raw:
    """A raw SQL expression that must NOT be quoted (e.g. NOW() - INTERVAL 3 MONTH)."""
    def __init__(self, expr):
        self.expr = expr


def months_ago(n):
    return Raw(f"(NOW() - INTERVAL {n} MONTH)")


def days_ago(n):
    return Raw(f"(NOW() - INTERVAL {n} DAY)")


def date_months_ago(n):
    return Raw(f"(CURDATE() - INTERVAL {n} MONTH)")


def appt_day(offset):
    if offset == 0:
        return Raw("CURDATE()")
    return Raw(f"(CURDATE() + INTERVAL {offset} DAY)")


def appt_time(offset, hhmmss):
    day = "CURDATE()" if offset == 0 else f"(CURDATE() + INTERVAL {offset} DAY)"
    return Raw(f"TIMESTAMP({day}, '{hhmmss}')")


def insert(table, cols, rows):
    out = []
    collist = ", ".join(f"`{c}`" for c in cols)
    out.append(f"INSERT INTO `{table}` ({collist}) VALUES")
    vals = []
    for r in rows:
        vals.append("(" + ", ".join(sqlstr(v) for v in r) + ")")
    out.append(",\n".join(vals) + ";")
    return "\n".join(out)


# ---------------------------------------------------------------------------
# Cohort definition. Each patient carries a compact clinical story so the chat
# agent has varied, verifiable material. Values are chosen to exercise every
# capability branch (corrected labs, censored "<7.0", pending, overdue, etc.).
# ---------------------------------------------------------------------------
# a1c_series: list of (months_ago, value_string, result_status, abnormal)
#   value_string may be "<7.0" (censored) -- ControlProxy must handle it.
# meds: list of (drug, dosage, qty, refills, months_started, note)
# vitals: list of (months_ago, bps, bpd, weight_lb, pulse) height fixed per pt
# lipids_last_months: how long since last lipid panel (None => none on file)
# microalb_last_months, eye_exam_last_months similar (for OverdueTests)
# pending: list of (test_code_key, name) ordered days_ago=2, no final result
# appt: (day_offset, "HH:MM:SS", category_id, reason)

COHORT = [
    dict(
        pid=PID_BASE + 0, fname="Aaron", lname="Delgado", sex="Male", dob="1962-04-18",
        height=70, story="Well-controlled T2DM; latest A1c reported censored as <7.0.",
        a1c=[(12, "7.8", "final", "high"), (6, "7.1", "final", "high"), (2, "<7.0", "final", "")],
        meds=[("Metformin", "1000 mg", "180", 3, 26, "1000 mg PO BID with meals")],
        vitals=[(12, "132", "82", 214, 76), (6, "128", "80", 209, 74), (2, "126", "78", 205, 72)],
        lipids_last=4, microalb_last=5, eye_exam_last=8,
        pending=[], appt=(0, "09:00:00", 9, "Diabetes follow-up"),
    ),
    dict(
        pid=PID_BASE + 1, fname="Bianca", lname="Okafor", sex="Female", dob="1970-09-02",
        height=65, story="Worsening control, missed metformin refills; glipizide just added.",
        a1c=[(12, "7.4", "final", "high"), (6, "8.2", "final", "high"), (2, "9.1", "final", "high")],
        meds=[("Metformin", "1000 mg", "180", 0, 30, "1000 mg PO BID -- 0 refills remaining, gap in fills"),
              ("Glipizide", "5 mg", "60", 2, 2, "5 mg PO daily, started this cycle")],
        vitals=[(12, "134", "84", 178, 82), (6, "138", "86", 184, 84), (2, "142", "88", 189, 86)],
        lipids_last=6, microalb_last=7, eye_exam_last=10,
        pending=[], appt=(0, "09:30:00", 9, "Diabetes follow-up - poor control"),
    ),
    dict(
        pid=PID_BASE + 2, fname="Carlos", lname="Nguyen", sex="Male", dob="1958-12-27",
        height=68, story="Prior A1c result was corrected 11.2 -> 8.1 by the lab.",
        a1c=[(12, "8.6", "final", "high"), (6, "8.3", "final", "high"),
             (2, "11.2", "corrected", "high"), (2, "8.1", "corrected", "high")],
        meds=[("Metformin", "1000 mg", "180", 4, 40, "1000 mg PO BID"),
              ("Empagliflozin", "10 mg", "30", 4, 6, "10 mg PO daily")],
        vitals=[(12, "130", "80", 196, 78), (6, "129", "79", 194, 77), (2, "131", "81", 195, 79)],
        lipids_last=5, microalb_last=6, eye_exam_last=9,
        pending=[], appt=(0, "10:00:00", 9, "Diabetes follow-up"),
        a1c_corrected_pair=True,
    ),
    dict(
        pid=PID_BASE + 3, fname="Deborah", lname="Ellis", sex="Female", dob="1965-06-14",
        height=64, story="Good glycemic control but overdue for lipids, microalbumin and eye exam.",
        a1c=[(12, "6.9", "final", ""), (6, "6.8", "final", ""), (3, "6.8", "final", "")],
        meds=[("Metformin", "850 mg", "180", 5, 48, "850 mg PO BID")],
        vitals=[(12, "124", "76", 165, 70), (6, "122", "74", 163, 70), (3, "123", "75", 162, 71)],
        lipids_last=14, microalb_last=19, eye_exam_last=26,   # all overdue
        pending=[], appt=(0, "10:30:00", 9, "Diabetes annual review"),
    ),
    dict(
        pid=PID_BASE + 4, fname="Emilio", lname="Ross", sex="Male", dob="1974-02-08",
        height=71, story="Labs drawn 2 days ago -- A1c and CMP still pending, one report unreviewed.",
        a1c=[(12, "7.9", "final", "high"), (6, "7.7", "final", "high")],
        meds=[("Metformin", "1000 mg", "180", 3, 22, "1000 mg PO BID"),
              ("Atorvastatin", "20 mg", "30", 5, 22, "20 mg PO at bedtime")],
        vitals=[(12, "127", "79", 201, 75), (6, "126", "78", 199, 74)],
        lipids_last=6, microalb_last=6, eye_exam_last=7,
        pending=[("a1c", "Hemoglobin A1c"), ("glucose", "Comprehensive metabolic panel - Glucose")],
        appt=(0, "11:00:00", 9, "Diabetes follow-up - review pending labs"),
    ),
    dict(
        pid=PID_BASE + 5, fname="Farida", lname="Haddad", sex="Female", dob="1968-11-19",
        height=63, story="High A1c, basal insulin glargine just initiated on top of metformin.",
        a1c=[(12, "9.4", "final", "high"), (6, "9.9", "final", "high"), (1, "9.8", "final", "high")],
        meds=[("Metformin", "1000 mg", "180", 3, 34, "1000 mg PO BID"),
              ("Insulin glargine", "10 units", "1", 5, 1, "10 units SC at bedtime, titrate per glucose")],
        vitals=[(12, "136", "85", 172, 80), (6, "137", "86", 175, 81), (1, "135", "84", 174, 82)],
        lipids_last=5, microalb_last=8, eye_exam_last=11,
        pending=[], appt=(0, "11:30:00", 9, "Diabetes follow-up - insulin titration"),
    ),
    dict(
        pid=PID_BASE + 9, fname="Julia", lname="Santos", sex="Female", dob="1959-03-30",
        height=62, story="Polypharmacy, stable control; good MedResponse test case.",
        a1c=[(12, "7.3", "final", "high"), (6, "7.2", "final", "high"), (2, "7.2", "final", "high")],
        meds=[("Metformin", "1000 mg", "180", 4, 52, "1000 mg PO BID"),
              ("Empagliflozin", "25 mg", "30", 4, 14, "25 mg PO daily"),
              ("Atorvastatin", "40 mg", "30", 4, 40, "40 mg PO at bedtime"),
              ("Lisinopril", "20 mg", "30", 4, 40, "20 mg PO daily")],
        vitals=[(12, "128", "78", 158, 72), (6, "127", "77", 157, 72), (2, "126", "76", 156, 71)],
        lipids_last=3, microalb_last=4, eye_exam_last=6,
        pending=[], appt=(0, "12:00:00", 9, "Diabetes follow-up"),
    ),
    dict(
        pid=PID_BASE + 6, fname="Gregory", lname="Payne", sex="Male", dob="1951-07-05",
        height=69, story="Diabetic CKD stage 3; metformin dose reduced for renal function.",
        a1c=[(12, "7.5", "final", "high"), (6, "7.6", "final", "high"), (3, "7.6", "final", "high")],
        meds=[("Metformin", "500 mg", "60", 3, 44, "500 mg PO daily (renal-adjusted)"),
              ("Insulin glargine", "16 units", "1", 5, 8, "16 units SC nightly")],
        vitals=[(12, "141", "83", 188, 76), (6, "139", "82", 186, 75), (3, "140", "82", 185, 77)],
        lipids_last=5, microalb_last=4, eye_exam_last=9, egfr="48",
        pending=[], appt=(1, "09:00:00", 9, "Diabetes + CKD follow-up"),
    ),
    dict(
        pid=PID_BASE + 7, fname="Hannah", lname="Weiss", sex="Female", dob="1972-10-12",
        height=66, story="A1c may be running low on sulfonylurea; hypoglycemia risk.",
        a1c=[(12, "6.8", "final", ""), (6, "6.5", "final", ""), (2, "6.4", "final", "low")],
        meds=[("Metformin", "1000 mg", "180", 4, 36, "1000 mg PO BID"),
              ("Glipizide", "10 mg", "60", 3, 20, "10 mg PO daily - reassess for hypoglycemia")],
        vitals=[(12, "121", "74", 149, 68), (6, "120", "73", 148, 69), (2, "119", "72", 147, 68)],
        lipids_last=4, microalb_last=5, eye_exam_last=7,
        pending=[], appt=(1, "09:30:00", 9, "Diabetes follow-up - hypoglycemia review"),
    ),
    dict(
        pid=PID_BASE + 8, fname="Ibrahim", lname="Cole", sex="Male", dob="1980-01-23",
        height=72, story="Newly diagnosed T2DM; metformin just started, first A1c 8.5.",
        a1c=[(1, "8.5", "final", "high")],
        meds=[("Metformin", "500 mg", "60", 3, 1, "500 mg PO daily, titrate to BID as tolerated")],
        vitals=[(1, "133", "83", 221, 80)],
        lipids_last=1, microalb_last=1, eye_exam_last=None,   # never had eye exam
        pending=[], appt=(1, "10:00:00", 10, "New diagnosis - diabetes education"),
    ),
]


def main():
    P = []
    P.append("-- ============================================================================")
    P.append("-- Clinical Co-Pilot test seed  (evergreen: dates are relative to load time)")
    P.append("-- Populates a type-2-diabetes endocrinology day: cohort + scheduled clinic +")
    P.append("-- A1c series, meds, vitals trend, overdue tests, and pending results so the")
    P.append("-- LLM synthesis and the pinned chat agent have real, cited facts to work over.")
    P.append("--")
    P.append("-- Load AFTER a normal OpenEMR install:  mysql -u <u> -p <db> < copilot_test_seed.sql")
    P.append("-- Re-runnable: it clears its own id ranges first.")
    P.append("-- ============================================================================")
    P.append("")
    P.append("SET @prov := %d;" % PROVIDER_ID)
    P.append("")

    # ---- idempotent cleanup of our id ranges ----
    P.append("-- Clear any previous run of this seed (id ranges are reserved for it).")
    pid_lo, pid_hi = PID_BASE, PID_BASE + 100
    cleanups = [
        f"DELETE FROM `patient_data` WHERE `pid` BETWEEN {pid_lo} AND {pid_hi};",
        f"DELETE FROM `form_encounter` WHERE `pid` BETWEEN {pid_lo} AND {pid_hi};",
        f"DELETE FROM `forms` WHERE `pid` BETWEEN {pid_lo} AND {pid_hi};",
        f"DELETE FROM `form_vitals` WHERE `pid` BETWEEN {pid_lo} AND {pid_hi};",
        f"DELETE FROM `lists` WHERE `pid` BETWEEN {pid_lo} AND {pid_hi};",
        f"DELETE FROM `prescriptions` WHERE `patient_id` BETWEEN {pid_lo} AND {pid_hi};",
        f"DELETE FROM `procedure_result` WHERE `procedure_report_id` BETWEEN {PREPORT_BASE} AND {PREPORT_BASE+9999};",
        f"DELETE FROM `procedure_report` WHERE `procedure_order_id` BETWEEN {PORDER_BASE} AND {PORDER_BASE+9999};",
        f"DELETE FROM `procedure_order_code` WHERE `procedure_order_id` BETWEEN {PORDER_BASE} AND {PORDER_BASE+9999};",
        f"DELETE FROM `procedure_order` WHERE `patient_id` BETWEEN {pid_lo} AND {pid_hi};",
        f"DELETE FROM `openemr_postcalendar_events` WHERE `pc_pid` BETWEEN {pid_lo} AND {pid_hi};",
        f"DELETE FROM `users` WHERE `id` = {PROVIDER_ID};",
    ]
    P.extend(cleanups)
    P.append("")

    # ---- provider ----
    P.append("-- Endocrinologist who owns the clinic day (shows on the calendar).")
    P.append(insert("users",
        ["id", "username", "password", "authorized", "source", "fname", "lname",
         "facility", "facility_id", "active", "npi", "title", "specialty",
         "calendar", "cal_ui", "see_auth", "taxonomy", "abook_type"],
        [[PROVIDER_ID, "agrant", None, 1, 0, "Alan", "Grant", FACILITY_NAME, FACILITY_ID,
          1, "1912121212", "MD", "Endocrinology", 1, 3, 1, "207RE0101X", "miscellaneous"]]))
    P.append("")

    # counters for auto-linked child rows
    vit_id = VITALS_BASE
    list_id = LIST_BASE
    rx_id = RX_BASE
    porder_id = PORDER_BASE
    preport_id = PREPORT_BASE
    presult_id = PRESULT_BASE
    event_id = EVENT_BASE

    patient_rows = []
    encounter_rows = []
    forms_rows = []
    vitals_rows = []
    lists_rows = []
    rx_rows = []
    porder_rows = []
    pcode_rows = []
    preport_rows = []
    presult_rows = []
    event_rows = []

    for idx, pt in enumerate(COHORT):
        pid = pt["pid"]
        poff = pid - PID_BASE
        # patient demographics
        patient_rows.append([
            "Mr." if pt["sex"] == "Male" else "Ms.", "english",
            pt["fname"], pt["lname"], pt["dob"], pt["sex"],
            f"{100+poff} Cedar Street", "San Diego", "CA", "92101",
            f"(619) 555-{1000+poff:04d}",
            f"{pt['fname'].lower()}.{pt['lname'].lower()}@example.com",
            "married", Raw("NOW()"), str(pid), pid, PROVIDER_ID,
        ])

        # medical problem: Type 2 diabetes mellitus
        list_id += 1
        lists_rows.append([
            list_id, months_ago(pt["a1c"][0][0] + 2), "medical_problem",
            "Type 2 diabetes mellitus", date_months_ago(pt["a1c"][0][0] + 2), 1, pid,
            "agrant", "ICD10:E11.9", pt["story"],
        ])

        # medications -> lists (type=medication) + prescriptions
        for (drug, dosage, qty, refills, mstart, note) in pt["meds"]:
            list_id += 1
            lists_rows.append([
                list_id, months_ago(mstart), "medication", f"{drug} {dosage}",
                date_months_ago(mstart), 1, pid, "agrant", "", note,
            ])
            rx_id += 1
            rx_rows.append([
                rx_id, pid, months_ago(mstart), PROVIDER_ID, date_months_ago(mstart),
                drug, dosage, qty, refills, 1, note,
                date_months_ago(mstart),  # txDate NOT NULL, no default
                "", "",                   # usage_category_title, request_intent_title (NOT NULL)
            ])

        # one encounter + vitals per historical visit (drives VitalsTrend)
        visit_seq = 0
        for (mago, bps, bpd, wt, pulse) in pt["vitals"]:
            visit_seq += 1
            enc = ENC_BASE + poff * 10 + visit_seq
            height = pt["height"]
            bmi = round((wt / (height * height)) * 703, 1)
            encounter_rows.append([
                enc, months_ago(mago), pt["appt"][3], FACILITY_NAME, FACILITY_ID,
                pid, enc, PROVIDER_ID, 9,
            ])
            forms_rows.append([
                months_ago(mago), enc, "New Patient Encounter", enc, pid,
                "agrant", "Default", 1, "newpatient", PROVIDER_ID,
            ])
            vit_id += 1
            vitals_rows.append([
                vit_id, months_ago(mago), pid, "agrant", "Default", 1, 1,
                bps, bpd, wt, height, pulse, 16, bmi,
            ])
            forms_rows.append([
                months_ago(mago), enc, "Vitals", vit_id, pid,
                "agrant", "Default", 1, "vitals", PROVIDER_ID,
            ])

        # A1c results (drives ControlProxy) -- tie to the nearest historical encounter
        for (mago, val, status, abn) in pt["a1c"]:
            porder_id += 1
            enc_for = ENC_BASE + poff * 10 + 1
            porder_rows.append([
                porder_id, PROVIDER_ID, pid, enc_for, months_ago(mago), months_ago(mago),
                "completed", 1, "E11.9 Type 2 diabetes mellitus", months_ago(mago),
            ])
            pcode_rows.append([
                porder_id, 1, LOINC["a1c"][0], "Hemoglobin A1c", "1", "ICD10:E11.9",
            ])
            preport_id += 1
            preport_rows.append([
                preport_id, porder_id, 1, months_ago(mago), months_ago(mago),
                PROVIDER_ID, "final", "reviewed",
            ])
            presult_id += 1
            # censored value stays a string; ControlProxy handles "<7.0"
            presult_rows.append([
                presult_id, preport_id, "N", LOINC["a1c"][0], "Hemoglobin A1c",
                months_ago(mago), "%", val, "4.0-5.6", abn, status,
                "Corrected result issued by lab." if status == "corrected" and val == "8.1"
                else ("Superseded by corrected result." if status == "corrected" and val == "11.2" else ""),
            ])

        # lipid panel history (OverdueTests keys on recency of the last one)
        if pt["lipids_last"] is not None:
            porder_id += 1
            m = pt["lipids_last"]
            porder_rows.append([
                porder_id, PROVIDER_ID, pid, ENC_BASE + poff * 10 + 1, months_ago(m),
                months_ago(m), "completed", 1, "E11.9 Type 2 diabetes mellitus", months_ago(m),
            ])
            for seq, key in enumerate(["chol", "ldl", "hdl", "trig"], start=1):
                pcode_rows.append([porder_id, seq, LOINC[key][0], LOINC[key][1], "1", "ICD10:E11.9"])
            preport_id += 1
            preport_rows.append([
                preport_id, porder_id, 1, months_ago(m), months_ago(m),
                PROVIDER_ID, "final", "reviewed",
            ])
            lipid_vals = {"chol": ("178", "<200"), "ldl": ("96", "<100"),
                          "hdl": ("44", ">40"), "trig": ("162", "<150")}
            for key in ["chol", "ldl", "hdl", "trig"]:
                presult_id += 1
                res, rng = lipid_vals[key]
                abn = "high" if key == "trig" else ""
                presult_rows.append([
                    presult_id, preport_id, "N", LOINC[key][0], LOINC[key][1],
                    months_ago(m), "mg/dL", res, rng, abn, "final", "",
                ])

        # microalbumin history (another OverdueTests axis)
        if pt.get("microalb_last") is not None:
            porder_id += 1
            m = pt["microalb_last"]
            porder_rows.append([
                porder_id, PROVIDER_ID, pid, ENC_BASE + poff * 10 + 1, months_ago(m),
                months_ago(m), "completed", 1, "E11.9 Type 2 diabetes mellitus", months_ago(m),
            ])
            pcode_rows.append([porder_id, 1, LOINC["microalb"][0], LOINC["microalb"][1], "1", "ICD10:E11.9"])
            preport_id += 1
            preport_rows.append([
                preport_id, porder_id, 1, months_ago(m), months_ago(m),
                PROVIDER_ID, "final", "reviewed",
            ])
            presult_id += 1
            presult_rows.append([
                presult_id, preport_id, "N", LOINC["microalb"][0], LOINC["microalb"][1],
                months_ago(m), "mg/g", "18", "<30", "", "final", "",
            ])

        # eGFR for the CKD patient
        if pt.get("egfr"):
            porder_id += 1
            porder_rows.append([
                porder_id, PROVIDER_ID, pid, ENC_BASE + poff * 10 + 1, months_ago(3),
                months_ago(3), "completed", 1, "N18.3 Chronic kidney disease stage 3", months_ago(3),
            ])
            pcode_rows.append([porder_id, 1, LOINC["egfr"][0], LOINC["egfr"][1], "1", "ICD10:N18.3"])
            preport_id += 1
            preport_rows.append([
                preport_id, porder_id, 1, months_ago(3), months_ago(3),
                PROVIDER_ID, "final", "reviewed",
            ])
            presult_id += 1
            presult_rows.append([
                presult_id, preport_id, "N", LOINC["egfr"][0], LOINC["egfr"][1],
                months_ago(3), "mL/min/1.73m2", pt["egfr"], ">60", "low", "final",
                "Consistent with CKD stage 3.",
            ])

        # PENDING results (drives PendingResults): ordered 2 days ago, no final result.
        for (key, name) in pt["pending"]:
            porder_id += 1
            porder_rows.append([
                porder_id, PROVIDER_ID, pid, ENC_BASE + poff * 10 + 1, days_ago(2),
                days_ago(2), "pending", 1, "E11.9 Type 2 diabetes mellitus", days_ago(2),
            ])
            pcode_rows.append([porder_id, 1, LOINC[key][0], name, "1", "ICD10:E11.9"])
            # a report row that has arrived but is NOT yet reviewed
            preport_id += 1
            preport_rows.append([
                preport_id, porder_id, 1, days_ago(2), None,
                PROVIDER_ID, "received", "received",
            ])

        # scheduled appointment (drives the "today's schedule" pre-warm surface)
        off, hhmmss, catid, reason = pt["appt"]
        endh = hhmmss.split(":")
        end_hhmmss = f"{endh[0]}:{int(endh[1]) + 30:02d}:00" if int(endh[1]) < 30 else \
            f"{int(endh[0]) + 1:02d}:{int(endh[1]) - 30:02d}:00"
        event_id += 1
        event_rows.append([
            event_id, catid, 0, str(PROVIDER_ID), str(pid),
            f"{pt['fname']} {pt['lname']} - {reason}",
            appt_time(off, hhmmss), reason, appt_day(off), 1800,
            Raw(f"'{hhmmss}'"), Raw(f"'{end_hhmmss}'"), "-", FACILITY_ID, FACILITY_ID,
        ])

    # ---- emit in FK-safe order ----
    P.append("-- Patients (type-2-diabetes cohort).")
    P.append(insert("patient_data",
        ["title", "language", "fname", "lname", "DOB", "sex", "street", "city",
         "state", "postal_code", "phone_cell", "email", "status", "date", "pubpid",
         "pid", "providerID"], patient_rows))
    P.append("")
    P.append("-- Problem list + medication list entries.")
    P.append(insert("lists",
        ["id", "date", "type", "title", "begdate", "activity", "pid", "user",
         "diagnosis", "comments"], lists_rows))
    P.append("")
    P.append("-- Prescriptions (MedResponse).")
    P.append(insert("prescriptions",
        ["id", "patient_id", "date_added", "provider_id", "start_date", "drug",
         "dosage", "quantity", "refills", "active", "note", "txDate",
         "usage_category_title", "request_intent_title"], rx_rows))
    P.append("")
    P.append("-- Encounters.")
    P.append(insert("form_encounter",
        ["encounter", "date", "reason", "facility", "facility_id", "pid",
         "encounter", "provider_id", "pc_catid"], encounter_rows)
        .replace("`encounter`, `date`", "`id`, `date`", 1))  # first col is really the PK id
    P.append("")
    P.append("-- Form registrations (make encounters + vitals visible in the chart UI).")
    P.append(insert("forms",
        ["date", "encounter", "form_name", "form_id", "pid", "user", "groupname",
         "authorized", "formdir", "provider_id"], forms_rows))
    P.append("")
    P.append("-- Vitals (VitalsTrend).")
    P.append(insert("form_vitals",
        ["id", "date", "pid", "user", "groupname", "authorized", "activity",
         "bps", "bpd", "weight", "height", "pulse", "respiration", "BMI"], vitals_rows))
    P.append("")
    P.append("-- Lab orders (procedure_order).")
    P.append(insert("procedure_order",
        ["procedure_order_id", "provider_id", "patient_id", "encounter_id",
         "date_collected", "date_ordered", "order_status", "activity",
         "order_diagnosis", "date_transmitted"], porder_rows))
    P.append("")
    P.append("-- Ordered tests (procedure_order_code).")
    P.append(insert("procedure_order_code",
        ["procedure_order_id", "procedure_order_seq", "procedure_code",
         "procedure_name", "procedure_source", "diagnoses"], pcode_rows))
    P.append("")
    P.append("-- Lab reports (procedure_report). 'received'+not-reviewed rows are the pending ones.")
    P.append(insert("procedure_report",
        ["procedure_report_id", "procedure_order_id", "procedure_order_seq",
         "date_collected", "date_report", "source", "report_status",
         "review_status"], preport_rows))
    P.append("")
    P.append("-- Lab results (procedure_result). A1c series incl. corrected + censored '<7.0'.")
    P.append(insert("procedure_result",
        ["procedure_result_id", "procedure_report_id", "result_data_type",
         "result_code", "result_text", "date", "units", "result", "range",
         "abnormal", "result_status", "comments"], presult_rows))
    P.append("")
    P.append("-- Scheduled clinic day (openemr_postcalendar_events) -- the pre-warm surface.")
    P.append(insert("openemr_postcalendar_events",
        ["pc_eid", "pc_catid", "pc_multiple", "pc_aid", "pc_pid", "pc_title", "pc_time",
         "pc_hometext", "pc_eventDate", "pc_duration", "pc_startTime",
         "pc_endTime", "pc_apptstatus", "pc_facility", "pc_billing_location"],
        event_rows))
    P.append("")
    P.append("-- Done. %d patients, appointments today + tomorrow." % len(COHORT))

    print("\n".join(P))


if __name__ == "__main__":
    main()
