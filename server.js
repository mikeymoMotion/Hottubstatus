// Tiny zero-dependency server for the hot tub status page.
//   GET  /            -> the page
//   GET  /api/status  -> { ready: boolean, updatedAt: string }
//   POST /api/status  -> set status (requires admin password)
//   POST /api/login   -> check admin password

const http = require("http");
const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

const PORT = process.env.PORT || 3000;
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD;
const DATA_FILE = process.env.DATA_FILE || path.join(__dirname, "data", "status.json");
const INDEX_HTML = fs.readFileSync(path.join(__dirname, "public", "index.html"));

if (!ADMIN_PASSWORD) {
  console.warn("WARNING: ADMIN_PASSWORD is not set. The status cannot be changed until you set it.");
}

function loadStatus() {
  try {
    return JSON.parse(fs.readFileSync(DATA_FILE, "utf8"));
  } catch {
    return { ready: false, updatedAt: new Date().toISOString() };
  }
}

function saveStatus(status) {
  fs.mkdirSync(path.dirname(DATA_FILE), { recursive: true });
  fs.writeFileSync(DATA_FILE, JSON.stringify(status));
}

let status = loadStatus();

function isAdmin(req) {
  if (!ADMIN_PASSWORD) return false;
  const given = (req.headers.authorization || "").replace(/^Bearer /, "");
  const a = crypto.createHash("sha256").update(given).digest();
  const b = crypto.createHash("sha256").update(ADMIN_PASSWORD).digest();
  return crypto.timingSafeEqual(a, b);
}

function sendJson(res, code, body) {
  res.writeHead(code, { "Content-Type": "application/json", "Cache-Control": "no-store" });
  res.end(JSON.stringify(body));
}

function readBody(req) {
  return new Promise((resolve) => {
    let data = "";
    req.on("data", (chunk) => {
      data += chunk;
      if (data.length > 1e4) req.destroy();
    });
    req.on("end", () => {
      try {
        resolve(JSON.parse(data || "{}"));
      } catch {
        resolve({});
      }
    });
  });
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, "http://localhost");

  if (url.pathname === "/" && req.method === "GET") {
    res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
    return res.end(INDEX_HTML);
  }

  if (url.pathname === "/api/status" && req.method === "GET") {
    return sendJson(res, 200, status);
  }

  if (url.pathname === "/api/status" && req.method === "POST") {
    if (!isAdmin(req)) return sendJson(res, 401, { error: "unauthorized" });
    const body = await readBody(req);
    status = { ready: Boolean(body.ready), updatedAt: new Date().toISOString() };
    saveStatus(status);
    return sendJson(res, 200, status);
  }

  if (url.pathname === "/api/login" && req.method === "POST") {
    return isAdmin(req) ? sendJson(res, 200, { ok: true }) : sendJson(res, 401, { error: "unauthorized" });
  }

  res.writeHead(404, { "Content-Type": "text/plain" });
  res.end("Not found");
});

server.listen(PORT, () => {
  console.log(`Hot tub status running on http://localhost:${PORT}`);
});
