#!/usr/bin/env python3
"""Check the UI translations in sources/app/www:
- every locale of STRINGS (i18n.js) defines exactly the same keys;
- every key app.js looks up with t('...') or builds dynamically exists.
"""

import re
import sys
from pathlib import Path

www = Path(sys.argv[1] if len(sys.argv) > 1 else Path(__file__).resolve().parent.parent / "sources/app/www")
i18n = (www / "i18n.js").read_text(encoding="utf-8")
app = (www / "app.js").read_text(encoding="utf-8")

tables = {
    m.group(1): set(re.findall(r"^    (\w+):", m.group(2), re.M))
    for m in re.finditer(r"^  (\w\w): \{\n(.*?)^  \},", i18n, re.S | re.M)
}
if len(tables) < 2 or "en" not in tables:
    sys.exit(f"could not parse STRINGS (found {sorted(tables)})")

status = 0
reference = tables["en"]
for lang, keys in tables.items():
    missing, extra = reference - keys, keys - reference
    if missing or extra:
        status = 1
        print(f"{lang}: missing={sorted(missing)} extra={sorted(extra)}", file=sys.stderr)

used = set(re.findall(r"\bt\('(\w+)'(?!\s*\+)", app))

# Keys built at runtime (t('media_' + kind), t('svc_' + action)) come from what
# the daemon emits: read them from Normalizer.php so the two can't drift apart.
normalizer = (www.parent / "src/Normalizer.php").read_text(encoding="utf-8")
actions = normalizer[normalizer.index("SERVICE_ACTIONS"):normalizer.index("];", normalizer.index("SERVICE_ACTIONS"))]
used |= {"svc_" + a for a in re.findall(r"=> '(\w+)'", actions)} | {"svc_other"}
media = normalizer[normalizer.index("function media("):normalizer.index("function dialogs(")]
kinds = set(re.findall(r"'kind' => '(\w+)'", media)) | set(re.findall(r"\$kind = '(\w+)'", media))
kinds |= {k for pair in re.findall(r"\? '(\w+)' : '(\w+)'", media) for k in pair}
used |= {"media_" + k for k in kinds}

undefined = used - reference
if undefined:
    status = 1
    print(f"app.js uses undefined keys: {sorted(undefined)}", file=sys.stderr)

sys.exit(status)
