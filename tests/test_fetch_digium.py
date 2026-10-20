import http.cookiejar
import importlib.util
import os
import tempfile
import unittest
from unittest import mock


ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SPEC = importlib.util.spec_from_file_location("fetch_digium_config", os.path.join(ROOT, "fetch_digium_config.py"))
fetcher = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(fetcher)


def config(extension="1000"):
    return (
        "<<VOIP CONFIG FILE>>\n<GLOBAL CONFIG MODULE>\n<SIP CONFIG MODULE>\n"
        f"SIP1 Register User : {extension}\n" + "Padding : value\n" * 8 + "<<END OF FILE>>\n"
    ).encode("latin-1")


class Response:
    def __init__(self, content, status=200):
        self.content = content
        self.status_code = status


class FakeSession:
    def __init__(self, responses):
        self.responses = list(responses)
        self.cookies = None
        self.headers = {}
        self.get_urls = []
        self.posts = []
        self.closed = False

    def get(self, url, **kwargs):
        self.get_urls.append(url)
        return self.responses.pop(0)

    def post(self, url, **kwargs):
        self.posts.append((url, kwargs))
        return Response(b"OK")

    def close(self):
        self.closed = True


class DigiumFetcherTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.temp_patch = mock.patch.object(fetcher.tempfile, "gettempdir", return_value=self.temp.name)
        self.temp_patch.start()

    def tearDown(self):
        self.temp_patch.stop()
        self.temp.cleanup()

    def test_hash_matches_phone_algorithm(self):
        self.assertEqual("e2fc714c4727ee9395f324cd2e7f331f", fetcher.md5_custom("abcd"))

    def test_session_jar_is_private_and_device_scoped(self):
        path = fetcher.ensure_session_jar("00:0F:D3:AA:BB:CC")
        self.assertTrue(path.endswith("digium_000fd3aabbcc.cookies"))
        self.assertEqual(0o600, os.stat(path).st_mode & 0o777)
        self.assertEqual(0o700, os.stat(os.path.dirname(path)).st_mode & 0o777)
        with self.assertRaises(ValueError):
            fetcher.ensure_session_jar("not-a-mac")

    def test_valid_cached_session_still_fetches_fresh_config(self):
        session = FakeSession([Response(config())])
        with mock.patch.object(fetcher.requests, "Session", return_value=session):
            result = fetcher.fetch_config("192.0.2.20", "admin", "secret", "000fd3000001")
        self.assertTrue(result["success"])
        self.assertTrue(result["session_reused"])
        self.assertEqual(["http://192.0.2.20/config.txt"], session.get_urls)
        self.assertTrue(session.closed)

    def test_authentication_fallback_uses_nonce_then_downloads(self):
        session = FakeSession([
            Response(b"<html>Logon</html>"),
            Response(b"<html>Logon</html>"),
            Response(b"1234567890abcdef"),
            Response(config("2000")),
        ])
        with mock.patch.object(fetcher.requests, "Session", return_value=session):
            result = fetcher.fetch_config("192.0.2.21", "admin", "secret", "000fd3000002")
        self.assertTrue(result["success"])
        self.assertFalse(result["session_reused"])
        self.assertEqual(1, len(session.posts))
        self.assertIn("encoded", session.posts[0][1]["data"])
        self.assertEqual("http://192.0.2.21/config.txt", session.get_urls[-1])

    def test_response_validation_and_errors(self):
        self.assertTrue(fetcher.is_digium_config(config().decode("latin-1")))
        self.assertFalse(fetcher.is_digium_config("<html>not a config</html>"))
        self.assertEqual("Phone web interface is busy; wait before retrying", fetcher.response_error("Server too busy"))
        self.assertEqual("login_required", fetcher.response_error("<title>Logon</title>"))
        self.assertEqual("IP belongs to an nginx web server, not the phone", fetcher.response_error("<center>nginx</center>"))


if __name__ == "__main__":
    unittest.main()
