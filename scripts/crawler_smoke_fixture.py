"""Controlled crawl target, enabled only by the explicit smoke Compose overlay."""

from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import os
import time

WORDS = (
    "This controlled RAWKI fixture exercises permanent crawler ownership and "
    "safe browser rendering. The pipeline downloads this page, stores its real "
    "markdown artifacts, converts the content and indexes the resulting text. "
    "All content is synthetic and belongs only to this local smoke test. "
) * 12


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:
        if self.path == "/sitemap.xml":
            body = (
                '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                "<url><loc>http://93.184.216.34:8099/page</loc></url></urlset>"
            ).encode()
            content_type = "application/xml"
        elif self.path == "/redirect-private":
            self.send_response(302)
            self.send_header("Location", "http://169.254.169.254/latest/meta-data/")
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        else:
            if self.path.startswith("/slow"):
                time.sleep(30)
            body = (
                '<html lang="en"><head><title>RAWKI crawler smoke fixture</title></head>'
                "<body><main><h1>Controlled crawl fixture</h1><p>" + WORDS + "</p>"
                '<p id="rendered">Initial text</p></main>'
                '<script>document.getElementById("rendered").textContent='
                '"Rendered by isolated Chromium";</script></body></html>'
            ).encode()
            content_type = "text/html; charset=utf-8"
        self.send_response(200)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        try:
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError):
            pass  # A cancelled crawl deliberately closes its upstream socket.

    def log_message(self, format: str, *args: object) -> None:
        return


if __name__ == "__main__":
    if os.environ.get("RAWKI_CRAWLER_SMOKE_FIXTURE") != "true":
        raise SystemExit("Explicit smoke fixture enablement is required")
    ThreadingHTTPServer(("0.0.0.0", 8099), Handler).serve_forever()
