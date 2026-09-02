#!/usr/bin/env python3
"""Post journal-entry payloads (ndjson) to a remote Gäld API, paced under the
60/min throttle, retrying 429 with backoff. Usage:
  post_remote.py payloads.ndjson  "https://host/api/v1"  "<token>"
"""
import json, sys, time, urllib.request, urllib.error

ndjson, base, token = sys.argv[1], sys.argv[2].rstrip("/"), sys.argv[3]
ok = dup = fail = 0
items = [json.loads(l) for l in open(ndjson) if l.strip()]
for i, rec in enumerate(items, 1):
    body = json.dumps(rec["body"]).encode()
    for attempt in range(1, 8):
        req = urllib.request.Request(
            f"{base}/journal-entries", data=body, method="POST",
            headers={"Authorization": f"Bearer {token}",
                     "Content-Type": "application/json",
                     "Accept": "application/json",
                     "Idempotency-Key": rec["idem"]})
        try:
            r = urllib.request.urlopen(req, timeout=30)
            ok += 1
            break
        except urllib.error.HTTPError as e:
            code = e.code
            payload = e.read().decode()[:200]
            if code == 429:
                time.sleep(15)
                continue
            if code == 409:
                dup += 1
                print(f"[{i}] 409 {payload}")
                break
            fail += 1
            print(f"[{i}] HTTP {code} idem={rec['idem']} {payload}")
            break
        except Exception as ex:
            if attempt == 7:
                fail += 1
                print(f"[{i}] {ex}")
            else:
                time.sleep(5)
    time.sleep(1.15)          # ~52/min, safely under the 60/min cap
    if i % 20 == 0:
        print(f"  ...{i}/{len(items)}  ok={ok} dup={dup} fail={fail}")
print(f"DONE ok={ok} dup(409)={dup} fail={fail}  (of {len(items)})")
