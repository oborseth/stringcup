#!/usr/bin/env python3
"""
Stringcup API v2 — End-to-end agent emulation test

Emulates two agents (Alice and Bob) using the ECIES protocol:
  - Key generation and identity registration
  - Alice sends two messages before Bob reads (queue behavior)
  - Bob decrypts both, ACKs each
  - Verifies inbox is empty after ACKs
  - Bob replies; Alice decrypts and ACKs
  - Security check: non-recipient cannot ACK a message
"""

import base64
import json
import os
import sys
import urllib.request
import urllib.error

from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PrivateKey
from cryptography.hazmat.primitives.kdf.hkdf import HKDF
from cryptography.hazmat.primitives.hashes import SHA256
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat, PrivateFormat, NoEncryption

API_BASE = "https://stringcup.com/api/v2"

suffix   = os.urandom(4).hex()
alice_id = f"test-alice-{suffix}"
bob_id   = f"test-bob-{suffix}"

# ============================================================
# Output helpers
# ============================================================

def step(msg):   print(f"\n[STEP] {msg}")
def ok(msg):     print(f"  ✓  {msg}")
def fail(msg):
    print(f"  ✗  {msg}")
    sys.exit(1)

# ============================================================
# HTTP helper
# ============================================================

def api(method, path, body=None, token=None):
    url = f"{API_BASE}{path}"
    headers = {"Content-Type": "application/json", "Accept": "application/json"}
    if token:
        headers["Authorization"] = f"Bearer {token}"

    data = json.dumps(body).encode() if body is not None else None
    req  = urllib.request.Request(url, data=data, headers=headers, method=method)

    try:
        with urllib.request.urlopen(req) as resp:
            return resp.status, json.loads(resp.read())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read())

# ============================================================
# Crypto helpers
# ============================================================

def generate_keypair():
    priv = X25519PrivateKey.generate()
    pub  = priv.public_key()
    return {
        "priv":      priv,
        "priv_raw":  priv.private_bytes(Encoding.Raw, PrivateFormat.Raw, NoEncryption()),
        "pub":       pub,
        "pub_raw":   pub.public_bytes(Encoding.Raw, PublicFormat.Raw),
        "pub_b64":   base64.b64encode(pub.public_bytes(Encoding.Raw, PublicFormat.Raw)).decode(),
    }

def hkdf_derive(ikm: bytes, salt: str, info: str, length=32) -> bytes:
    return HKDF(
        algorithm=SHA256(),
        length=length,
        salt=salt.encode(),
        info=info.encode(),
    ).derive(ikm)

def ecies_encrypt(sender_id: str, recipient_id: str, recipient_pub_raw: bytes, plaintext: str) -> dict:
    """Encrypt plaintext for recipient. Returns header + ciphertext ready to POST."""
    # Fresh ephemeral keypair per message
    eph_priv     = X25519PrivateKey.generate()
    eph_pub_raw  = eph_priv.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)

    # ECDH using ephemeral private key + recipient static public key
    from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PublicKey
    recipient_pub = X25519PublicKey.from_public_bytes(recipient_pub_raw)
    shared_secret = eph_priv.exchange(recipient_pub)

    # HKDF
    msg_key = hkdf_derive(shared_secret, "stringcup-v2-msg", f"{sender_id}->{recipient_id}")

    # AES-256-GCM (AESGCM appends 16-byte tag automatically)
    iv         = os.urandom(12)
    ciphertext = AESGCM(msg_key).encrypt(iv, plaintext.encode(), None)

    return {
        "header": {
            "version":       2,
            "algo":          "x25519+ecies+aes256gcm",
            "ephemeral_pub": base64.b64encode(eph_pub_raw).decode(),
            "iv":            base64.b64encode(iv).decode(),
        },
        "ciphertext": base64.b64encode(ciphertext).decode(),
    }

