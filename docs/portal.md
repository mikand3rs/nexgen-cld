# Docs portal

The public documentation portal is a static site generated from the Markdown
files already in this repo (`README.md` + `docs/*.md`). No framework, no
build service — one script, plain HTML/CSS in the BMOS dark theme.

## Build

```bash
cd ~/projects/nexgen-bmos
bash scripts/build-docs.sh
```

Needs a Markdown converter: `pandoc` or Python `markdown`. The script tells
you which to install if neither is present:

```bash
sudo apt install pandoc
# or: pip install markdown
```

Output lands in `site/` (gitignored — it's generated, never committed to main).

## Preview

```bash
cd ~/projects/nexgen-bmos/site
python3 -m http.server 8080
```

Then open http://localhost:8080.

## Publish (GitHub Pages)

Rebuild the site, then push it to the `gh-pages` branch:

```bash
cd ~/projects/nexgen-bmos
bash scripts/build-docs.sh
git checkout -b gh-pages
git add -f site/
git commit -m "docs portal"
git push origin gh-pages --force
git checkout main
```

Then in the GitHub repo: **Settings → Pages → Deploy from a branch →
`gh-pages` / `/ (root)`**. Your docs will be live at
`https://mikand3rs.github.io/nexgen-cld/` within a minute or two.

Rebuilding later is the same sequence — the `--force` push replaces the old
site. (Alternative: drag the `site/` folder into Netlify Drop or Cloudflare
Pages for a URL without touching git.)

## Adding docs

Write a new `docs/<name>.md` file with a `# Title` first line, rebuild, and it
appears in the sidebar automatically. Keep image references as
`screenshots/<file>` inside `docs/`; the build copies them along.
