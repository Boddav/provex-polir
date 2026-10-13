const http = require("node:http");
const fs = require("node:fs");
const path = require("node:path");
const { spawn } = require("node:child_process");

const root = path.join(__dirname, "public");
const host = process.env.HOST || "0.0.0.0";
const port = Number(process.env.PORT || 80);
const phpCgi = process.env.PHP_CGI || "C:\\tools\\php83\\php-cgi.exe";
const phpIni = path.join(__dirname, "php.ini");

// Only these PHP scripts are ever executed; every other .php request is a 404.
const phpScripts = new Set(["admin.php"]);
const maxBodyBytes = 512 * 1024;
const phpTimeoutMs = 20_000;

const mimeTypes = {
  ".css": "text/css; charset=utf-8",
  ".html": "text/html; charset=utf-8",
  ".ico": "image/x-icon",
  ".jpeg": "image/jpeg",
  ".jpg": "image/jpeg",
  ".js": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".txt": "text/plain; charset=utf-8",
  ".webp": "image/webp",
  ".xml": "application/xml; charset=utf-8"
};

const allowedExtensions = new Set(Object.keys(mimeTypes));
const requestBuckets = new Map();
const postBuckets = new Map();
const rateLimitWindowMs = 60_000;
const rateLimitMaxRequests = 180;
const rateLimitMaxPosts = 20;

// The PROVEX pages use inline <style>/<script> and Google Fonts.
const securityHeaders = {
  "Content-Security-Policy": "default-src 'self'; base-uri 'self'; connect-src 'self'; font-src 'self' https://fonts.gstatic.com; form-action 'self'; frame-ancestors 'none'; img-src 'self' data:; object-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
  "Cross-Origin-Opener-Policy": "same-origin",
  "Cross-Origin-Resource-Policy": "same-origin",
  "Permissions-Policy": "camera=(), geolocation=(), microphone=(), payment=(), usb=()",
  "Referrer-Policy": "strict-origin-when-cross-origin",
  "X-Content-Type-Options": "nosniff",
  "X-Frame-Options": "DENY"
};

function applySecurityHeaders(response) {
  for (const [name, value] of Object.entries(securityHeaders)) {
    response.setHeader(name, value);
  }
}

function isRateLimited(buckets, address, max) {
  const now = Date.now();
  const bucket = buckets.get(address);

  if (!bucket || now - bucket.startedAt >= rateLimitWindowMs) {
    buckets.set(address, { count: 1, startedAt: now });
    return false;
  }

  bucket.count += 1;
  return bucket.count > max;
}

setInterval(() => {
  const cutoff = Date.now() - rateLimitWindowMs;
  for (const buckets of [requestBuckets, postBuckets]) {
    for (const [address, bucket] of buckets) {
      if (bucket.startedAt < cutoff) buckets.delete(address);
    }
  }
}, rateLimitWindowMs).unref();

function sendError(response, statusCode, message) {
  if (response.headersSent) {
    response.destroy();
    return;
  }
  applySecurityHeaders(response);
  response.writeHead(statusCode, { "Content-Type": "text/plain; charset=utf-8" });
  response.end(message);
}

function runPhp(request, response, url, scriptName, body) {
  const env = {
    SystemRoot: process.env.SystemRoot || "C:\\Windows",
    TEMP: process.env.TEMP || "C:\\Windows\\Temp",
    TMP: process.env.TMP || "C:\\Windows\\Temp",
    PATH: process.env.PATH || "",
    REDIRECT_STATUS: "200",
    GATEWAY_INTERFACE: "CGI/1.1",
    SERVER_SOFTWARE: "provex-node",
    SERVER_PROTOCOL: "HTTP/1.1",
    SERVER_NAME: (request.headers.host || "localhost").split(":")[0],
    SERVER_PORT: String(port),
    REQUEST_METHOD: request.method,
    REQUEST_URI: request.url,
    SCRIPT_NAME: `/${scriptName}`,
    SCRIPT_FILENAME: path.join(root, scriptName),
    DOCUMENT_ROOT: root,
    QUERY_STRING: url.search.replace(/^\?/, ""),
    REMOTE_ADDR: request.socket.remoteAddress || "",
    CONTENT_TYPE: request.headers["content-type"] || "",
    CONTENT_LENGTH: String(body.length)
  };
  for (const [name, value] of Object.entries(request.headers)) {
    if (name === "proxy") continue;
    env[`HTTP_${name.toUpperCase().replace(/-/g, "_")}`] = Array.isArray(value) ? value.join(", ") : value;
  }

  const child = spawn(phpCgi, ["-c", phpIni], { env, windowsHide: true });
  const chunks = [];
  let errorText = "";
  const timer = setTimeout(() => child.kill(), phpTimeoutMs);

  child.stdout.on("data", (chunk) => chunks.push(chunk));
  child.stderr.on("data", (chunk) => { errorText += chunk; });
  child.on("error", (error) => {
    clearTimeout(timer);
    console.error(`php-cgi failed to start: ${error.message}`);
    sendError(response, 502, "Bad Gateway");
  });
  child.on("close", () => {
    clearTimeout(timer);
    if (errorText) console.error(`php-cgi stderr: ${errorText.trim()}`);
    if (response.headersSent || response.destroyed) return;

    const output = Buffer.concat(chunks);
    let split = output.indexOf("\r\n\r\n");
    let gap = 4;
    if (split === -1) {
      split = output.indexOf("\n\n");
      gap = 2;
    }
    if (split === -1) {
      sendError(response, 502, "Bad Gateway");
      return;
    }

    let statusCode = 200;
    const cookies = [];
    for (const line of output.subarray(0, split).toString("latin1").split(/\r?\n/)) {
      const colon = line.indexOf(":");
      if (colon === -1) continue;
      const name = line.slice(0, colon).trim();
      const value = line.slice(colon + 1).trim();
      const lower = name.toLowerCase();
      if (lower === "status") {
        statusCode = Number.parseInt(value, 10) || 200;
      } else if (lower === "set-cookie") {
        cookies.push(value);
      } else if (lower === "location") {
        if (statusCode === 200) statusCode = 302;
        response.setHeader(name, value);
      } else {
        response.setHeader(name, value);
      }
    }
    if (cookies.length) response.setHeader("Set-Cookie", cookies);

    const payload = output.subarray(split + gap);
    response.setHeader("Content-Length", payload.length);
    response.setHeader("Cache-Control", "no-store");
    response.writeHead(statusCode);
    response.end(request.method === "HEAD" ? undefined : payload);
  });

  child.stdin.on("error", () => {});
  child.stdin.end(body);
}

