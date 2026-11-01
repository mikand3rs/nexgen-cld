<?php
// BMOS 3.2 — Tasks UI content.
// HTMX-driven: list loads via /tasks/list, create via form, status toggles.
?>
<div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
  <h2 class="font-semibold mb-3">New task</h2>
  <form hx-post="/tasks/create" hx-target="#task-list" hx-swap="innerHTML"
        hx-on::after-request="this.reset()" class="flex gap-2">
    <input name="title" type="text" required maxlength="200" placeholder="Task title…"
           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm">
    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
      Add
    </button>
  </form>
</div>

<div class="bg-white rounded-xl border border-gray-200 p-5">
  <div class="flex items-center justify-between mb-3">
    <h2 class="font-semibold">Tasks</h2>
    <div class="flex gap-2 text-sm" x-data="{ filter: 'all' }">
      <button @click="filter = 'all'; $dispatch('filter', 'all')"
              class="px-2 py-1 rounded" :class="filter === 'all' ? 'bg-gray-200' : ''">All</button>
      <button @click="filter = 'open'; $dispatch('filter', 'open')"
              class="px-2 py-1 rounded" :class="filter === 'open' ? 'bg-gray-200' : ''">Open</button>
      <button @click="filter = 'done'; $dispatch('filter', 'done')"
              class="px-2 py-1 rounded" :class="filter === 'done' ? 'bg-gray-200' : ''">Done</button>
    </div>
  </div>
  <div id="task-list" hx-get="/tasks/list" hx-trigger="load" hx-swap="innerHTML">
    <div class="text-sm text-gray-500">Loading…</div>
  </div>
</div>
