"""Disposable loopback TLS terminator for authenticated MCP integration tests."""
import argparse
import http.client
import http.server
import ssl


class Proxy(http.server.BaseHTTPRequestHandler):
    def handle_request(self):
        connection = http.client.HTTPConnection("127.0.0.1", 8088, timeout=30)
        body = self.rfile.read(int(self.headers.get("Content-Length", "0")))
        headers = {key: value for key, value in self.headers.items()
                   if key.lower() not in ("host", "connection", "transfer-encoding")}
        headers["Host"] = "localhost:8088"
        headers["X-Forwarded-Proto"] = "https"
        try:
            connection.request(self.command, self.path, body, headers)
            response = connection.getresponse()
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in ("connection", "transfer-encoding", "content-length"):
                    self.send_header(key, value)
            data = response.read()
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
        finally:
            connection.close()

    do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = handle_request


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--cert", required=True)
    parser.add_argument("--key", required=True)
    args = parser.parse_args()
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 443), Proxy)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(args.cert, args.key)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    server.serve_forever()