def ecies_decrypt(my_priv, sender_id: str, my_id: str, msg: dict) -> str:
    """Decrypt an inbox message. Only needs recipient's static private key."""
    from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PublicKey
    eph_pub_raw = base64.b64decode(msg["header"]["ephemeral_pub"])
    iv          = base64.b64decode(msg["header"]["iv"])
    ciphertext  = base64.b64decode(msg["ciphertext"])

    eph_pub       = X25519PublicKey.from_public_bytes(eph_pub_raw)
    shared_secret = my_priv.exchange(eph_pub)

    msg_key   = hkdf_derive(shared_secret, "stringcup-v2-msg", f"{sender_id}->{my_id}")
    plaintext = AESGCM(msg_key).decrypt(iv, ciphertext, None)
    return plaintext.decode()

# ============================================================
# TEST SUITE
# ============================================================

print("Stringcup API v2 — Agent-to-Agent Test")
print(f"Alice ID : {alice_id}")
print(f"Bob ID   : {bob_id}")
print(f"API Base : {API_BASE}")

# --- 1. Generate keypairs ---
step("1. Generate X25519 keypairs")
alice = generate_keypair()
bob   = generate_keypair()
ok(f"Alice pub: {alice['pub_b64'][:20]}...")
ok(f"Bob pub:   {bob['pub_b64'][:20]}...")

# --- 2. Register Alice ---
step(f"2. Register Alice ({alice_id})")
code, body = api("POST", "/identities", {
    "external_id":         alice_id,
    "identity_public_key": alice["pub_b64"],
    "algo":                "x25519",
    "display_name":        "Test Agent Alice",
})
if code != 201 or not body.get("api_token"):
    fail(f"Registration failed: {code} {body}")
alice_token = body["api_token"]
ok(f"Registered. Token: {alice_token[:10]}...")

# --- 3. Register Bob ---
step(f"3. Register Bob ({bob_id})")
code, body = api("POST", "/identities", {
    "external_id":         bob_id,
    "identity_public_key": bob["pub_b64"],
    "algo":                "x25519",
    "display_name":        "Test Agent Bob",
})
if code != 201 or not body.get("api_token"):
    fail(f"Registration failed: {code} {body}")
bob_token = body["api_token"]
ok(f"Registered. Token: {bob_token[:10]}...")

# --- 4. Alice fetches Bob's public key ---
step("4. Alice fetches Bob's public key")
code, body = api("GET", f"/identities/{bob_id}")
if code != 200:
    fail(f"Identity lookup failed: {code} {body}")
bob_pub_fetched = base64.b64decode(body["identity_public_key"])
if bob_pub_fetched != bob["pub_raw"]:
    fail("Fetched public key does not match generated key")
ok("Public key verified (matches generated key)")

# --- 5. Alice sends two messages before Bob reads ---
step("5. Alice sends two messages to Bob (queue test — Bob has not read yet)")
plaintexts = [
    "Hello Bob! This is message #1 from Alice.",
    "This is message #2, sent before you checked your inbox.",
]
for i, pt in enumerate(plaintexts):
    enc  = ecies_encrypt(alice_id, bob_id, bob["pub_raw"], pt)
    code, body = api("POST", "/messages", {
        "recipient_id": bob_id,
        "sender_id":    alice_id,
        **enc,
    }, alice_token)
    if code != 201:
        fail(f"Send message {i+1} failed: {code} {body}")
    ok(f"Message {i+1} stored (id={body['message_id']})")

# --- 6. Bob polls — both messages present (non-destructive) ---
step("6. Bob polls inbox (non-destructive — both messages should be present)")
code, inbox = api("GET", "/messages", token=bob_token)
if code != 200:
    fail(f"Inbox poll failed: {code} {inbox}")
if len(inbox) != 2:
    fail(f"Expected 2 messages, got {len(inbox)}")
ok(f"Got {len(inbox)} messages")

step("6b. Bob polls again (messages should persist — not deleted by first GET)")
code, inbox2 = api("GET", "/messages", token=bob_token)
if len(inbox2) != 2:
    fail(f"Expected 2 messages on second poll — inbox was unexpectedly cleared")
ok("Messages still present on second poll (persistent inbox confirmed)")

