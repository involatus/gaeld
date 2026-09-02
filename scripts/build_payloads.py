#!/usr/bin/env python3
"""Turn the cashctrl 2026 journal CSV into Gäld /api/v1/journal-entries payloads.

Outputs newline-delimited JSON: {"idem": "...", "body": {...}}
plus a leading opening-balance entry dated 2026-01-01.
"""
import csv, json, sys, hashlib

CSV = "/Users/pascal/Downloads/Journal 2026-08-26.csv"

# cashctrl account-code prefix -> Gäld account_code
MAP = {
    "1010": "1010",
    "1050": "1100", "1051": "1100", "1052": "1100", "1053": "1100",
    "1300": "1300",
    "2000": "2000",
    "3000": "3000", "3010": "3010", "3100": "3100",
    "6110": "6110", "6120": "6120", "6125": "6125",
    "6130": "6130", "6140": "6140", "6150": "6150",
    "6160": "6950",
}

def acc(cell):
    code = cell.split()[0]
    if code not in MAP:
        raise SystemExit(f"unmapped account: {cell!r}")
    return MAP[code]

def d(cell):  # dd.mm.yyyy -> yyyy-mm-dd
    dd, mm, yy = cell.split(".")
    return f"{yy}-{mm}-{dd}"

def amt(cell):
    return f"{float(cell.replace(chr(39), '').replace(',', '.')):.2f}"

out = []

# ---- opening balances, 01.01.2026 (already balanced: 13'658.21 each side) ----
opening = [
    ("1010", "11614.44", "0.00"),
    ("1100", "222.77",    "0.00"),   # 1050..1053 net: -350.53+168.30+93.30+311.70
    ("1300", "1821.00",   "0.00"),
    ("2000", "0.00",      "432.22"),
    ("2330", "0.00",      "12987.27"),
    ("2960", "0.00",      "238.72"),
]
out.append({"idem": "glastar-opening-2026", "body": {
    "date": "2026-01-01",
    "reference": "EB-2026",
    "description": "Eröffnungsbilanz 01.01.2026 (aus cashctrl)",
    "status": "posted",
    "lines": [
        {"account_code": a, "debit": dr, "credit": cr, "description": "Eröffnungssaldo"}
        for (a, dr, cr) in opening
    ],
}})

# ---- 114 journal rows ----
rows = list(csv.reader(open(CSV, encoding="utf-8-sig"), delimiter=";"))
hdr = rows[0]
n = 0
for r in rows[1:]:
    if not r or not r[0].strip():
        continue
    rec = dict(zip(hdr, r))
    n += 1
    date = d(rec["Datum"])
    soll = acc(rec["Soll"])
    haben = acc(rec["Haben"])
    a = amt(rec["Betrag"])
    beleg = (rec.get("Referenz / Beleg") or "").strip()
    desc = (rec.get("Beschreibung") or "").strip()
    gp = (rec.get("Geschäftspartner") or "").strip()
    full_desc = desc + (f" [{gp}]" if gp else "")
    # deterministic idempotency key from the row's natural content
    key = "glastar-je-" + hashlib.sha1(
        f"{date}|{soll}|{haben}|{a}|{beleg}|{desc}|{n}".encode()
    ).hexdigest()[:16]
    out.append({"idem": key, "body": {
        "date": date,
        "reference": beleg[:100],
        "description": full_desc[:1000],
        "status": "posted",
        "lines": [
            {"account_code": soll,  "debit": a,      "credit": "0.00", "description": full_desc[:500]},
            {"account_code": haben, "debit": "0.00", "credit": a,      "description": full_desc[:500]},
        ],
    }})

with open(sys.argv[1] if len(sys.argv) > 1 else "/dev/stdout", "w") as f:
    for o in out:
        f.write(json.dumps(o, ensure_ascii=False) + "\n")

print(f"wrote {len(out)} payloads (1 opening + {n} journal rows)", file=sys.stderr)
