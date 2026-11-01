<?php
// BMOS 3.2 — AI Chat UI. Alpine.js client state, fetch() to /ai/chat.
// The tenant UUID comes from the header input (same as other pages).
?>
<div class="bg-white rounded-xl border border-gray-200 flex flex-col" style="height: calc(100vh - 12rem);"
     x-data="{ messages: [], input: '', sending: false }">
  <div class="px-5 py-3 border-b border-gray-200">
    <h2 class="font-semibold">AI Chat</h2>
    <p class="text-xs text-gray-500">Powered by Lemonade (local GPU) via the BMOS API. Tenant-scoped.</p>
  </div>
  <div id="chat-log" class="flex-1 overflow-y-auto p-5 space-y-3">
    <template x-for="(m, i) in messages" :key="i">
      <div :class="m.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
        <div :class="m.role === 'user'
              ? 'bg-blue-600 text-white rounded-2xl rounded-br-sm px-4 py-2 max-w-[80%] text-sm'
              : 'bg-gray-100 text-gray-900 rounded-2xl rounded-bl-sm px-4 py-2 max-w-[80%] text-sm whitespace-pre-wrap'"
             x-text="m.content"></div>
      </div>
    </template>
    <div x-show="messages.length === 0" class="text-sm text-gray-500 text-center py-8">
      Ask something to start the conversation.
    </div>
    <div x-show="sending" class="flex justify-start">
      <div class="bg-gray-100 rounded-2xl px-4 py-2 text-sm text-gray-500">Thinking…</div>
    </div>
  </div>
  <form class="p-4 border-t border-gray-200 flex gap-2"
        @submit.prevent="
          if (!input.trim() || sending) return;
          const tenant = document.getElementById('tenant-input').value;
          const userMsg = input.trim();
          messages.push({ role: 'user', content: userMsg });
          input = ''; sending = true;
          $nextTick(() => { document.getElementById('chat-log').scrollTop = 1e6; });
          const history = messages.filter(m => m.role !== 'system').slice(-20);
          fetch('/ai/chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Tenant-ID': tenant },
            body: JSON.stringify({ messages: history })
          }).then(r => r.json()).then(d => {
            messages.push({ role: 'assistant', content: d.content || ('Error: ' + (d.error || 'unknown')) });
          }).catch(e => {
            messages.push({ role: 'assistant', content: 'Error: ' + e.message });
          }).finally(() => {
            sending = false;
            $nextTick(() => { document.getElementById('chat-log').scrollTop = 1e6; });
          });
        ">
    <input x-model="input" type="text" placeholder="Type a message…"
           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm" :disabled="sending">
    <button type="submit" :disabled="sending"
            class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">
      Send
    </button>
  </form>
</div>