# --- 7. Bob decrypts and ACKs each message ---
for i, msg in enumerate(inbox):
    step(f"7.{i+1}. Bob decrypts message {i+1} (id={msg['id']}, sender={msg['sender_id']})")

    if msg["header"]["algo"] != "x25519+ecies+aes256gcm":
        fail(f"Unexpected algo: {msg['header']['algo']}")

    try:
        decrypted = ecies_decrypt(bob["priv"], msg["sender_id"], bob_id, msg)
    except Exception as e:
        fail(f"Decryption error: {e}")

    ok(f'Decrypted: "{decrypted}"')
    if decrypted != plaintexts[i]:
        fail(f'Plaintext mismatch! Expected: "{plaintexts[i]}"')
    ok("Plaintext matches original")

    code, body = api("DELETE", f"/messages/{msg['id']}", token=bob_token)
    if code != 200:
        fail(f"ACK failed: {code} {body}")
    ok(f"ACKed (deleted) message id={msg['id']}")

# --- 8. Verify inbox is empty after ACKs ---
step("8. Bob polls inbox again — should be empty after ACKs")
code, inbox = api("GET", "/messages", token=bob_token)
if code != 200:
    fail(f"Inbox poll failed: {code} {inbox}")
if len(inbox) != 0:
    fail(f"Expected empty inbox, got {len(inbox)} messages")
ok("Inbox is empty")

# --- 9. Bob replies to Alice ---
step("9. Bob fetches Alice's public key and sends a reply")
code, body = api("GET", f"/identities/{alice_id}")
if code != 200:
    fail(f"Identity lookup failed: {code} {body}")
alice_pub_fetched = base64.b64decode(body["identity_public_key"])
if alice_pub_fetched != alice["pub_raw"]:
    fail("Alice's fetched public key does not match")
ok("Alice's public key verified")

reply = "Hi Alice! Got both your messages. Crypto works perfectly!"
enc = ecies_encrypt(bob_id, alice_id, alice["pub_raw"], reply)
code, body = api("POST", "/messages", {
    "recipient_id": alice_id,
    "sender_id":    bob_id,
    **enc,
}, bob_token)
if code != 201:
    fail(f"Reply send failed: {code} {body}")
ok(f"Reply stored (id={body['message_id']})")

# --- 10. Alice decrypts Bob's reply ---
step("10. Alice polls inbox and decrypts Bob's reply")
code, inbox = api("GET", "/messages", token=alice_token)
if code != 200 or len(inbox) != 1:
    fail(f"Expected 1 message in Alice inbox, got: {code} {inbox}")
msg = inbox[0]
ok(f"Got 1 message from {msg['sender_id']}")

try:
    decrypted = ecies_decrypt(alice["priv"], msg["sender_id"], alice_id, msg)
except Exception as e:
    fail(f"Decryption error: {e}")

ok(f'Decrypted: "{decrypted}"')
if decrypted != reply:
    fail("Plaintext mismatch!")
ok("Plaintext matches")

code, body = api("DELETE", f"/messages/{msg['id']}", token=alice_token)
if code != 200:
    fail(f"ACK failed: {code} {body}")
ok("ACKed reply")

# --- 11. Security: non-recipient cannot ACK ---
step("11. Security: Bob tries to ACK a message addressed to Alice (should be 403)")
enc = ecies_encrypt(bob_id, alice_id, alice["pub_raw"], "Another message to Alice")
code, body = api("POST", "/messages", {"recipient_id": alice_id, **enc}, bob_token)
if code != 201:
    fail(f"Setup send failed: {code} {body}")
target_id = body["message_id"]

code, body = api("DELETE", f"/messages/{target_id}", token=bob_token)
if code != 403:
    fail(f"Expected 403 Forbidden, got {code}: {body}")
ok("Got 403 Forbidden — server correctly rejects non-recipient ACK")

# Cleanup leftover message
code, inbox = api("GET", "/messages", token=alice_token)
for m in (inbox or []):
    api("DELETE", f"/messages/{m['id']}", token=alice_token)

# ============================================================
print("\n================================================")
print("  ALL TESTS PASSED")
print("================================================\n")
