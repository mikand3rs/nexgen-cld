<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — NexGen CLD</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>body { font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", Inter, sans-serif; } input { color-scheme: dark; }</style>
</head>
<body class="bg-[#08080c] min-h-screen flex items-center justify-center text-zinc-100" style="background: radial-gradient(700px 350px at 50% 0%, rgba(37,64,235,.12), transparent 70%), #08080c;">
<div class="bg-[#121217] border border-zinc-800 rounded-2xl p-8 w-full max-w-sm shadow-2xl">
  <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-blue-500 to-violet-600 flex items-center justify-center text-lg font-bold text-white mb-5">N</div>
  <h1 class="text-[20px] font-semibold mb-1">NexGen CLD</h1>
  <p class="text-[13px] text-zinc-500 mb-6">Sign in to your workspace</p>
  <form id="login-form" class="space-y-4">
    <div>
      <label class="block text-[13px] font-medium mb-1.5 text-zinc-300" for="email">Email</label>
      <input type="email" id="email" name="email" required autocomplete="email"
             class="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2.5 text-[14px] text-zinc-100 placeholder-zinc-600 outline-none focus:border-zinc-600">
    </div>
    <div>
      <label class="block text-[13px] font-medium mb-1.5 text-zinc-300" for="password">Password</label>
      <input type="password" id="password" name="password" required autocomplete="current-password"
             class="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2.5 text-[14px] text-zinc-100 placeholder-zinc-600 outline-none focus:border-zinc-600">
    </div>
    <div id="login-error" class="text-[13px] text-red-400 hidden"></div>
    <button type="submit"
            class="w-full bg-blue-600 hover:bg-blue-500 text-white rounded-xl py-2.5 text-[14px] font-medium transition">
      Sign in
    </button>
  </form>
</div>
<script>
document.getElementById('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const err = document.getElementById('login-error');
  err.classList.add('hidden');
  const res = await fetch('/auth/login', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      email: document.getElementById('email').value,
      password: document.getElementById('password').value,
    }),
  });
  if (res.ok) {
    window.location.href = '/dashboard';
  } else {
    const d = await res.json().catch(() => ({}));
    err.textContent = d.error || 'Sign in failed';
    err.classList.remove('hidden');
  }
});
</script>
</body>
</html>