function handlePhp(request, response, url, scriptName) {
  const address = request.socket.remoteAddress || "unknown";

  if (request.method === "GET" || request.method === "HEAD") {
    runPhp(request, response, url, scriptName, Buffer.alloc(0));
    return;
  }

  if (isRateLimited(postBuckets, address, rateLimitMaxPosts)) {
    response.setHeader("Retry-After", "60");
    sendError(response, 429, "Too Many Requests");
    return;
  }

  const chunks = [];
  let received = 0;
  let tooLarge = false;
  request.on("data", (chunk) => {
    received += chunk.length;
    if (received > maxBodyBytes) {
      tooLarge = true;
      return;
    }
    chunks.push(chunk);
  });
  request.on("end", () => {
    if (tooLarge) {
      sendError(response, 413, "Payload Too Large");
      return;
    }
    runPhp(request, response, url, scriptName, Buffer.concat(chunks));
  });
  request.on("error", () => response.destroy());
}

const server = http.createServer((request, response) => {
  applySecurityHeaders(response);
  response.on("finish", () => {
    console.log(`${new Date().toISOString()} ${request.socket.remoteAddress} ${request.method} ${request.url} ${response.statusCode}`);
  });

  if (isRateLimited(requestBuckets, request.socket.remoteAddress || "unknown", rateLimitMaxRequests)) {
    response.setHeader("Retry-After", "60");
    sendError(response, 429, "Too Many Requests");
    return;
  }

  let url;
  let pathname;
  try {
    url = new URL(request.url, "http://localhost");
    pathname = decodeURIComponent(url.pathname);
  } catch {
    sendError(response, 400, "Bad Request");
    return;
  }

  const relativePath = pathname === "/" ? "index.html" : pathname.replace(/^\/+/, "");

  if (phpScripts.has(relativePath)) {
    if (!["GET", "HEAD", "POST"].includes(request.method)) {
      response.setHeader("Allow", "GET, HEAD, POST");
      sendError(response, 405, "Method Not Allowed");
      return;
    }
    handlePhp(request, response, url, relativePath);
    return;
  }

  if (request.method !== "GET" && request.method !== "HEAD") {
    response.setHeader("Allow", "GET, HEAD");
    sendError(response, 405, "Method Not Allowed");
    return;
  }

  const filePath = path.resolve(root, relativePath);
  const rootPrefix = `${path.resolve(root)}${path.sep}`;
  const extension = path.extname(filePath).toLowerCase();

  if (relativePath.split(/[\\/]/).some((segment) => segment.startsWith(".")) ||
      !allowedExtensions.has(extension)) {
    sendError(response, 404, "Not Found");
    return;
  }

  if (!filePath.startsWith(rootPrefix)) {
    sendError(response, 403, "Forbidden");
    return;
  }

  fs.stat(filePath, (statError, stats) => {
    if (statError || !stats.isFile()) {
      sendError(response, 404, "Not Found");
      return;
    }

    response.writeHead(200, {
      "Content-Type": mimeTypes[extension] || "application/octet-stream",
      "Content-Length": stats.size,
      // content.json is edited from the admin, so it must never be served stale.
      "Cache-Control": extension === ".html" || extension === ".json"
        ? "no-cache"
        : "public, max-age=3600"
    });

    if (request.method === "HEAD") {
      response.end();
      return;
    }

    const stream = fs.createReadStream(filePath);
    stream.on("error", () => response.destroy());
    stream.pipe(response);
  });
});

server.headersTimeout = 10_000;
server.requestTimeout = 30_000;
server.keepAliveTimeout = 5_000;
server.maxHeadersCount = 50;

server.listen(port, host, () => {
  console.log(`PROVEX listening on http://${host}:${port}`);
  console.log(`Serving ${root}`);
});

// No certificate yet: refuse HTTPS attempts at once so browsers that try
// https:// first fall back to http:// instead of waiting for a timeout.
const httpsRefuser = require("node:net").createServer((socket) => {
  console.log(`${new Date().toISOString()} ${socket.remoteAddress} HTTPS attempt on 443 refused`);
  socket.destroy();
});
httpsRefuser.on("error", (error) => console.error(`443 listener: ${error.message}`));
if (port === 80) httpsRefuser.listen(443, host);

function shutdown(signal) {
  console.log(`${signal} received, shutting down`);
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(1), 10000).unref();
}

process.on("SIGINT", () => shutdown("SIGINT"));
process.on("SIGTERM", () => shutdown("SIGTERM"));
