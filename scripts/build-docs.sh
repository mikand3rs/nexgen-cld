#!/usr/bin/env bash
# Build the static docs portal from Markdown sources.
# Usage: bash scripts/build-docs.sh
# Output: site/ (gitignored) — publish with the steps in docs/portal.md
set -euo pipefail

DOCS_DIR="docs"
OUT="site"

if command -v pandoc >/dev/null 2>&1; then
  CONVERTER="pandoc"
elif python3 -c "import markdown" >/dev/null 2>&1; then
  CONVERTER="pymarkdown"
else
  echo "ERROR: need a Markdown converter."
  echo "  sudo apt install pandoc      # Debian/Ubuntu/WSL"
  echo "  # or: pip install markdown"
  exit 1
fi

rm -rf "$OUT"
mkdir -p "$OUT"

# Screenshots ride along if present.
if [ -d "$DOCS_DIR/screenshots" ]; then
  mkdir -p "$OUT/screenshots"
  cp "$DOCS_DIR"/screenshots/* "$OUT/screenshots/" 2>/dev/null || true
fi

python3 - "$DOCS_DIR" "$OUT" "$CONVERTER" <<'PYEOF'
import os, re, sys, html
try:
    import markdown as mdlib
except ImportError:
    mdlib = None

docs_dir, out_dir, converter = sys.argv[1], sys.argv[2], sys.argv[3]

SOURCES = []
if os.path.exists("README.md"):
    SOURCES.append(("README.md", "Overview"))
for name in sorted(os.listdir(docs_dir)):
    if name.endswith(".md"):
        SOURCES.append((os.path.join(docs_dir, name), None))

def title_of(path, fallback):
    with open(path, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if line.startswith("# "):
                return line[2:].strip()
    return fallback

def to_html(path):
    with open(path, encoding="utf-8") as f:
        text = f.read()
    # Local .md links become .html links; README's docs/screenshots/ refs become screenshots/
    text = re.sub(r"\(([^)]*?)\.md(#.*)?\)",
                  lambda m: "(%s.html%s)" % (m.group(1), m.group(2) or ""), text)
    text = text.replace("(docs/screenshots/", "(screenshots/")
    if converter == "pandoc":
        import subprocess
        r = subprocess.run(["pandoc", "-f", "markdown", "-t", "html5"],
                           input=text, capture_output=True, text=True)
        if r.returncode != 0:
            raise RuntimeError("pandoc failed: " + r.stderr)
        return r.stdout
    return mdlib.markdown(text, extensions=["fenced_code", "tables", "toc"])

pages = []
for src, label in SOURCES:
    base = os.path.splitext(os.path.basename(src))[0]
    out_name = "index.html" if src == "README.md" else base + ".html"
    pages.append((out_name, label or title_of(src, base), src))

nav = "\n".join(
    '        <a href="%s">%s</a>' % (name, html.escape(title))
    for name, title, _ in pages
)

TEMPLATE = """<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} — NexGen CLD docs</title>
<style>
  :root {{ color-scheme: dark; }}
  body {{ background:#08080c; color:#e8e8ee; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; margin:0; }}
  .wrap {{ display:flex; min-height:100vh; }}
  nav {{ width:264px; flex-shrink:0; border-right:1px solid #1c1c26; padding:24px 16px; position:sticky; top:0; height:100vh; overflow:auto; box-sizing:border-box; }}
  nav h2 {{ font-size:12px; text-transform:uppercase; letter-spacing:.08em; color:#8b8b9e; margin:0 0 12px 8px; }}
  nav a {{ display:block; padding:8px 12px; border-radius:8px; color:#c9c9d8; text-decoration:none; font-size:14px; }}
  nav a:hover {{ background:#14141c; color:#fff; }}
  main {{ flex:1; max-width:860px; padding:40px 48px; }}
  h1,h2,h3 {{ color:#fff; }}
  a {{ color:#67e8f9; }}
  code {{ background:#14141c; padding:2px 6px; border-radius:6px; font-size:.9em; }}
  pre {{ background:#101016; border:1px solid #1c1c26; border-radius:12px; padding:16px; overflow:auto; }}
  pre code {{ background:none; padding:0; }}
  table {{ border-collapse:collapse; width:100%; margin:16px 0; }}
  th,td {{ border:1px solid #262633; padding:8px 12px; text-align:left; font-size:14px; }}
  th {{ background:#101016; }}
  img {{ max-width:100%; border-radius:12px; }}
  blockquote {{ border-left:3px solid #67e8f9; margin:16px 0; padding:4px 16px; color:#b9b9cc; }}
</style>
</head>
<body>
<div class="wrap">
  <nav>
    <h2>NexGen CLD docs</h2>
{nav}
  </nav>
  <main>
{body}
  </main>
</div>
</body>
</html>
"""

for out_name, title, src in pages:
    body = to_html(src)
    page = TEMPLATE.format(title=html.escape(title), nav=nav, body=body)
    with open(os.path.join(out_dir, out_name), "w", encoding="utf-8") as f:
        f.write(page)

print("Built %d pages into %s/" % (len(pages), out_dir))
PYEOF

echo "Done. Preview locally: cd $OUT && python3 -m http.server 8080"
