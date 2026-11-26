#!/usr/bin/env bash
# Run before every release. Stops at the first failure.
set -e
cd "$(dirname "$0")/.."
echo "1. HTML and script syntax"
python3 - <<'PY'
import re, subprocess, sys
s = open("index.html", encoding="utf-8").read()
js = re.search(r"<script>(.*)</script>", s, re.S).group(1)
open("/tmp/factory_check.js", "w", encoding="utf-8").write(js)
subprocess.run(["node", "--check", "/tmp/factory_check.js"], check=True)
print("   ok")
PY
echo "2. No personal data or secrets"
if grep -n -i -E "mekala|vikram|riaan|inaaya|arya|stanns|charters|new haw|@gmail|\+447|miru-digest|SUPABASE_KEY=|CLIENT_SECRET|ghp_|sk-ant|SERVICE_ROLE" index.html; then
  echo "   FAIL: personal data or a secret found"; exit 1
fi
echo "   ok"
echo "3. Page loads its scripts without errors (stand-in browser)"
node dev/tests/load_test.js
echo "4. Company brief page and its PHP"
python3 - <<'PY'
import re, subprocess
s = open("company/index.html", encoding="utf-8").read()
open("/tmp/company_check.js", "w", encoding="utf-8").write(re.search(r"<script>(.*)</script>", s, re.S).group(1))
subprocess.run(["node", "--check", "/tmp/company_check.js"], check=True)
PY
for f in api/company.php api/company_lib.php api/company_ai.php; do php -l "$f" >/dev/null; done
if grep -n -E "sk-ant|ghp_|COMPANIES_HOUSE_KEY' *=> *'[A-Za-z0-9]" company/index.html api/company*.php; then echo "   FAIL: a key is in the code"; exit 1; fi
echo "   ok"
echo "All checks passed."
