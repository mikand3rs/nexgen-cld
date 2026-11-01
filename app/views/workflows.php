<?php
// BMOS 3.2 — Workflows UI (n8n integration dashboard).
// n8n runs profile-gated on the backend network; this page shows status,
// the API surface n8n should call, and a connection test.
?>
<div class="grid grid-cols-1 xl:grid-cols-2 gap-4">

  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
    <h2 class="font-semibold mb-3">n8n status</h2>
    <div hx-get="/workflows/status" hx-trigger="load" hx-swap="innerHTML">
      <div class="text-sm text-zinc-500">Loading…</div>
    </div>
  </div>

  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
    <h2 class="font-semibold mb-3">Accessing the n8n editor</h2>
    <div class="text-sm text-zinc-400 space-y-2">
      <p>n8n runs on the backend network only (not published). To open the editor:</p>
      <pre class="bg-zinc-900 border border-zinc-800 rounded-lg p-3 text-xs font-mono overflow-x-auto">docker compose --profile n8n port-forward n8n 5678:5678
# then open http://localhost:5678</pre>
      <p>Or start it with the UI port published (dev only):</p>
      <pre class="bg-zinc-900 border border-zinc-800 rounded-lg p-3 text-xs font-mono overflow-x-auto">docker compose --profile n8n up -d --scale n8n=1</pre>
    </div>
  </div>

  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 xl:col-span-2">
    <h2 class="font-semibold mb-3">BMOS API surface for n8n</h2>
    <p class="text-sm text-zinc-400 mb-3">
      n8n calls BMOS — never the reverse, never the database directly.
      Server-to-server auth uses <span class="font-mono">Authorization: Bearer</span>
      API keys (per-tenant). Browser sessions use cookies.
    </p>
    <div class="overflow-x-auto">
      <table class="text-sm w-full">
        <thead><tr class="text-left text-zinc-500 border-b">
          <th class="py-2 pr-4">Method</th><th class="py-2 pr-4">Endpoint</th><th class="py-2">Use</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
          <tr><td class="py-2 pr-4 font-mono">POST</td><td class="py-2 pr-4 font-mono">/jobs/enqueue</td><td class="py-2">Enqueue a background job (task.created, document.index)</td></tr>
          <tr><td class="py-2 pr-4 font-mono">POST</td><td class="py-2 pr-4 font-mono">/objects</td><td class="py-2">Register a file upload (get presigned PUT URL)</td></tr>
          <tr><td class="py-2 pr-4 font-mono">POST</td><td class="py-2 pr-4 font-mono">/ai/chat</td><td class="py-2">Ask the local AI (Lemonade)</td></tr>
          <tr><td class="py-2 pr-4 font-mono">POST</td><td class="py-2 pr-4 font-mono">/search</td><td class="py-2">Vector + keyword document search</td></tr>
          <tr><td class="py-2 pr-4 font-mono">GET</td><td class="py-2 pr-4 font-mono">/ready</td><td class="py-2">Health check (DB as bmos_app)</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 xl:col-span-2">
    <h2 class="font-semibold mb-3">Test: n8n → BMOS round-trip</h2>
    <p class="text-sm text-zinc-400 mb-3">
      Enqueues a job through the BMOS API the way an n8n workflow would.
    </p>
    <button hx-post="/workflows/test-job" hx-target="#wf-test-result" hx-swap="innerHTML"
            class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
      Enqueue test job
    </button>
    <div id="wf-test-result" class="mt-3 text-sm"></div>
  </div>
</div>
