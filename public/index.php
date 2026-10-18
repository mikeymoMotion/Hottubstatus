<?php
// ===== Put your admin password on the line below (no quotes needed) =====
$ADMIN_PASSWORD = <<<'PASSWORD'
change-me
PASSWORD;
// =========================================================================
$ADMIN_PASSWORD = trim($ADMIN_PASSWORD);

// The rest of this file is the page itself. Requests to ?api are the status API:
//   GET  ?api                                  -> { ready, updatedAt }
//   POST ?api  { password, action: "login" }   -> check admin password
//   POST ?api  { password, ready: true|false } -> set status

$dataFile = __DIR__ . '/status.json';

if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    function respond($code, $body) {
        http_response_code($code);
        echo json_encode($body);
        exit;
    }

    function loadStatus($dataFile) {
        $status = @json_decode(@file_get_contents($dataFile), true);
        return is_array($status) ? $status : ['ready' => false, 'updatedAt' => gmdate('c')];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(200, loadStatus($dataFile));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['error' => 'method not allowed']);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $given = (string)($body['password'] ?? '');

    if ($ADMIN_PASSWORD === '' || $ADMIN_PASSWORD === 'change-me' || !hash_equals($ADMIN_PASSWORD, $given)) {
        respond(401, ['error' => 'unauthorized']);
    }

    if (($body['action'] ?? '') === 'login') {
        respond(200, ['ok' => true]);
    }

    $status = ['ready' => !empty($body['ready']), 'updatedAt' => gmdate('c')];
    if (file_put_contents($dataFile, json_encode($status), LOCK_EX) === false) {
        respond(500, ['error' => 'could not save status']);
    }
    respond(200, $status);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Hot Tub Status</title>
  <style>
    * { box-sizing: border-box; margin: 0; }

    html, body { height: 100%; }

    body {
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      color: #fff;
      background: #1b2a4a;
      overflow: hidden;
    }

    /* Two stacked gradient layers so we can crossfade between them. */
    .bg {
      position: fixed;
      inset: 0;
      transition: opacity 1.2s ease;
    }
    .bg-warm { background: linear-gradient(160deg, #ffb347 0%, #ff7b54 45%, #d7385e 100%); opacity: 0; }
    .bg-cool { background: linear-gradient(160deg, #3a7bd5 0%, #1f4e8c 50%, #0f2027 100%); }
    body.ready .bg-warm { opacity: 1; }
    body.ready .bg-cool { opacity: 0; }

    /* Steam */
    .steam {
      position: fixed;
      inset: 0;
      pointer-events: none;
      opacity: 0;
      transition: opacity 1.5s ease;
    }
    body.ready .steam { opacity: 1; }

    /* Each wisp is a thin, soft streak that sways as it rises, then spreads out and fades. */
    .wisp {
      position: absolute;
      bottom: -30vh;
      width: 5vmax;
      height: 30vmax;
      border-radius: 50%;
      background: radial-gradient(ellipse at center, rgba(255,255,255,0.5) 0%, rgba(255,255,255,0.15) 45%, rgba(255,255,255,0) 70%);
      filter: blur(10px);
      opacity: 0;
      animation: rise-a 6s ease-in infinite;
    }
    .wisp:nth-child(even) { animation-name: rise-b; }
    .wisp:nth-child(1)  { left: 4%;  animation-duration: 6s;   animation-delay: 0s; }
    .wisp:nth-child(2)  { left: 12%; animation-duration: 5.2s; animation-delay: -2.1s; }
    .wisp:nth-child(3)  { left: 21%; animation-duration: 6.6s; animation-delay: -4s; }
    .wisp:nth-child(4)  { left: 29%; animation-duration: 5.5s; animation-delay: -1s; }
    .wisp:nth-child(5)  { left: 38%; animation-duration: 6.2s; animation-delay: -3.3s; }
    .wisp:nth-child(6)  { left: 46%; animation-duration: 5s;   animation-delay: -0.5s; }
    .wisp:nth-child(7)  { left: 55%; animation-duration: 6.8s; animation-delay: -5.2s; }
    .wisp:nth-child(8)  { left: 63%; animation-duration: 5.4s; animation-delay: -2.7s; }
    .wisp:nth-child(9)  { left: 72%; animation-duration: 6.1s; animation-delay: -1.6s; }
    .wisp:nth-child(10) { left: 80%; animation-duration: 5.7s; animation-delay: -4.4s; }
    .wisp:nth-child(11) { left: 88%; animation-duration: 6.4s; animation-delay: -0.9s; }
    .wisp:nth-child(12) { left: 95%; animation-duration: 5.3s; animation-delay: -3.7s; }

    @keyframes rise-a {
      0%   { transform: translate(0, 0) rotate(0deg) scale(0.5, 0.7); opacity: 0; }
      15%  { opacity: 0.6; }
      35%  { transform: translate(2.5vw, -35vh) rotate(6deg) scale(0.8, 1); }
      60%  { transform: translate(-1.5vw, -65vh) rotate(-5deg) scale(1.3, 1.1); opacity: 0.35; }
      100% { transform: translate(3vw, -105vh) rotate(8deg) scale(2.2, 1.2); opacity: 0; }
    }
    @keyframes rise-b {
      0%   { transform: translate(0, 0) rotate(0deg) scale(0.5, 0.7); opacity: 0; }
      15%  { opacity: 0.55; }
      35%  { transform: translate(-2.5vw, -35vh) rotate(-7deg) scale(0.8, 1); }
      60%  { transform: translate(2vw, -65vh) rotate(5deg) scale(1.3, 1.1); opacity: 0.3; }
      100% { transform: translate(-3vw, -105vh) rotate(-8deg) scale(2.2, 1.2); opacity: 0; }
    }

    @media (prefers-reduced-motion: reduce) {
      .wisp { animation: none; }
      .steam { display: none; }
    }

    /* Status */
    main {
      position: relative;
      height: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 16px;
    }
    .label {
      font-size: clamp(1rem, 3vw, 1.6rem);
      font-weight: 600;
      letter-spacing: 0.3em;
      text-transform: uppercase;
      opacity: 0.8;
      margin-bottom: 0.5em;
    }
    #status {
      font-size: clamp(1.75rem, 9vw, 6.5rem);
      font-weight: 900;
      line-height: 0.95;
      letter-spacing: -0.02em;
      text-transform: uppercase;
      text-shadow: 0 6px 30px rgba(0,0,0,0.25);
    }

    /* Admin controls: a faint lock in the corner, and a switch once unlocked. */
    .admin {
      position: fixed;
      right: 16px;
      bottom: 16px;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .lock {
      background: none;
      border: none;
      color: #fff;
      font-size: 1.2rem;
      opacity: 0.25;
      cursor: pointer;
      padding: 8px;
    }
    .lock:hover { opacity: 0.7; }

    .login { display: none; gap: 8px; }
    .login.open { display: flex; }
    .login input, .login button {
      font: inherit;
      font-size: 0.9rem;
      padding: 8px 12px;
      border-radius: 8px;
      border: 1px solid rgba(255,255,255,0.4);
      background: rgba(0,0,0,0.25);
      color: #fff;
    }
    .login button { cursor: pointer; }

    .switch { display: none; align-items: center; gap: 10px; font-size: 0.9rem; font-weight: 600; cursor: pointer; }
    body.admin-on .switch { display: flex; }
    body.admin-on .lock { display: none; }
    .switch input { display: none; }
    .track {
      width: 60px;
      height: 32px;
      border-radius: 16px;
      background: rgba(0,0,0,0.35);
      border: 1px solid rgba(255,255,255,0.4);
      position: relative;
      transition: background 0.3s;
    }
    .track::after {
      content: "";
      position: absolute;
      top: 3px;
      left: 3px;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      background: #fff;
      transition: transform 0.3s;
    }
    .switch input:checked + .track { background: rgba(255,255,255,0.35); }
    .switch input:checked + .track::after { transform: translateX(28px); }
  </style>
</head>
<body>
  <div class="bg bg-cool"></div>
  <div class="bg bg-warm"></div>
  <div class="steam" aria-hidden="true">
    <div class="wisp"></div><div class="wisp"></div><div class="wisp"></div>
    <div class="wisp"></div><div class="wisp"></div><div class="wisp"></div>
    <div class="wisp"></div><div class="wisp"></div><div class="wisp"></div>
    <div class="wisp"></div><div class="wisp"></div><div class="wisp"></div>
  </div>

  <main>
    <div class="label">Hot Tub</div>
    <h1 id="status">&nbsp;</h1>
  </main>

  <div class="admin">
    <form class="login" id="login">
      <input type="password" id="password" placeholder="Admin password" autocomplete="current-password">
      <button type="submit">Unlock</button>
    </form>
    <label class="switch">
      <span>Ready</span>
      <input type="checkbox" id="toggle">
      <span class="track"></span>
    </label>
    <button class="lock" id="lock" title="Admin" aria-label="Admin">&#128274;</button>
  </div>

  <script>
    const statusEl = document.getElementById("status");
    const toggle = document.getElementById("toggle");
    const loginForm = document.getElementById("login");
    const passwordInput = document.getElementById("password");
    const KEY = "hottub-admin-password";

    function getPassword() {
      try { return localStorage.getItem(KEY); } catch { return null; }
    }
    function setPassword(pw) {
      try { pw ? localStorage.setItem(KEY, pw) : localStorage.removeItem(KEY); } catch {}
    }

    function render(ready) {
      statusEl.textContent = ready ? "Ready" : "Not Ready";
      document.body.classList.toggle("ready", ready);
      toggle.checked = ready;
    }

    async function refresh() {
      try {
        const res = await fetch("?api", { cache: "no-store" });
        render((await res.json()).ready);
      } catch {}
    }

    async function login(pw) {
      const res = await fetch("?api", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "login", password: pw }),
      });
      if (!res.ok) return false;
      setPassword(pw);
      document.body.classList.add("admin-on");
      loginForm.classList.remove("open");
      return true;
    }

    document.getElementById("lock").addEventListener("click", () => {
      loginForm.classList.toggle("open");
      passwordInput.focus();
    });

    loginForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (!(await login(passwordInput.value))) {
        passwordInput.value = "";
        passwordInput.placeholder = "Wrong password";
      }
    });

    toggle.addEventListener("change", async () => {
      const ready = toggle.checked;
      render(ready);
      const res = await fetch("?api", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ready, password: getPassword() }),
      });
      if (res.status === 401) {
        setPassword(null);
        document.body.classList.remove("admin-on");
      }
      refresh();
    });

    const saved = getPassword();
    if (saved) login(saved);

    refresh();
    setInterval(refresh, 15000);
  </script>
</body>
</html>
