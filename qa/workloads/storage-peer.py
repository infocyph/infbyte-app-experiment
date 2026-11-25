"""Actual FTP and WebDAV server implementations on loopback."""
import sys

mode, root = sys.argv[1:]
if mode == "ftp":
    from pyftpdlib.authorizers import DummyAuthorizer
    from pyftpdlib.handlers import FTPHandler
    from pyftpdlib.servers import FTPServer
    auth = DummyAuthorizer()
    auth.add_user("experiment", "fixture-only-password", root, perm="elradfmwMT")
    FTPHandler.authorizer = auth
    FTPServer(("127.0.0.1", 12121), FTPHandler).serve_forever()
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
