"""A stand-in for Tracearr, written from its source (apps/server/src/routes):

* GET /api/v2/public/users, /users/:id/stats, /users/:id/history need
  "Authorization: Bearer trr_pub_..." and answer as publicV2 does.
* GET /api/v1/images/proxy needs no authentication (routes/images.ts).

Endpoints under /_test/ are not part of Tracearr; they let the tests set the
data and read what was asked.
"""

import json
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse


def fresh_state() -> dict:
    return {"key": "trr_pub_test", "identities": [], "stats": {}, "history": {}, "requests": []}


STATE = fresh_state()

EMPTY_WINDOWS = {"all_time": {"plays": 0, "watch_time_ms": 0}, "last_30": {"plays": 0, "watch_time_ms": 0}, "last_7": {"plays": 0, "watch_time_ms": 0}}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def send_json(self, status: int, body) -> None:
        self.send_raw(status, "application/json", json.dumps(body).encode())

    def send_raw(self, status: int, content_type: str, body: bytes) -> None:
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        self.route("GET")

    def do_POST(self):
        self.route("POST")

    def route(self, method: str) -> None:
        global STATE
        url = urlparse(self.path)
        path = url.path.rstrip("/")
        query = {k: v[0] for k, v in parse_qs(url.query).items()}
        length = int(self.headers.get("Content-Length") or 0)
        body = json.loads(self.rfile.read(length) or b"{}") if length else {}

        if path == "/_test/reset":
            STATE = fresh_state()
            return self.send_json(200, {"ok": True})
        if path == "/_test/state":
            return self.send_json(200, STATE)
        if path == "/_test/set":
            STATE.update(body)
            return self.send_json(200, {"ok": True})
        if path == "/_test/clear-requests":
            STATE["requests"] = []
            return self.send_json(200, {"ok": True})

        if path == "/api/v1/images/proxy":
            image = query.get("url", "")
            STATE["requests"].append({"method": method, "path": "image:" + image})
            if "notimage" in image:
                return self.send_raw(200, "text/html", b"<html>login</html>")
            if "svg" in image:
                return self.send_raw(200, "image/svg+xml", b"<svg xmlns='http://www.w3.org/2000/svg'><script>alert(1)</script></svg>")
            return self.send_raw(200, "image/jpeg", b"\xff\xd8\xff\xe0JPEG:" + image.encode())

        prefix = "/api/v2/public"
        if not path.startswith(prefix + "/"):
            return self.send_json(404, {"message": "Not found"})
        path = path[len(prefix):]
        STATE["requests"].append({"method": method, "path": path, "query": query})

        auth = self.headers.get("Authorization") or ""
        if not auth.startswith("Bearer "):
            return self.send_json(401, {"message": "Missing or invalid Authorization header"})
        if auth[7:] != STATE["key"]:
            return self.send_json(401, {"message": "Invalid API key"})

        parts = path.strip("/").split("/")
        page_size = int(query.get("pageSize", 25))
        if method == "GET" and path == "/users":
            start = int(query.get("cursor", "0") or 0)
            page = STATE["identities"][start:start + page_size]
            nxt = str(start + page_size) if start + page_size < len(STATE["identities"]) else None
            return self.send_json(200, {"data": page, "meta": {"nextCursor": nxt, "pageSize": page_size}})
        if method == "GET" and len(parts) == 3 and parts[0] == "users" and parts[2] == "stats":
            stats = STATE["stats"].get(parts[1], {})
            return self.send_json(200, {"user_id": parts[1], "windows": stats.get("windows", EMPTY_WINDOWS), "top_genres": stats.get("top_genres", [])})
        if method == "GET" and len(parts) == 3 and parts[0] == "users" and parts[2] == "history":
            return self.send_json(200, {"data": STATE["history"].get(parts[1], [])[:page_size], "meta": {"nextCursor": None, "pageSize": page_size}})
        return self.send_json(404, {"message": "Not found"})


if __name__ == "__main__":
    ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1] if len(sys.argv) > 1 else 8767)), Handler).serve_forever()
