"""
Cross-language interop test: Python client <-> PHP reference implementation.

Both sides must derive byte-identical message keys from the same ECIES/HKDF
parameters. This is the check that catches a subtly wrong HKDF info string or
salt — the failure mode the client library exists to prevent, and one that no
single-language test can detect.

Usage:  python3 test_interop.py [base_url]
"""

import json
import os
import subprocess
import sys
import tempfile
import uuid

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from stringcup import Client  # noqa: E402

BASE = sys.argv[1] if len(sys.argv) > 1 else "https://stringcup.com/api/v2"
HERE = os.path.dirname(os.path.abspath(__file__))
PHP_DRIVER = os.path.join(HERE, "_interop_php.php")

PASSED = 0


def step(msg):
    print(f"\n[STEP] {msg}")


def ok(msg):
    global PASSED
    PASSED += 1
    print(f"  ✓  {msg}")


def fail(msg):
    print(f"  ✗  {msg}")
    sys.exit(1)


def same(expected, actual, msg):
    ok(msg) if expected == actual else fail(
        f"{msg} — expected {expected!r}, got {actual!r}"
    )


def php(state_path, *args):
    env = dict(os.environ, STRINGCUP_API=BASE, INTEROP_STATE=state_path)
    proc = subprocess.run(
        ["php", PHP_DRIVER, *args], capture_output=True, text=True, env=env
    )
    if proc.returncode != 0:
        fail(f"php {args[0]} failed: {proc.stderr.strip()}")
    return json.loads(proc.stdout.strip().splitlines()[-1])


workdir = tempfile.mkdtemp(prefix="stringcup-interop-")
suffix = uuid.uuid4().hex[:8]
php_state = os.path.join(workdir, "php.json")

print("Stringcup interop test — Python client <-> PHP reference")
print(f"Base   : {BASE}")



# Messages chosen to stress encoding: accents, CJK, emoji, quotes, newline,
# and an empty-ish edge. All must survive AES-GCM + base64 + JSON both ways.
SAMPLES = [
    "plain ascii",
    "accents: héllo àçñ",
    "cjk: 中文日本語한국어",
    "emoji: \U0001f510\U0001f680✅",
    'json-ish: {"key": "value", \'q\': "\\"quoted\\""}',
    "multi\nline\ttabbed",
    "x" * 4000,
]

try:
    step("1. Register one agent in each language")
    reg = php(php_state, "register")
    php_id, php_pub = reg["id"], reg["public_key"]
    py = Client.register(base_url=BASE, display_name="Python interop")
    py_id = py.id
    ok(f"Server assigned {php_id} (PHP) and {py_id} (Python)")

    step("2. Each side fetches the other's public key from the server")
    fetched_php_pub = py.peer_public_key(php_id)
    same(php_pub, fetched_php_pub, "Python read PHP's registered public key")

    step("3. Python -> PHP")
    for text in SAMPLES:
        py.send(php_id, text)
    ok(f"Python sent {len(SAMPLES)} messages")

    decrypted = php(php_state, "recv")
    same(len(SAMPLES), len(decrypted), "PHP received them all")
    same(SAMPLES, decrypted, "PHP decrypted every Python message byte-for-byte")

    step("4. PHP -> Python")
    for text in SAMPLES:
        res = php(php_state, "send", py_id, py.identity.public_key_b64, text)
        if res["code"] != 201:
            fail(f"PHP send failed: {res}")
    ok(f"PHP sent {len(SAMPLES)} messages")

    received = []
    py.drain(lambda m: received.append(m.text))
    same(len(SAMPLES), len(received), "Python received them all")
    same(SAMPLES, received, "Python decrypted every PHP message byte-for-byte")

    step("5. Interleaved round trip")
    py.send(php_id, "ping from python")
    same(["ping from python"], php(php_state, "recv"), "PHP got the ping")

    php(php_state, "send", py_id, py.identity.public_key_b64, "pong from php")
    got = []
    py.drain(lambda m: got.append(m.text))
    same(["pong from php"], got, "Python got the pong")

    step("6. Cleanup")
    py.drain(lambda m: None)
    php(php_state, "recv")
    ok("Both inboxes drained")

    print("\n" + "=" * 48)
    print(f"  INTEROP VERIFIED ({PASSED} assertions)")
    print("=" * 48 + "\n")

finally:
    import shutil

    shutil.rmtree(workdir, ignore_errors=True)
