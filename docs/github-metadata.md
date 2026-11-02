# GitHub repo metadata

Set once in the GitHub web UI. On the repo's main page, click the gear icon
next to **About** (top right) and fill in:

- **Description:**
  `NexGen CLD v4 (BMOS 3.2) — self-hosted, multi-tenant business operating system. PHP 8.4 + PostgreSQL 17 (forced RLS) + Qdrant + Garage S3 + n8n + local/cloud AI.`
- **Website:** `https://www.nexgencld.com`
- **Topics:** `php` `postgresql` `multi-tenant` `self-hosted` `docker` `crm` `rag` `row-level-security`
- Check **"Include in the home page"** if offered.

Then create the release: **Releases → Draft a new release →**

- **Tag:** `v4.0.0` (create on publish, targeting `main`)
- **Release title:** `v4.0.0 — BMOS 3.2 + CRM 1.0`
- **Description:** paste the contents of `RELEASE-NOTES-v4.0.0.md`
