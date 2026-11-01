# Board guidelines — UI web access for BMOS (Preline template analysis)

Filed 2026-11-01. Michelle pasted this ChatGPT analysis and identified it as
**guidelines from the board on how to build the UI for web access,
custom-coded for the BMOS stack** — i.e., the web-access UI layer is to be
built custom on this stack (PHP 8.4, PostgreSQL 17, Tailwind/HTMX/Alpine),
not bought as SaaS or built on a different stack.
Status: **reference only — not scheduled.** BMOS is still in backend-foundation
phases (outbox/worker now; Qdrant, MinIO, n8n, Lemonade later). When the
application UI phase arrives, this maps Preline templates to BMOS modules.

Standing decision captured here: **Preline is the presentation layer, not the
application architecture.** Tailwind CSS + HTMX + Alpine.js on the PHP-native,
server-rendered stack (matches the project's UI direction); PHP stays
authoritative for business state; the AI/chat interface talks to the PHP app,
not directly to AI infrastructure. Suggested prerequisite before integrating:
a BMOS master UI spec mapping every Preline page to its PHP controller,
route, database entity, permissions, and API endpoints.

---

After comparing Preline's templates against your **BMOS 3.2 Architecture**, I would recommend building BMOS using a combination of Preline templates rather than selecting just one.

BMOS is much closer to a **multi-tenant business operating system (SaaS)** than a conventional admin dashboard.

You need an interface that combines:

* Business administration.
* Customer and client management.
* Project and task management.
* Employee and team management.
* Document and knowledge management.
* AI assistants and agents.
* Workflow automation.
* Reporting and analytics.
* Billing and account management.
* System administration.

Your architecture already supports the infrastructure for these capabilities through PHP 8.4, PostgreSQL 17, Redis, Qdrant, MinIO, n8n and Lemonade AI.

# 1. Best Preline templates for BMOS

Here is my ranking.

## 1. Admin Dashboard — Best overall foundation

[Admin Dashboard – Preline](https://www.preline.co/templates/dashboards/admin-dashboard/details.html?utm_source=chatgpt.com)

**31 ready-made pages.**

This is my first choice for the BMOS application shell.

Includes:

* Main dashboard.
* User management.
* Teams.
* User profiles.
* Account settings.
* Billing.
* Integrations.
* Calendar.
* Chat.
* Inbox.
* Kanban boards.
* File management.
* To-do lists.
* Project management.

**BMOS functionality it supports:**

| BMOS module             | Template functionality |
| ----------------------- | ---------------------- |
| Command Center          | Main dashboard         |
| User Management         | Users                  |
| Organization Management | Teams                  |
| Account Management      | Account settings       |
| Project Management      | Projects               |
| Task Management         | Kanban / To-do         |
| Document Management     | Files                  |
| Communications          | Chat / Inbox           |
| Billing                 | Plans and invoices     |
| Scheduling              | Calendar               |

This gives you most of the basic application interface.

## 2. CRM Dashboard — Customer and business operations

[CRM Dashboard – Preline](https://www.preline.co/templates/dashboards/crm-dashboard/details.html?utm_source=chatgpt.com)

**10 ready-made pages.**

Includes:

* CRM overview.
* Customers.
* Customer details.
* Customer activity.
* Bookings.
* Projects.
* Invoices.
* Tasks.
* Search.

**BMOS functionality it supports:**

* Customer relationship management.
* Client onboarding.
* Customer records.
* Customer activity tracking.
* Sales pipeline.
* Client projects.
* Customer invoices.
* Business development.

This is particularly important because BMOS isn't just an internal administration tool. It needs to support the actual operational activities of small businesses.

## 3. Work Management — Employee and operational management

[Work Management – Preline](https://preline.co/templates/productivity/work-management/details.html?utm_source=chatgpt.com)

**6 ready-made pages.**

Includes:

* Overview.
* Projects.
* Talent management.
* Talent details.
* Empty states.

**BMOS functionality it supports:**

* Workforce management.
* Employee directory.
* Resource allocation.
* Project assignments.
* Operational planning.
* Team capacity.

This would be useful for both the customer-facing business platform and your internal NexGen CLD workforce.

## 4. Help Desk — Customer support

[Help Desk – Preline](https://www.preline.co/templates/support/helpdesk/details.html?utm_source=chatgpt.com)

**5 ready-made pages.**

Includes:

* Support workspace.
* Visitors.
* Tickets.
* Activity.
* Reports.

**BMOS functionality it supports:**

* Customer support.
* Support tickets.
* Incident management.
* Service requests.
* Customer communications.
* Support reporting.

This could become the customer support module within BMOS.

## 5. AI Chat Interface — AI workforce interface

[AI Chat Interface – Preline](https://www.preline.co/templates/ai/ai-chat-interface/details.html?utm_source=chatgpt.com)

**4 ready-made pages.**

Includes:

* AI prompt interface.
* Explore.
* Chat details.
* Studio workspace.
* Conversation history.
* Search.
* Assistant interface.

**BMOS functionality it supports:**

* AI assistant.
* AI business advisor.
* Knowledge assistant.
* Coding agent interface.
* Agent conversations.
* AI-powered business operations.

I would use this as the visual foundation for your Lemonade-powered AI interface.

Importantly, the interface would communicate with your PHP application, not directly with the AI infrastructure.

## 6. Analytics Dashboard — Business intelligence

[Analytics Dashboard – Preline](https://www.preline.co/templates/dashboards/analytics-dashboard/details.html?utm_source=chatgpt.com)

**5 ready-made pages.**

Includes:

* Analytics overview.
* Visitors.
* Surveys.
* Incidents.
* Reporting.
* Charts and KPIs.

**BMOS functionality it supports:**

* Business performance.
* Operational reporting.
* Revenue metrics.
* Workflow performance.
* Productivity metrics.
* System monitoring.

---

# 2. My recommended BMOS template combination

Rather than treating these as six separate applications, I would combine their interfaces into one unified BMOS application.

| Preline template    | BMOS module              | Priority  |
| ------------------- | ------------------------ | --------- |
| Admin Dashboard     | Main application shell   | Essential |
| CRM Dashboard       | Customers and clients    | Essential |
| Work Management     | Projects and workforce   | Essential |
| Help Desk           | Support and incidents    | High      |
| AI Chat Interface   | AI assistants and agents | Essential |
| Analytics Dashboard | Business intelligence    | High      |

There is also another template worth considering:

**[Shared Inbox – Preline](https://preline.co/templates/support/shared-inbox/details.html?utm_source=chatgpt.com)**

This would be useful for unified customer communications and team collaboration.

# 3. How I would organize the BMOS interface

I would build one application with a persistent sidebar and modular workspaces.

Something like this:

```text
BMOS
│
├── COMMAND CENTER
│   ├── Overview
│   ├── Business Health
│   ├── Notifications
│   └── Activity Feed
│
├── BUSINESS
│   ├── Organizations
│   ├── Customers
│   ├── Contacts
│   ├── Projects
│   ├── Services
│   └── Invoices
│
├── OPERATIONS
│   ├── Tasks
│   ├── Workflows
│   ├── Projects
│   ├── Calendar
│   ├── Documents
│   └── Knowledge Base
│
├── WORKFORCE
│   ├── Employees
│   ├── Teams
│   ├── Assignments
│   └── Performance
│
├── AI WORKFORCE
│   ├── AI Command Center
│   ├── Assistants
│   ├── Agents
│   ├── Coding Agent
│   ├── Agent Tasks
│   ├── Executions
│   └── Approvals
│
├── AUTOMATION
│   ├── Workflow Builder
│   ├── Integrations
│   ├── Triggers
│   └── Execution History
│
├── SUPPORT
│   ├── Support Tickets
│   ├── Conversations
│   └── Incidents
│
├── ANALYTICS
│   ├── Business Reports
│   ├── Revenue
│   ├── Productivity
│   └── System Metrics
│
└── ADMINISTRATION
    ├── Users
    ├── Organizations
    ├── Roles & Permissions
    ├── Security
    ├── Billing
    └── System Configuration
```

This is a proposed BMOS interface structure, not a claim that all these modules are already implemented in BMOS 3.2.

# 4. Important: How I would implement Preline with your architecture

There is one architectural consideration I would not compromise on.

**Preline should be the presentation layer, not the application architecture.**

Your existing architecture specifies PHP 8.4 as the application engine, PostgreSQL 17 as the authoritative relational database, and separate services for orchestration, storage and AI.

I would implement it like this:

| Layer               | Technology       |
| ------------------- | ---------------- |
| UI                  | Preline UI       |
| Styling             | Tailwind CSS     |
| Interactivity       | HTMX + Alpine.js |
| Application         | PHP 8.4          |
| Database            | PostgreSQL 17    |
| Background jobs     | Redis + workers  |
| Workflow automation | n8n              |
| Vector search       | Qdrant           |
| File storage        | MinIO            |
| AI inference        | Lemonade         |
| AI workforce        | Paperclip        |
| Reverse proxy       | Traefik          |

Preline templates are delivered as HTML, Tailwind CSS and JavaScript, so they can be adapted to your PHP-native server-rendered architecture.

For example:

```text
Preline UI
    │
    ▼
Nginx
    │
    ▼
PHP 8.4
    │
    ├── Authentication
    ├── Tenant Context
    ├── Business Logic
    ├── Task Management
    ├── Agent Management
    └── API Controllers
           │
           ▼
      PostgreSQL
           │
           ├── Redis
           ├── Qdrant
           ├── MinIO
           ├── n8n
           ├── Paperclip
           └── Lemonade
```

Paperclip should not independently own BMOS business records. PHP remains authoritative for business state.

# 5. Which Preline package should you get?

Current advertised pricing (as of the analysis date — verify before purchasing):

| Package     |  Price | Relevant access                                  |
| ----------- | -----: | ------------------------------------------------ |
| Open Source |   Free | Free components, blocks and 5 template downloads |
| Pro         |   $249 | Full template collection                         |
| Team        |   $459 | Full collection, up to 15 developers             |
| Enterprise  | Custom | Custom licensing                                 |

Source: [Preline pricing and templates](https://www.preline.co/templates/?utm_source=chatgpt.com)

For your project, **Pro would be sufficient** if you're the only developer.

You don't need Team unless additional developers join.

---

# My final recommendation

I would purchase Preline Pro and use the **Admin Dashboard as the foundation for BMOS**, incorporating the CRM, Work Management, Help Desk, AI Chat and Analytics templates as additional modules.

I would not build six independent applications.

I would build one cohesive BMOS interface, with a consistent navigation system, design language, authentication system and tenant-aware PHP backend.

That gives you a professional starting point without compromising the architecture you've spent so much time developing.

**One particularly important next step:** establish a BMOS master UI specification mapping every Preline page to its corresponding PHP controller, route, database entity, permissions and API endpoints before integrating the templates.
