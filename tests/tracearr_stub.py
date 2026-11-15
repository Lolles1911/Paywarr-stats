"""A stand-in for Tracearr, written from its source (apps/server/src/routes):

* GET /api/v2/public/users, /users/:id/stats, /users/:id/history and
  /watched-media need
  "Authorization: Bearer trr_pub_..." and answer as publicV2 does.
* GET /api/v1/images/proxy needs no authentication (routes/images.ts).

And for Silo, under /silo, written from Silo's API contract (openapi.json and
its fixtures): /api/v2/admin/users/:id/watch-summary, /api/v2/admin/playback-history
(user_id filter, cursor pages of up to 200), /api/v2/profiles and
/api/v2/catalog/items/:id (needs X-Profile-Id of the key owner's profile).
"sa_full" is a full-access key; "sa_monitor" has only admin:monitor:read, so
profiles and the catalog answer 403. Posters: a signed Silo route
(/silo/img/...) or object storage (/s3/...).

Endpoints under /_test/ are not part of Tracearr; they let the tests set the
data and read what was asked.
"""

import json
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse


def fresh_state() -> dict:
    return {"key": "trr_pub_test", "identities": [], "stats": {}, "history": {}, "watched": {}, "requests": [],
            "streams": [], "top_media": {"movie": [], "show": []}, "activity": [],
            "silo": {"summary": {}, "history": {}, "items": {}, "ignore_user_filter": False,
                     "profiles": [{"id": "p-owner", "is_primary": True, "has_pin": False}, {"id": "p-kid", "is_primary": False, "has_pin": False}]}}


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
            STATE["silo"].update(body.pop("silo", {}))
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

        if path.startswith("/silo/img/") or path.startswith("/s3/"):
            STATE["requests"].append({"method": method, "path": "image:" + path, "auth": self.headers.get("Authorization") or ""})
            if "notimage" in path:
                return self.send_raw(200, "text/html", b"<html>login</html>")
            if "svg" in path:
                return self.send_raw(200, "image/svg+xml", b"<svg xmlns='http://www.w3.org/2000/svg'><script>alert(1)</script></svg>")
            return self.send_raw(200, "image/jpeg", b"\xff\xd8\xff\xe0JPEG:" + path.encode())
        if path.startswith("/silo/"):
            return self.silo(method, path[len("/silo"):], query)

        if path == "/api/v1/public/activity":
            STATE["requests"].append({"method": method, "path": "v1:/activity", "query": query})
            if (self.headers.get("Authorization") or "") != "Bearer " + STATE["key"]:
                return self.send_json(401, {"message": "Invalid API key"})
            return self.send_json(200, {"period": query.get("period", "month"), "plays": STATE["activity"], "concurrent": [], "byDayOfWeek": [], "byHourOfDay": [], "platforms": [], "quality": {}})

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
        if method == "GET" and path == "/streams":
            return self.send_json(200, {"data": STATE["streams"], "summary": {"total": len(STATE["streams"])}})
        if method == "GET" and path == "/top-media":
            if query.get("media_type") not in ("movie", "show") or not 1 <= int(query.get("days", 30)) <= 365:
                return self.send_json(400, {"message": "Invalid query parameters"})
            rows = STATE["top_media"].get(query["media_type"], [])[:page_size]
            return self.send_json(200, {"data": rows, "meta": {"nextCursor": None, "pageSize": page_size}})
        if method == "GET" and len(parts) == 3 and parts[0] == "users" and parts[2] == "stats":
            stats = STATE["stats"].get(parts[1], {})
            return self.send_json(200, {"user_id": parts[1], "windows": stats.get("windows", EMPTY_WINDOWS), "top_genres": stats.get("top_genres", [])})
        if method == "GET" and len(parts) == 3 and parts[0] == "users" and parts[2] == "history":
            if page_size > 100:
                return self.send_json(400, {"message": "pageSize must be at most 100"})
            plays = STATE["history"].get(parts[1], [])
            start = int(query.get("cursor", "0") or 0)
            nxt = str(start + page_size) if start + page_size < len(plays) else None
            return self.send_json(200, {"data": plays[start:start + page_size], "meta": {"nextCursor": nxt, "pageSize": page_size}})
        if method == "GET" and path == "/watched-media":
            # Without user_id Tracearr answers for everyone; the stub does too.
            kind = query.get("media_type", "")
            people = [query["user_id"]] if "user_id" in query else list(STATE["watched"].keys())
            rows = [row for person in people for row in STATE["watched"].get(person, {}).get(kind, [])]
            if query.get("min_state") != "partial":
                rows = [row for row in rows if row.get("watched_state") == "watched"]
            return self.send_json(200, {"data": rows[:page_size], "meta": {"nextCursor": None, "pageSize": page_size}})
        return self.send_json(404, {"message": "Not found"})


    def silo(self, method: str, path: str, query: dict) -> None:
        silo = STATE["silo"]
        STATE["requests"].append({"method": method, "path": "silo:" + path, "query": query, "profile": self.headers.get("X-Profile-Id")})
        auth = self.headers.get("Authorization") or ""
        if auth not in ("Bearer sa_full", "Bearer sa_monitor"):
            return self.send_json(401, {"title": "authentication_required", "status": 401})
        monitor_only = auth == "Bearer sa_monitor"
        parts = path.strip("/").split("/")
        if method == "GET" and len(parts) == 6 and parts[2:4] == ["admin", "users"] and parts[5] == "watch-summary":
            days = int(query.get("days", 30))
            if not 1 <= days <= 365:
                return self.send_json(422, {"title": "validation_failed", "status": 422})
            numbers = silo["summary"].get(parts[4], {}).get(str(days), {"plays": 0, "watched_seconds": 0})
            return self.send_json(200, {"days": days, "since": "2026-01-01T00:00:00.000Z", "completed_plays": 0, "last_played_at": None, **numbers})
        if method == "GET" and path == "/api/v2/admin/playback-history":
            limit = int(query.get("limit", 50))
            if limit > 200:
                return self.send_json(422, {"title": "validation_failed", "status": 422})
            if silo["ignore_user_filter"]:
                rows = [p for plays in silo["history"].values() for p in plays]
            else:
                rows = silo["history"].get(query.get("user_id", ""), []) if "user_id" in query else [p for plays in silo["history"].values() for p in plays]
            start = int(query.get("cursor", "0") or 0)
            chunk = rows[start:start + limit]
            more = start + limit < len(rows)
            return self.send_json(200, {"items": chunk, "page": {"has_more": more, **({"next_cursor": str(start + limit)} if more else {})}})
        if method == "GET" and path == "/api/v2/admin/sessions":
            return self.send_json(200, {"items": silo.get("sessions", []), "page": {"has_more": False}})
        if method == "GET" and path == "/api/v2/admin/stats/top-activity":
            days, limit = int(query.get("days", 7)), int(query.get("limit", 10))
            if not (1 <= days <= 30 and 1 <= limit <= 25):
                return self.send_json(422, {"title": "validation_failed", "status": 422})
            top = silo.get("top", {"titles": [], "profiles": []})
            return self.send_json(200, {"days": days, "limit": limit, "titles": top["titles"][:limit], "profiles": top["profiles"][:limit]})
        if method == "GET" and path == "/api/v2/admin/stats/playback-activity":
            if not 1 <= int(query.get("hours", 24)) <= 744:
                return self.send_json(422, {"title": "validation_failed", "status": 422})
            return self.send_json(200, {"hours": int(query["hours"]), "bucket_seconds": 3600, "buckets": silo.get("buckets", []),
                                        "from": "", "to": "", "profiles_active_24h": 0, "reliability": "ok"})
        if monitor_only:
            return self.send_json(403, {"title": "permission_denied", "status": 403})
        if method == "GET" and path == "/api/v2/profiles":
            return self.send_json(200, {"items": silo["profiles"]})
        if method == "GET" and len(parts) == 5 and parts[2:4] == ["catalog", "items"]:
            if self.headers.get("X-Profile-Id") != "p-owner":
                return self.send_json(403 if self.headers.get("X-Profile-Id") else 400, {"title": "profile_required", "status": 400})
            item = silo["items"].get(parts[4])
            if item is None:
                return self.send_json(404, {"title": "not_found", "status": 404})
            return self.send_json(200, {"content_id": parts[4], "genres": [], **item})
        return self.send_json(404, {"title": "not_found", "status": 404})


if __name__ == "__main__":
    ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1] if len(sys.argv) > 1 else 8767)), Handler).serve_forever()
