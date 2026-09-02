#!/bin/sh
# Post journal-entry payloads (ndjson: {"idem":..,"body":..}) to the Gäld API.
# Runs INSIDE the web container.  Usage: post_entries.sh <ndjson> <token> [base]
# Paces requests under the 60/min API throttle and retries 429 with backoff.
set -eu
FILE="$1"; TOKEN="$2"; BASE="${3:-http://localhost:8080}"
ok=0; fail=0; dup=0; line=0
while IFS= read -r rec; do
  line=$((line + 1))
  idem=$(printf '%s' "$rec" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["idem"];')
  body=$(printf '%s' "$rec" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo json_encode($j["body"]);')
  attempt=0
  while :; do
    attempt=$((attempt + 1))
    code=$(printf '%s' "$body" | curl -s -o /tmp/resp.json -w '%{http_code}' \
      -X POST "$BASE/api/v1/journal-entries" \
      -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
      -H "Accept: application/json" -H "Idempotency-Key: $idem" --data-binary @-)
    case "$code" in
      201|200) ok=$((ok + 1)); break ;;
      409)     dup=$((dup + 1)); break ;;
      429)
        if [ "$attempt" -ge 6 ]; then fail=$((fail + 1)); echo "LINE $line giveup 429 idem=$idem"; break; fi
        sleep 20 ;;
      *)
        fail=$((fail + 1)); echo "LINE $line HTTP $code idem=$idem"; cat /tmp/resp.json; echo; break ;;
    esac
  done
  sleep 1.1
done < "$FILE"
echo "done: ok=$ok dup(409)=$dup fail=$fail"
