# BMOS CRM 1.0 — native customer & sales module

CRM is a first-class BMOS domain, not a bolted-on product. It reuses the
existing tenants, session auth, forced RLS, and audit infrastructure. No new
containers, no new databases.

**Scope of 1.0:** leads, companies (accounts), contacts, notes, activities,
search. The schema also creates the opportunity/pipeline/stage and tag tables
so 1.1 builds on the same foundation; their UI is not in 1.0.

## Architecture

```
Lead → (qualify/convert) → Account + Contact → (1.1) Opportunity → Project → Tasks
```

- A lead is a prospect. Conversion is transactional and preserves the lead
  and its history — never deleted.
- All writes go through `app/src/Crm.php`. The caller owns the transaction
  and must `SET LOCAL app.current_tenant` first; RLS enforces isolation.
- Every business table has `tenant_id` + forced RLS.
- Table-to-table relationships use composite `(tenant_id, id)` foreign keys,
  so a record in tenant A can never reference tenant B's records.
- Polymorphic links (`entity_type` + `entity_id` on notes, activity links,
  record tags) are validated in PHP against an allowlist
  (`lead|account|contact|opportunity`) and the target is verified to exist
  in the caller's tenant via `Crm::assertEntity()` (RLS-scoped).
- Important changes write to `app.audit_events` (actor, action, entity,
  JSONB details). Notes and activities are their own history and are not
  audit-logged individually.
- Notes refuse obvious credential material (`password:`, `api_key=`, …) —
  defense in depth; never store secrets in CRM.

## Schema (migration 011)

| Table | Purpose |
|---|---|
| `app.audit_events` | Append-only audit log (new in 011 — none existed) |
| `app.crm_leads` | Prospects; status new/contacted/qualified/disqualified/converted |
| `app.crm_accounts` | Companies; status prospect/customer/former |
| `app.crm_contacts` | People |
| `app.crm_account_contacts` | People ↔ companies (composite FK, one primary per account) |
| `app.crm_pipelines` / `app.crm_stages` | Sales pipeline definitions (1.1) |
| `app.crm_opportunities` | Deals, value_cents, expected_close (1.1) |
| `app.crm_activities` | Calls, meetings, emails, notes, follow-ups; due_at/completed_at |
| `app.crm_activity_links` | Activity → record links (polymorphic) |
| `app.crm_notes` | Internal notes on any record (polymorphic) |
| `app.crm_tags` / `app.crm_record_tags` | Categorization (1.1 UI) |

## Routes (all under `/crm`, session or Bearer auth)

Pages: `/crm` (overview + search), `/crm/leads`, `/crm/leads/{id}`,
`/crm/accounts`, `/crm/accounts/{id}`, `/crm/contacts`,
`/crm/contacts/{id}`, `/crm/activities`.

HTMX partials: `/crm/leads/list`, `/crm/accounts/list`, `/crm/contacts/list`,
`/crm/activities/list`, `/crm/notes/list`, `/crm/search`.

Mutations: `POST /crm/leads/create`, `POST /crm/leads/{id}/status`,
`POST /crm/leads/{id}/convert` (JSON), `POST /crm/accounts/create`,
`POST /crm/contacts/create`, `POST /crm/contacts/link` (JSON),
`POST /crm/activities/create`, `POST /crm/activities/{id}/complete`,
`POST /crm/notes/create`.

Routes live in `app/crm-routes.php`, dispatched from `index.php`.
Views in `app/views/crm/`. Sidebar section "CRM" added to `layout.php`.

## Tests

`scripts/test-crm.sh` (wired into `scripts/verify.sh`):

- HTTP: unauthenticated → 401; CRUD; XSS escaping; SQL-injection literal
  storage; status transitions; conversion + double-convert idempotency;
  cross-tenant UUID link rejection; note credential refusal; activity
  overdue filter + completion; tenant-scoped search; overview renders.
- DB (as `bmos_app`): RLS enabled+forced on all 13 CRM/audit tables;
  tenant B cannot read tenant A's rows; cross-tenant composite-FK link
  rejected; cross-tenant INSERT rejected by RLS WITH CHECK.

## Known limitations (1.0)

- **No CSRF tokens.** Same as the rest of the BMOS app — session POSTs
  rely on SameSite cookies. App-wide CSRF is recommended follow-up
  hardening, not a CRM-only bolt-on.
- Pipelines, opportunities UI, tags UI, proposals, reports, and outbox
  reminder jobs are 1.1+ scope. Tables exist; UI does not.
- No email integration; activities of type `email` are manual logs.
- AI assistance (summaries, draft follow-ups) is 1.3 scope.

## Deploy

```bash
unzip -o ~/nexgen-bmos-phase18.zip -d ~/projects/nexgen-bmos
cd ~/projects/nexgen-bmos
bash scripts/migrate.sh          # applies 011-crm.sql
docker compose up -d --build php # if PHP files changed (they did)
bash scripts/test-crm.sh         # ADMIN_EMAIL / ADMIN_PASSWORD required
```
