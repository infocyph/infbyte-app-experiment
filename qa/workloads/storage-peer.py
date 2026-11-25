"""Actual FTP and WebDAV server implementations on loopback."""
import sys

mode, root = sys.argv[1:]
if mode == "ftp":
    from pyftpdlib.authorizers import DummyAuthorizer
    from pyftpdlib.handlers import FTPHandler
    from pyftpdlib.servers import FTPServer
    auth = DummyAuthorizer()
    auth.add_user("experiment", "fixture-only-password", root, perm="elradfmwMT")
    import os
    from pathlib import Path

    class LabFTPHandler(FTPHandler):
        # Inject only against the explicitly armed tenant-a rename; the real
        # filesystem implementation still performs the mutation in after mode.
        armed_rename = None

        def record_disconnect(self, phase, reason):
            signal = Path(os.environ["LAB_FTP_SIGNAL"])
            try:
                with (signal / phase).open("x") as marker:
                    marker.write(reason + "\n")
            except FileExistsError:
                (signal / ("replayed-" + phase)).touch()
            self.close()

        def ftp_RNTO(self, path):
            signal = os.environ.get("LAB_FTP_SIGNAL")
            if not signal:
                return super().ftp_RNTO(path)
            phase = (Path(signal) / "phase").read_text().strip()
            prefix = Path(root) / "tenant-a" / "receipts"
            if phase not in ("before", "after") or Path(path) != prefix / (phase + "-published.bin"):
                return super().ftp_RNTO(path)
            if Path(self._rnfr or "") != prefix / (phase + "-stage.bin"):
                return super().ftp_RNTO(path)
            if phase == "before":
                self.record_disconnect(phase, "rename request withheld")
                return
            self.armed_rename = phase
            try:
                return super().ftp_RNTO(path)
            finally:
                self.armed_rename = None

        def respond(self, response, logfun=None):
            if self.armed_rename and response.startswith("250 "):
                self.record_disconnect(self.armed_rename, "native rename success response withheld")
                return
            if logfun is None:
                return super().respond(response)
            return super().respond(response, logfun=logfun)

    LabFTPHandler.authorizer = auth
    FTPServer(("127.0.0.1", 12121), LabFTPHandler).serve_forever()
elif mode == "webdav":
    from cheroot.wsgi import Server
    from wsgidav.wsgidav_app import WsgiDAVApp
    app = WsgiDAVApp({
        "provider_mapping": {"/": root},
        "http_authenticator": {"domain_controller": "wsgidav.dc.simple_dc.SimpleDomainController"},
        "simple_dc": {"user_mapping": {"*": {"experiment": {"password": "fixture-only-password"}}}},
        "verbose": 1,
    })
    Server(("127.0.0.1", 18085), app).start()
else:
    raise ValueError("Unknown storage peer")
