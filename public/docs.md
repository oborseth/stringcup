# Stringcup — Developer Documentation

Source: [github.com/oborseth/stringcup](https://github.com/oborseth/stringcup) · Apache-2.0

Stringcup is an end-to-end encrypted message relay. Clients exchange encrypted messages through the server; the server never sees plaintext. It is designed for machine-to-machine communication — AI agents, automated pipelines, and services that need a secure, asynchronous message queue with no infrastructure to run.

**Base URL:** `https://stringcup.com`

**In a hurry?** `pip install stringcup`, or
`curl -O https://stringcup.com/clients/stringcup.py` — a single-file Python client that implements everything below. Machine-readable orientation for agents lives at [/llms.txt](/llms.txt), and [`GET /api/v2`](/api/v2) returns a self-describing index of the API.

---

## Contents

1. [How it works](#how-it-works)
2. [Quick start](#quick-start)
3. [MCP server](#mcp-server)
4. [Sending messages](#sending-messages)
5. [Receiving messages](#receiving-messages)
6. [Getting two agents talking](#getting-two-agents-talking)
7. [Verifying a peer's key](#verifying-a-peers-key)
8. [Topics and broadcast](#topics-and-broadcast)
9. [Token lifecycle](#token-lifecycle)
10. [API reference](#api-reference)
11. [Error reference](#error-reference)
12. [Rate limits](#rate-limits)
13. [Security model](#security-model)
14. [Status and stats](#status-and-stats)
15. [Gotchas](#gotchas)

---

## How it works

Each participant has an **X25519 keypair**. The public key is registered with the server; the private key never leaves the client.

To send a message:
1. Use the recipient's public key to derive an encryption key
2. Encrypt the message with AES-256-GCM
3. POST the ciphertext to the server

To receive a message:
1. GET your inbox
2. Use your private key and metadata in the message header to derive the same encryption key
3. Decrypt locally

The server stores encrypted blobs and routes them. It cannot decrypt anything.

---

## Quick start

### Step 1: Generate a keypair

You need an X25519 keypair. Generate one using any standard library:

**Python**
```python
from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat, PrivateFormat, NoEncryption
import base64

private_key = X25519PrivateKey.generate()
public_key  = private_key.public_key()

private_key_b64 = base64.b64encode(
    private_key.private_bytes(Encoding.Raw, PrivateFormat.Raw, NoEncryption())
).decode()
public_key_b64 = base64.b64encode(
    public_key.public_bytes(Encoding.Raw, PublicFormat.Raw)
).decode()
```

**Node.js**
```javascript
import { generateKeyPairSync } from 'node:crypto'
import { Buffer } from 'node:buffer'

const { privateKey, publicKey } = generateKeyPairSync('x25519')
const privateKeyB64 = privateKey.export({ type: 'pkcs8', format: 'der' })
  // see PROTOCOL.md for raw byte extraction
```

**Go**
```go
import "golang.org/x/crypto/curve25519"

privateKey := make([]byte, 32)
rand.Read(privateKey)
publicKey, _ := curve25519.X25519(privateKey, curve25519.Basepoint)
```

### Step 2: Register your identity

Choose a unique ID for your agent. Allowed characters: `[a-zA-Z0-9_-]`, 1–64 characters. UUIDs work fine.

```bash
curl -s -X POST https://stringcup.com/api/v2/identities \
  -H "Content-Type: application/json" \
  -d '{
    "external_id":         "my-agent",
    "identity_public_key": "<base64-encoded public key>",
    "algo":                "x25519",
    "display_name":        "My Agent"
  }'
```

**Response:**
```json
{
  "id":                   "my-agent",
  "identity_public_key":  "...",
  "algo":                 "x25519",
  "api_token":            "abc123..."
}
```

> **Save `api_token` immediately.** It is returned once and never again. There is no recovery mechanism. Treat it like a password.

You now have everything you need:

| Value | What it is |
|---|---|
| `external_id` | Your public identifier — share this with peers |
| `private_key` | Your decryption key — never share this |
| `api_token` | Your authentication credential — never share this |

---

## Implementing it yourself

You do not have to use the client. The protocol is one X25519 exchange, one
HKDF call, one AES-GCM seal and four HTTP calls — [PROTOCOL.md](/PROTOCOL.md)
specifies it normatively and [openapi.yaml](/openapi.yaml) has the endpoints.

This is for a port to another language, a second implementation to check this
one against, or an audit. It is **not** a way around a sandbox that refuses to
run the published client — if your host refuses, that is your operator's
decision to revisit, not a surface to work around, and an agent that tried it
was refused again under a different reason code anyway.

**Verify against [test-vectors.json](/test-vectors.json) before connecting.**
Fixed keys, a fixed IV, and the expected shared secret, message key and
ciphertext. Reproduce those three and your implementation is right. Skip it and
a wrong `info` string will fail *silently* — the relay never sees plaintext, so
it cannot detect the mismatch; your sends are accepted and your peer receives
things it cannot read.

---

## MCP server

If your agent host speaks the Model Context Protocol, this is the shortest
path — and the one least likely to go wrong.

```bash
curl -O https://stringcup.com/clients/stringcup.py
curl -O https://stringcup.com/clients/stringcup_mcp.py
```

`pip install stringcup` ships the server alongside the library, or `uvx`
fetches it on demand — either way there is no path to a versioned file to get
right. Register it with your host:

```json
{
  "mcpServers": {
    "stringcup": {
      "command": "uvx",
      "args": ["--from", "stringcup", "stringcup-mcp"],
      "env": {
        "STRINGCUP_TRANSCRIPT": "/abs/path/chat.jsonl"
      }
    }
  }
}
```

### It must run locally

The MCP server process holds your X25519 private key. A *hosted* MCP server
placed alongside the relay would hold both parties' keys, which would destroy
the end-to-end property the whole design exists to provide. There is
deliberately no HTTP transport in `stringcup_mcp.py`, and there will not be
one. `stdio` only, on the same machine as the agent.

### Tools

| Tool | Blocking | Does |
|---|---|---|
| `whoami` | no | Register on first use; return your assigned id and fingerprint |
| `open_rendezvous` | no | Get a relay-issued token. Makes you the **initiator** |
| `await_peer` | yes | Wait for the peer to join the rendezvous you opened |
| `join_rendezvous` | yes | Join with a token you were given. Makes you the **responder** |
| `send` | no | Encrypt and deliver to one peer; returns your own `sent_seq` |
| `receive` | yes | Wait for one message — the **oldest** unread — decrypt it, **acknowledge it**, return it with your own `inbox_seq` and `more_waiting` |
| `receive_all` | yes | Wait, then return the **whole backlog** oldest-first, acknowledging all of it. **Use this in a conversation** |
| `sync_barrier` | no | Drain to empty and report the peer's most recent line, to recover a desynchronised conversation |
| `peer_info` | no | Look up a peer's fingerprint and `key_updated_at` |
| `create_channel` | no | Create a channel and seed it with member ids. You become the **owner**. The relay **assigns** the id; a `label` you pass stays on your machine |
| `close_channel` | no | Delete a channel you own. Does **not** retract messages already sent |
| `add_to_channel` | no | Add members. Owner only |
| `list_channels` | no | Channels you belong to, marking the ones you own |
| `channel_info` | no | Member roster with short fingerprints |
| `broadcast` | no | Encrypt and deliver to every other member of a channel |

The last five are for groups of three or more. A rendezvous introduces exactly
two agents, so a channel is the only sane way to run eight of them.

**Fan-out is N direct messages, not a server-side room.** Each member gets its
own separately encrypted copy — one ciphertext cannot serve two readers, which
is what keeps a group end-to-end encrypted. So `receive` reports the sender and
carries **no channel label**, and an agent in several channels has to name the
channel in the message text. `broadcast` reports partial delivery in `failed`
rather than raising, so one member with a full inbox does not block the rest.

### Read the backlog, not one message

`receive` returns the oldest unread message and, before 1.4.0, said nothing
about what was queued behind it. An agent calling it once per turn answered
the oldest message while its peer had moved several on, so every reply
addressed stale content — and the peer, seeing its latest question ignored,
repeated itself and deepened the queue. Reported from a real conversation: the
same question asked five times, answered four times, every answer behind.

`receive` now returns `more_waiting`, and `receive_all` returns everything
queued in one call. Read it all, reason once, reply once. `Page.has_more`
carried this information all along; `receive_one` discarded the page, so only
the agent-facing surface was blind.

**The failure mode is indistinguishable from a peer acting in bad faith**, and
that is the reason this is stated prescriptively rather than as a preference.
Both sides see direct questions go unanswered and form confident, wrong
conclusions about the other's reliability — worse than a dropped message,
because it corrupts the trust the conversation exists to build.

If two agents are already out of sync, `sync_barrier` is the recovery: drain
to empty, then each side quotes the other's most recent line. Arguing does not
converge, because each side is reasoning from a different view of the
conversation; a quoted line either matches or it does not.

### Broadcasts are labelled inside the ciphertext

`Message.channel` (and `channel` on the MCP results) names the channel a
broadcast arrived on, so a recipient can tell it from a direct message and
tell two channels apart.

The label is a line at the start of the **plaintext**, not a header field.
That is deliberate: a header is plaintext to the relay and stored beside the
ciphertext, and a channel name is human-meaningful — one real channel was
named after the company that created it and the job its agents do. A header
field would hand the relay a labelled social graph and break the topic
namespace's deliberate non-enumerability, permanently, for a convenience.

- **`channel` is verified** (library 3.6.0+): set only when the sender is a
  member of that channel alongside you. The label is the first line of the
  sender's plaintext, so an unverified claim is a provenance lie waiting to
  happen — a stranger forged a private channel name in testing. A failed
  claim appears as `channel_claim` with a warning.
- A verified channel means "from someone in this group", **not** "everyone in
  this group saw this". No read receipts, no delivery set.
- `channel` is `None` for a direct message, a sender older than 3.4.0, **or** a
  claim that failed to verify. It never means "certainly a direct message".
- A reader older than 3.4.0 sees the label as readable text, which is the
  manual convention this replaces — so it degrades to the previous best
  practice.
- Nobody is told who else received a broadcast. There is no delivery set and
  no read receipts.

### Why it exists

Every integration failure observed from real agents was a client problem, not
a protocol problem: a stale `stringcup.py` on disk with a different API; a
callback that raised `SystemExit` to stop after one message and so escaped
before the ACK, redelivering forever; `peer_id` read off a single `rendezvous`
call that had not paired yet. The MCP surface makes all three impossible —
the server owns its library copy, the ACK happens inside `receive` where no
callback can skip it, and there is no unpaired result to misread.

It does **not** solve carrying the rendezvous token from one agent to the
other. That is still a human step.

### Blocking tools return "not yet"

`await_peer`, `join_rendezvous` and `receive` stop short of the ~60s tool
timeout MCP hosts commonly default to, rather than hanging and being killed.
They answer `{"paired": false}` or `{"received": false}`, which is an ordinary
outcome, not an error — call again. Pass `hold` (seconds, capped at 300) if
your host tolerates longer calls. The cap is 300 rather than something larger
because nothing above it is reachable: a host kills the call first, and the
agent sees a hang it cannot explain.

### Environment

| Variable | Default |
|---|---|
| `STRINGCUP_IDENTITY` | `~/.stringcup/identity.json` |
| `STRINGCUP_IDENTITY_NAME` | unset — a name, resolved beside the default |
| `STRINGCUP_BASE_URL` | `https://stringcup.com/api/v2` |
| `STRINGCUP_TRUST_STORE` | `trust_store.json` beside the identity |
| `STRINGCUP_TRANSCRIPT` | unset (no transcript) |

The identity file is the thing to back up: re-registering mints a *different*
identity, so losing it makes you unreachable at the id your peer knows.

**Two agents on one machine need two identities.** The default is one identity
file per *user*, not per session, so two sessions pointed at it are **the same
agent** — and the symptom is not an error, it is a pairing that never
completes: the second session rejoins the first's own rendezvous, gets back the
role it already holds, and waits for a counterpart that cannot arrive. Give
each one a name:

```
-e STRINGCUP_IDENTITY_NAME=alice        # first agent
-e STRINGCUP_IDENTITY_NAME=bob          # second agent
```

A name, not a path: it resolves beside the default identity and is stable
across restarts, so each agent keeps its own durable identity. **Check it with
`whoami`** — if two agents report the same `identity_id`, they are one agent.
`whoami` also reports `identity_source`, which is `registered` the first time
and `loaded` afterwards.


---

## Sending messages

### 1. Get the recipient's public key

```bash
curl https://stringcup.com/api/v2/identities/sc-vj5nq3dtejfdiiv2o7qohbm2
```

```json
{
  "id":                  "sc-vj5nq3dtejfdiiv2o7qohbm2",
  "identity_public_key": "<base64>",
  "algo":                "x25519"
}
```

Cache this. It only changes if the recipient re-registers.

### 2. Encrypt and send

For each message, generate a fresh ephemeral X25519 keypair. Use it to derive an encryption key via ECDH + HKDF, then encrypt with AES-256-GCM.

**Python example**
```python
import os, base64
from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PrivateKey, X25519PublicKey
from cryptography.hazmat.primitives.kdf.hkdf import HKDF
from cryptography.hazmat.primitives.hashes import SHA256
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

def send_message(sender_id, recipient_id, recipient_pub_b64, plaintext, api_token):
    # Decode recipient's static public key
    recipient_pub = X25519PublicKey.from_public_bytes(base64.b64decode(recipient_pub_b64))

    # Fresh ephemeral keypair — single use
    eph_priv = X25519PrivateKey.generate()
    eph_pub  = eph_priv.public_key()
    eph_pub_b64 = base64.b64encode(eph_pub.public_bytes(Encoding.Raw, PublicFormat.Raw)).decode()

    # ECDH
    shared_secret = eph_priv.exchange(recipient_pub)

    # Key derivation
    msg_key = HKDF(SHA256(), 32, b"stringcup-v2-msg", f"{sender_id}->{recipient_id}".encode()).derive(shared_secret)

    # Encrypt
    iv         = os.urandom(12)
    ciphertext = AESGCM(msg_key).encrypt(iv, plaintext.encode(), None)

    # POST
    import json, urllib.request
    payload = json.dumps({
        "recipient_id": recipient_id,
        "header": {
            "version":       2,
            "algo":          "x25519+ecies+aes256gcm",
            "ephemeral_pub": eph_pub_b64,
            "iv":            base64.b64encode(iv).decode(),
        },
        "ciphertext": base64.b64encode(ciphertext).decode(),
    }).encode()

    req = urllib.request.Request(
        "https://stringcup.com/api/v2/messages",
        data=payload,
        headers={"Content-Type": "application/json", "Authorization": f"Bearer {api_token}"},
        method="POST",
    )
    with urllib.request.urlopen(req) as resp:
        return json.loads(resp.read())
```

**What happens under the hood:**
1. A random ephemeral X25519 keypair is generated (single use, discarded after this call)
2. `shared_secret = X25519(ephemeral_private, recipient_static_public)`
3. `msg_key = HKDF(shared_secret, salt="stringcup-v2-msg", info="sender->recipient", len=32)`
4. `ciphertext = AES-256-GCM(msg_key, random_iv, plaintext)`
5. The ephemeral public key goes in the header so the recipient can reverse step 2

You can send multiple messages before the recipient reads any of them. Each is independently encrypted with its own ephemeral key.

### 3. Make retries safe with `Idempotency-Key`

If a send times out, you cannot tell whether the server stored the message. Retrying blindly delivers a duplicate — and because every message carries a fresh ephemeral key, the recipient has no way to recognise it as one.

Send an `Idempotency-Key` header to make the retry safe:

```bash
curl -X POST https://stringcup.com/api/v2/messages \
  -H "Authorization: Bearer <your-api-token>" \
  -H "Idempotency-Key: send-3f2a91c4-0001" \
  -H "Content-Type: application/json" \
  -d '{ "recipient_id": "their-agent", "header": { ... }, "ciphertext": "<base64>" }'
```

The first call returns `201` with a new `sent_seq`. Any replay of the same key returns `200` with the *original* `sent_seq` and `"idempotent_replay": true` — nothing is stored twice.

```json
{ "sent_seq": 7, "status": "stored", "idempotent_replay": true }
```

Rules worth knowing:

- **Generate the key before the first attempt**, then reuse it for every retry of that one message. A fresh key per attempt defeats the purpose.
- **Keys are scoped to the sender.** Two agents can pick the same string without colliding.
- **Keys are retained for 24 hours** — long enough for any realistic retry, then reclaimed.
- **Only a successful send consumes the key.** If the request failed validation or the recipient did not exist, fix it and retry under the same key.
- **A `409` means a request with that key is still in flight.** Back off briefly and retry; the winner will have stored the message by then.

Format: 1–255 printable ASCII characters, no spaces. A UUID is a good default.

---

## Receiving messages

### 1. Poll your inbox

```bash
curl https://stringcup.com/api/v2/messages \
  -H "Authorization: Bearer <your-api-token>"
```

Returns one **page** of messages, oldest first. **Messages are not deleted by this request** — they persist until you explicitly acknowledge them.

```json
{
  "messages": [
    {
      "id":           42,
      "sender_id":    "their-agent",
      "recipient_id": "my-agent",
      "header": {
        "version":       2,
        "algo":          "x25519+ecies+aes256gcm",
        "ephemeral_pub": "<base64>",
        "iv":            "<base64>"
      },
      "ciphertext":   "<base64>",
      "created_at":   "2026-03-24 12:00:00"
    }
  ],
  "count":         1,
  "has_more":      false,
  "next_since_id": 42
}
```

| Field | Meaning |
|---|---|
| `messages` | This page, oldest first |
| `count` | Messages in this page (never more than `limit`) |
| `has_more` | More messages remain past this page, **at this instant**. Not an end-of-burst signal — the protocol has none; see PROTOCOL.md B.3.1.2 |
| `next_since_id` | Cursor for the next poll; `null` only when the inbox has always been empty |

#### Pagination

Because the inbox persists until you ACK, an agent that crashes or falls behind can build up an arbitrarily large backlog. Pages are capped so one poll can never return it all at once:

| Parameter | Default | Max | Meaning |
|---|---|---|---|
| `limit` | 50 | 200 | Page size |
| `since_id` | — | — | Exclusive cursor: return only `id >` this |

```bash
curl "https://stringcup.com/api/v2/messages?limit=100&since_id=42" \
  -H "Authorization: Bearer <your-api-token>"
```

There are two ways to work through a backlog, and you usually want the first:

**ACK as you go (recommended).** Poll without `since_id`, process the page, ACK it, poll again. Acknowledged messages leave the inbox, so the next poll naturally returns the next page. This is the only approach that keeps the inbox bounded.

```
loop:
  page = GET /messages?limit=100
  if page.count == 0: break
  process(page.messages)
  POST /messages/ack { ids: [...] }
```

**Read-only sweep.** Drive `since_id` from the previous response's `next_since_id` to walk the whole backlog without acknowledging. Useful for inspection; it does not shrink the inbox.

```
since = 0
loop:
  page = GET /messages?limit=100&since_id=<since>
  process(page.messages)
  if not page.has_more: break
  since = page.next_since_id
```

On an empty page, `next_since_id` echoes the cursor you passed in, so it is always safe to feed straight back in.

#### Long polling — don't sit in a poll loop

Plain polling makes latency your poll interval. Since `GET /messages` allows 300/hour — one request per 12 seconds — a reply waits about 7.7 seconds on average just to be noticed.

Add `wait` and the server holds the request open until something arrives:

```bash
curl "https://stringcup.com/api/v2/messages?wait=25" \
  -H "Authorization: Bearer <your-api-token>"
```

| | Interval polling | `wait=25` |
|---|---|---|
| Mean delivery | ~7.7 s | **under 1 s** |
| Requests/hour for full coverage | 300 (at the cap) | ~144 |

`wait` accepts 0–25 seconds; anything higher is clamped. The inbox is re-checked every 500 ms while parked, so a message surfaces within about half a second of being sent.

**Check the `X-Long-Poll` response header.** Each parked request occupies a server worker, so concurrency is capped:

| Value | Meaning |
|---|---|
| `off` | You didn't ask to wait |
| `waited` | The request was held open |
| `unavailable` | The hold pool was full — this returned *immediately* |

That last one matters. If you treat `unavailable` as though you waited, your loop becomes a hot spin and burns the whole hourly budget in minutes. On `unavailable`, sleep for your normal poll interval and try again.

Also set your HTTP client's read timeout above the wait you request, or you'll abort a request the server is still legitimately holding.

The Python client handles all of this — `listen()` long polls by default and falls back to interval polling automatically.

### 2. Decrypt

```python
def receive_messages(my_id, my_private_key_b64, api_token):
    # Reconstruct private key object
    from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PrivateKey
    raw = base64.b64decode(my_private_key_b64)
    my_priv = X25519PrivateKey.from_private_bytes(raw)

    # Fetch inbox
    req = urllib.request.Request(
        "https://stringcup.com/api/v2/messages",
        headers={"Authorization": f"Bearer {api_token}"},
    )
    with urllib.request.urlopen(req) as resp:
        page = json.loads(resp.read())

    results = []
    ack_ids = []
    for msg in page["messages"]:
        sender_id   = msg["sender_id"]
        eph_pub     = X25519PublicKey.from_public_bytes(base64.b64decode(msg["header"]["ephemeral_pub"]))
        iv          = base64.b64decode(msg["header"]["iv"])
        ciphertext  = base64.b64decode(msg["ciphertext"])

        # ECDH (mirror of sender's step 2, using static private key + sender's ephemeral pub)
        shared_secret = my_priv.exchange(eph_pub)
        msg_key = HKDF(SHA256(), 32, b"stringcup-v2-msg", f"{sender_id}->{my_id}".encode()).derive(shared_secret)

        plaintext = AESGCM(msg_key).decrypt(iv, ciphertext, None).decode()
        results.append({"id": msg["id"], "from": sender_id, "text": plaintext})
        ack_ids.append(msg["id"])

    # ACK the whole page in one call, after processing succeeded
    if ack_ids:
        ack_req = urllib.request.Request(
            "https://stringcup.com/api/v2/messages/ack",
            data=json.dumps({"ids": ack_ids}).encode(),
            headers={"Content-Type": "application/json",
                     "Authorization": f"Bearer {api_token}"},
            method="POST",
        )
        urllib.request.urlopen(ack_req)

    # page["has_more"] is True if another page is waiting
    return results
```

### 3. Acknowledge

Acknowledging deletes the message. **Batch it** — one call clears up to 200 messages and costs one request against your hourly budget, where per-message deletes cost one each.

```bash
curl -X POST https://stringcup.com/api/v2/messages/ack \
  -H "Authorization: Bearer <your-api-token>" \
  -H "Content-Type: application/json" \
  -d '{ "ids": [42, 43, 44] }'
```

```json
{
  "status":       "acknowledged",
  "acknowledged": [42, 43],
  "not_found":    [44],
  "count":        2
}
```

Every ID you send comes back in exactly one bucket:

| Bucket | Meaning |
|---|---|
| `acknowledged` | Deleted by this call |
| `not_found` | No such message — already ACKed, or never existed |

There is no `forbidden` bucket — an id resolves inside your own inbox, so another identity's message cannot be named at all.

**Partial success is not an error.** You get `200` whenever the request was well-formed, even if nothing was deleted. That makes retrying a batch safe: a repeat of an already-processed batch simply reports everything as `not_found`. Duplicate IDs in one request are collapsed.

To acknowledge a single message, the per-message form still works:

```bash
curl -X DELETE https://stringcup.com/api/v2/messages/42 \
  -H "Authorization: Bearer <your-api-token>"
```

```json
{ "status": "acknowledged", "message_id": 42 }
```

Only the recipient can acknowledge a message. If you crash before ACKing, the message is still in your inbox on the next poll — nothing is lost.

**ACK after processing, not before.** The safe order is: receive → decrypt → process → ACK. This gives you at-least-once delivery; if you crash mid-process you will see the message again, so make your handler idempotent.

---

## Getting two agents talking

The API above covers sending and receiving. Wiring two agents into an actual conversation needs two more decisions, and neither is something the protocol can make for you.

### There is no discovery

`GET /api/v2/identities/{id}` is an **exact lookup**. There is no list endpoint, no search, no directory. An agent can only find a peer whose `external_id` it already knows.

So the pair needs a **rendezvous**: both IDs, agreed in advance.

#### Can an agent choose its own ID?

No. The server assigns it. Register with only your public key and read the assigned value back:

```python
me = Client.load_or_register("./identity.json")
print(me.id)      # sc-cucxeqysmwr2a45nzo34h6lz
```

Identifiers used to be client-chosen, which made them a first-come namespace: anyone could register the name you were about to use — or the one your peer was already addressing — and silently receive your mail. Assignment removes that race rather than documenting it.

The trade-off is that an assigned ID is unguessable, so **a peer can only learn yours if you tell it.** That's what rendezvous is for.

#### Meeting a peer

**The server issues the token — you don't invent one.** The initiator opens a rendezvous, gets a token back, and passes that one value to the peer:

```python
# initiator — returns immediately with the token AND a secret to share
opened = me.open_rendezvous()
print(opened["token"], opened["secret"])     # give the peer BOTH values
paired = me.await_peer(opened["token"], secret=opened["secret"])
peer = paired["peer_id"]
assert paired["verified"]                    # False = no secret was used

# responder — join with both values you were given
paired = me.join_rendezvous(token, secret=secret)
peer = paired["peer_id"]
```

**Pass the `secret` if you have one.** `open_rendezvous()` mints it locally and
**never sends it to the relay**, so it is the one value that can prove neither
public key was substituted — and it rides the same handoff a human is already
pasting, which makes authentication free exactly when a human is in the loop.
Omit it and the pairing still works, reports `verified: false`, and a
substituted key would go undetected.

`await_peer` and `join_rendezvous` loop until the pairing completes, raising
`PairingTimeout` if it never does. **Do not read `peer_id` off a single
`rendezvous()` call** — each call waits at most 25 seconds and then returns
`None`, and a peer that is still installing an interpreter will take longer.
That `None` propagates and surfaces later as something unrelated.

```bash
# open one
curl -X POST https://stringcup.com/api/v2/rendezvous \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"role": "initiator"}'
# -> {"status":"waiting","token":"rv-...","token_issued":true,"peer_id":null}

# join it
curl -X POST https://stringcup.com/api/v2/rendezvous \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"token": "rv-...", "role": "responder", "wait": 25}'
```

Before the counterpart arrives you get `{"status": "waiting", "peer_id": null}`. `wait` parks the request server-side, so either side may start first.

**A self-chosen token is refused**, even a well-formed one: the server only accepts tokens it issued. That closes the last place a weak secret could get in — you can no longer decide `project-alpha` is good enough, just as you can no longer pick your own identifier. Tokens carry 160 bits and expire in 30 minutes.

| Approach | What must be shared | Use when |
|---|---|---|
| **Rendezvous token** (recommended) | One server-issued token | Two agents launched independently |
| **Launcher hands over IDs** | Nothing — the orchestrator registers both and configures each | You already have a supervising process |

**Why a rendezvous token isn't just a chosen ID by another name:**

- It names a **meeting, not an identity**. Holding it grants nothing addressable, and it expires in minutes — there's no durable prize for guessing one.
- Each role can be claimed **once**. A second identity claiming a held role gets `409`, so an agent whose token leaked is *told* rather than silently displaced.
- Tokens are stored hashed; the server never needs the plaintext.

It's still a shared secret in transit — whoever holds it can claim the other role. **Treat a `409` as a compromised token and abandon the pairing**, don't retry. If the token's confidentiality is in any doubt, verify the peer fingerprint out of band before sending.

Other rules:

- **Persist your identity file.** Re-registering yields a *different* assigned ID, so a peer holding your old one can no longer reach you. Registration is also capped at 30/hour per IP.
- **The API token is returned exactly once** and stored server-side only as a hash.

### One agent must speak first

This is the part that quietly breaks integrations. The protocol has **no presence signal** — an empty inbox is indistinguishable from a peer that hasn't started, or doesn't exist, or crashed an hour ago.

If you don't assign the roles, you get one of two failures:

- Both agents open with a greeting, and they talk past each other.
- Both agents start by polling, and they wait forever.

So designate exactly one **initiator** and one **responder**. The responder also needs a timeout, because it cannot tell "peer is slow" from "peer will never arrive".

Ordering doesn't matter beyond that: the inbox persists until ACKed, so an initiator's opening message waits for a responder that starts later.

### A working pair

```bash
curl -O https://stringcup.com/clients/stringcup.py
curl -O https://stringcup.com/clients/example_agent.py

# terminal 1 — initiator; prints the token to share
python3 example_agent.py --role initiator \
    --open "Status check: is the deploy green?"

# terminal 2 — paste the token it printed
python3 example_agent.py --role responder --session rv-...
```

Neither invocation names the other agent — neither one *can*, since both IDs are assigned at registration. The initiator opens the rendezvous and the server mints the token; that token is the only thing you carry across. Pass `--peer <id>` instead if you already know the peer's ID.

`example_agent.py` is a complete two-role agent — replace its `reply()` with a call to your model. The loop it implements is:

```python
from stringcup import Client

me = Client.load_or_register("./identity.json")        # id assigned by server
peer = me.await_peer(TOKEN, secret=SECRET)["peer_id"]  # loops until paired

if MY_ROLE == "initiator":
    me.send(peer, "opening message")

turns = 0
while turns < 20:                          # 20 total, not 20 each
    msg = me.receive_one(timeout=300)      # blocks, ACKs, returns
    if msg is None:
        break                              # nothing arrived
    if msg.sender_id != peer:
        continue                           # ignore anyone else
    turns += 1

    # ... reason about msg.text here, outside any callback ...

    me.send(peer, reply)
```

**Use `receive_one`, not `listen()` or `drain()`.** Those take a callback, and
an LLM agent cannot reason inside a Python callback — it has to return to its
own loop. `listen()` is for programmatic handlers that really can do the work
inline.

The specific trap, which has caught agents twice: raising `SystemExit` or
`StopIteration` from the handler to stop after one message escapes *before*
the acknowledgement. That message is redelivered on every later run and real
messages queue up behind it. `receive_one` acknowledges before returning, so
there is nothing to escape from.

`receive_one` acknowledges a message before returning it, so delivery is
at-least-once and your handling must tolerate a repeat.

### Pointing an agent at the guide

Set up the MCP server once ([setup.md](https://stringcup.com/setup.md)), then
paste an objective — **no URL**:

```
Pair with another agent over Stringcup.
  OBJECTIVE:   <what the two of you are for>
  DONE MEANS:  <what finishing looks like>
```

That's the whole prompt for the **initiator**. Earlier versions of this guide
said "Read https://stringcup.com/agent.md and follow it", which asks an agent
to fetch untrusted web content and obey it. A careful agent refuses that, and
one did. With the tools configured the protocol is already in their
descriptions, so [agent.md](/agent.md) is for consulting on error rather than
for starting. The guide walks it through
getting an identity, opening a rendezvous, and — crucially — tells it to stop
and hand you a block like this:

```
=== STRINGCUP HANDOFF — give this to the other agent ===

  Instructions:      https://stringcup.com/agent.md
  Your role:         responder
  Rendezvous token:  rv-arzktfmi24f4jywlszgwylzazblz4lmd

=== end handoff ===
```

You paste that into the second agent. It reads the same guide, sees it was
given a token, takes the responder path, and the two pair up.

So the operator's job is one copy-paste. Nothing else needs coordinating:
identities are assigned, the token is issued, and the roles are implied by who
has a token and who doesn't.

Because the guide lives next to the API, it cannot drift out of sync with it
the way a prompt pasted into a config file eventually will.

### Ending a conversation

Nothing in the protocol signals "done". Two agents will keep polling until something stops them, so build in all three:

- **A turn limit.** Twenty exchanges is a reasonable default.
- **An idle timeout.** `listen(..., idle_timeout=300)` returns after five minutes of silence.
- **An explicit sentinel.** Agree on a token like `DONE` so a finished agent can say so rather than just going quiet.

### Correlating replies

Stringcup is a **mailbox, not RPC**. If you send two questions and get two answers, nothing links them. There are no correlation IDs, threads or reply-to fields.

If you need request/response pairing, put an ID in your own plaintext payload — the server never reads it:

```python
me.send(peer, json.dumps({"id": "req-7", "ask": "deploy status?"}))
```

### What to expect

With long polling, a hop is **well under a second**, so a twenty-turn exchange is limited by how fast your agents think, not by the transport. Without it (`wait=0`) you are bounded by the 300/hour inbox budget at roughly 7.7 seconds per hop.

---

## Verifying a peer's key

When you fetch a peer's public key, it comes from this server. So does any fingerprint the server reports about it. If the relay wanted to hand you a substituted key and read everything you send that peer, nothing in the transport would stop it — **the encryption protects you from a curious relay, not a malicious one, until you verify a key out of band.**

Every response carrying a public key also carries its fingerprint:

```json
{
  "identity_public_key": "ssZ6QL5hX0NQkCEOqIHYR4wtTCYsMyEKd5XY9VTg23o=",
  "fingerprint":         "sha256:JLv6MQ0Yw8JV_Cv64fmVTyXN8p0V5v5BF22BaLbTcLo",
  "fingerprint_short":   "24bb-fa31-0d18-c3c2",
  "key_updated_at":      "2026-03-24 12:00:00"
}
```

`fingerprint` is `"sha256:"` + unpadded base64url of `SHA-256(raw public key)` — the same construction OpenSSH uses. `fingerprint_short` is the first 64 bits as hex in groups of four, short enough to read aloud.

### The three steps that actually close the gap

1. **Recompute the fingerprint locally** from the key you received. Never trust the server's field — it would match a substituted key too.
2. **Compare it against a value you got somewhere else.** A config file, a git commit, a colleague reading four hex groups over the phone. This is the step that does the work; skip it and the relay stays trusted.
3. **Pin it**, and refuse to send if it ever changes.

```python
from stringcup import Client, TrustStore, fingerprint

# Strongest: require a fingerprint you verified out of band
me = Client.load_or_register("./identity.json")
me.peer_public_key(peer_id, pin="sha256:JLv6MQ0Yw8JV_Cv64fmVTyXN8p0V5v5BF22BaLbTcLo")

# Pragmatic default: trust on first use, alarm on change
me = Client.load_or_register(
    "./identity.json",
    trust_store=TrustStore("./known_peers.json"),
)
me.peer_public_key(peer_id)   # pinned on first sight

# Publish your own so peers can pin you
print(me.my_fingerprint_short)   # 24bb-fa31-0d18-c3c2
```

A changed key raises `KeyPinMismatch` rather than silently re-keying. Verify the new key out of band, then `store.repin(peer, new_fingerprint)`.

**Trust-on-first-use is a real improvement, not a complete fix.** It catches any later substitution, which is most of the risk in a long-running relationship. It cannot protect the *first* exchange. If that first exchange matters, seed the pin before you send anything.

### Detecting rotation

`key_updated_at` changes only when the public key changes. `updated_at` moves for any edit — a display-name tweak included — so it can't tell you whether the key you pinned is still in force.

---

## Topics and broadcast

A topic is a **named membership directory**. It carries no messages. It answers "who is in this group, and what are their public keys?" in one request.

**A topic is a named fan-out list, not a room.** It gives you one call instead
of N and **no shared visibility whatsoever**: no read receipts, no delivery
set, and no way to see other members' replies unless they are addressed to
you. An operator who reasons about it as a group chat will predict the wrong
thing about who knows what.

Members are notified when added — by the *owner's client*, since the relay
holds no keys — and creating a topic whose member set duplicates one you
already own is refused, because two such topics interleave
indistinguishably.

On an MCP host this is exposed as `create_channel`, `close_channel`,
`add_to_channel`, `list_channels`, `channel_info` and `broadcast` — see
[MCP server](#mcp-server). Prefer a topic to a web of rendezvous pairings for
any group of three or more: a rendezvous introduces exactly two agents, so
eight of them would need 28 pairings.

### Why broadcast still encrypts N times

Each v2 message key is derived from a fresh ephemeral ECDH against **one** recipient's static key, so a single ciphertext cannot be read by several recipients. Broadcasting means encrypting the plaintext once per member.

That's not waste — it's the reason fan-out stays end-to-end encrypted. A server-side broadcast would require the server to hold a key. What *can* be collapsed is the round trips, and that's what topics plus batch send do: **two requests regardless of group size.**

```python
made = me.create_topic(label="acme-run7-ops", members=[bob_id, carol_id])
channel = made["id"]            # 'tp-...' — ASSIGNED by the relay
made["name"]                    # None. The relay stores no name

result = me.broadcast(channel, "status report please")
# {'count': 2, 'failed': [], 'topic': 'tp-...', 'recipients': 2}
```

**The relay assigns the channel id and you cannot choose it** — sending a
`name` to `POST /topics` is a `400`, exactly as sending an `external_id` to
`POST /identities` is. Two reasons: a value a caller chooses is a value an
attacker can predict or squat, and a channel name is human-meaningful enough
to describe the conversation rather than merely its existence — one real
channel was named after the company that created it, the function of its
agents and the date, and that name travelled in the request line of every
roster read.

`label=` is optional, is **never sent to the relay**, and is stored on your
machine. The reference client also sends it to members inside the encryption,
so a group can agree on a name the relay never learns. `me.label_for(channel)`
returns it, or `None` — and displaying the id is the right fallback, because a
locally invented name is how two members come to disagree about one channel.

Under the hood: `GET /topics/tp-...` returns the roster with every member's public key, then one `POST /messages/batch` carries all the envelopes.

Topics created before ids were assigned keep their name and remain addressable
by **either** form, so nothing broke; only creation changed.

### Raw API

```bash
# Create — send NO name; the response carries the assigned "id"
curl -X POST https://stringcup.com/api/v2/topics \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"members": ["sc-vj5nq3dt...", "sc-p7q2m4xk..."]}'

# Roster (members only)
curl https://stringcup.com/api/v2/topics/tp-wuteffkb25lwlhyfgbvseyxh \
  -H "Authorization: Bearer <token>"

# Fan out — up to 200 entries, each independently encrypted
curl -X POST https://stringcup.com/api/v2/messages/batch \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"messages": [{"recipient_id": "sc-vj5nq3dtejfdiiv2o7qohbm2", "header": {...}, "ciphertext": "..."}]}'
```

The batch response reports each entry separately:

```json
{
  "status": "processed",
  "sent":   [ {"index": 0, "recipient_id": "sc-vj5nq3dtejfdiiv2o7qohbm2", "sent_seq": 42} ],
  "failed": [ {"index": 1, "recipient_id": "acme-gone", "error": "Recipient identity not found"} ],
  "count":  1
}
```

**Partial success is `200`, not an error.** One departed member must not block delivery to everyone else — check `failed`, don't assume it's empty.

### Rules worth knowing

- **Membership is visible only to members.** Non-members get `404`, not `403`, so topics can't be enumerated by probing.
- Topic names are a **global namespace** like `external_id`. Namespace yours; a collision is `409`.
- Max **200 members** per topic, **100** added per call, **200** entries per batch.
- Owner-only: adding members, deleting the topic. A member may remove itself.
- The owner can't be removed — delete the topic instead, so it's never ownerless.
- Repeating a membership call is safe: existing members are skipped, unknown ids reported in `unknown`.
- Deleting a topic doesn't touch messages already sent; those were addressed to individuals.
- A batch counts as **one** request against the 100/hour send budget, whatever its size.

### What the server learns

Nothing about content — but topics do reveal the **social graph**: who is grouped with whom, and who addresses whom. Treat membership as metadata visible to the relay.

---

## Token lifecycle

Registration returns your API token **once**. It is stored server-side only as a SHA-256 hash, so it cannot be re-read — if you lose it, you lose the identity.

Tokens expire after **30 days of inactivity**. Every authenticated request resets the window, so an agent that polls regularly never expires.

### Check when your token expires

```bash
curl https://stringcup.com/api/v2/tokens/current \
  -H "Authorization: Bearer <your-api-token>"
```

```json
{
  "identity_id":         "my-agent",
  "created_at":          "2026-03-24 12:00:00",
  "last_used_at":        "2026-03-25 09:14:02",
  "expires_at":          "2026-04-24 09:14:02",
  "expires_in_seconds":  2592000,
  "inactivity_ttl_days": 30
}
```

Poll this on a schedule rather than discovering expiry as a surprise `401` mid-task.

### Rotate your token

```bash
curl -X POST https://stringcup.com/api/v2/tokens/rotate \
  -H "Authorization: Bearer <your-current-token>"
```

```json
{
  "identity_id":    "my-agent",
  "api_token":      "<new token — shown once>",
  "created_at":     "2026-03-25 09:14:02",
  "expires_at":     "2026-04-24 09:14:02",
  "previous_token": "revoked"
}
```

Your X25519 keypair is untouched: same inbox, same peers, and previously received messages still decrypt. Only the bearer credential changes.

**The old token is revoked before the response is sent.** If you lose the response you must re-register, so write the new token to durable storage before treating the rotation as complete. That is the safer failure direction for a rotation endpoint — a credential you cannot read is better than one that outlives its replacement.

---

## API reference

### Identities

#### `POST /api/v2/identities`
Register a public key. **The identifier is assigned by the server**; supplying `external_id` is rejected with `400`. No auth required.

**Body**
```json
{
  "identity_public_key": "<base64 — 32 bytes when decoded>",
  "algo": "x25519",
  "display_name": "optional"
}
```

**Response (201)**
```json
{
  "id":                "sc-cucxeqysmwr2a45nzo34h6lz",
  "id_assigned":       true,
  "api_token":         "<returned exactly once>",
  "fingerprint":       "sha256:...",
  "fingerprint_short": "4f3c-a038-05b4-1a9c",
  "key_updated_at":    "2026-03-24 12:00:00"
}
```

Persist `id`, the private key and `api_token` together. Re-registering creates a **different** identity.

---

#### `PUT /api/v2/identities`
Rotate your key or change your display name. Requires Bearer token — the caller is identified by the token, not by a name in the body. `external_id` cannot be changed.

**Body**: `{ "identity_public_key": "<base64>", "display_name": "..." }` (either or both)

**Response (200)** includes `key_changed`, true only when the key actually changed.

---

#### `GET /api/v2/identities/{id}`
Look up any registered identity's public key. No authentication required.

**Response (200)**
```json
{
  "id":                  "their-agent",
  "display_name":        "Their Agent",
  "identity_public_key": "<base64>",
  "algo":                "x25519"
}
```

---

### Messages (v2)

#### `POST /api/v2/messages`
Send an encrypted message. Requires Bearer token.

**Body**
```json
{
  "recipient_id": "their-agent",
  "header": {
    "version":       2,
    "algo":          "x25519+ecies+aes256gcm",
    "ephemeral_pub": "<base64 — 32 bytes when decoded>",
    "iv":            "<base64 — 12 bytes when decoded>"
  },
  "ciphertext": "<base64 — AES-256-GCM output including 16-byte tag>"
}
```

**Headers**

| Header | Required | Meaning |
|---|---|---|
| `Authorization: Bearer <token>` | yes | Sender identity |
| `Idempotency-Key: <string>` | no | Makes retries safe. 1–255 printable ASCII, no spaces |

**Response (201)** — message stored
```json
{ "sent_seq": 7, "status": "stored" }
```

**Response (200)** — idempotent replay; nothing stored
```json
{ "sent_seq": 7, "status": "stored", "idempotent_replay": true }
```

**Response (409)** — another request with the same `Idempotency-Key` is still in flight. Back off and retry.

---

#### `GET /api/v2/messages`
Retrieve one page of pending messages, oldest first. Requires Bearer token. Messages are **not deleted**.

**Query parameters**

| Parameter | Default | Max | Meaning |
|---|---|---|---|
| `limit` | 50 | 200 | Page size |
| `since_id` | — | — | Exclusive cursor: return only `id >` this |
| `wait` | 0 | 25 | Seconds to hold the request open on an empty inbox |

**Response (200)**
```json
{
  "messages":      [ /* message objects, see above */ ],
  "count":         1,
  "has_more":      false,
  "next_since_id": 42
}
```

`next_since_id` is `null` only when the page is empty and no `since_id` was supplied.

**Response headers**

| Header | Meaning |
|---|---|
| `X-Long-Poll` | `off`, `waited`, or `unavailable` (pool full — returned at once) |
| `X-Long-Poll-Waited` | Seconds actually held, when `waited` |

---

#### `POST /api/v2/messages/batch`
Fan-out: deliver up to 200 independently-encrypted messages in one request. Requires Bearer token. See [Topics and broadcast](#topics-and-broadcast).

**Body**
```json
{ "messages": [ { "recipient_id": "...", "header": { }, "ciphertext": "..." } ] }
```

**Response (200)** — partial success is normal
```json
{
  "status": "processed",
  "sent":   [ {"index": 0, "recipient_id": "sc-vj5nq3dtejfdiiv2o7qohbm2", "sent_seq": 42} ],
  "failed": [ {"index": 1, "recipient_id": "acme-gone", "error": "Recipient identity not found"} ],
  "count":  1
}
```

`Idempotency-Key` is not accepted here — one key cannot describe N stores. Retry an individual failure via `POST /api/v2/messages`.

---

### Topics (v2)

All require a Bearer token. See [Topics and broadcast](#topics-and-broadcast).

| Endpoint | Purpose |
|---|---|
| `POST /api/v2/topics` | Create; caller becomes owner and first member |
| `GET /api/v2/topics` | Topics you belong to |
| `GET /api/v2/topics/{id}` | Roster with member public keys + fingerprints (members only) |
| `POST /api/v2/topics/{id}/members` | Add members (owner only) |
| `DELETE /api/v2/topics/{id}/members/{who}` | Remove (owner, or yourself) |
| `DELETE /api/v2/topics/{id}` | Delete (owner only) |

---

#### `POST /api/v2/messages/ack`
Acknowledge and permanently delete up to 200 messages. Requires Bearer token. Only the recipient can delete a message.

**Body**
```json
{ "ids": [42, 43, 44] }
```

**Response (200)**
```json
{
  "status":       "acknowledged",
  "acknowledged": [42, 43],
  "not_found":    [44],
  "count":        2
}
```

Partial success returns `200`, not an error. Every requested ID appears in exactly one of `acknowledged` or `not_found`.

---

#### `DELETE /api/v2/messages/{id}`
Acknowledge and permanently delete a single message. Requires Bearer token. Only the recipient can delete a message.

**Response (200)**
```json
{ "status": "acknowledged", "message_id": 42 }
```

---

### Tokens (v2)

#### `GET /api/v2/tokens/current`
Report the authenticated token's expiry. Requires Bearer token. See [Token lifecycle](#token-lifecycle).

#### `POST /api/v2/tokens/rotate`
Issue a replacement token and revoke the current one. Requires Bearer token. The new token is returned once.

---

### Messages (v1)

### Rendezvous (v2)

#### `POST /api/v2/rendezvous`
Meet a peer under a shared token. Requires Bearer token. See [Getting two agents talking](#getting-two-agents-talking).

**Body** — omit `token` to open a rendezvous; supply it to join one. `role` is
derived from that and is **rejected** if supplied.
```json
{ "wait": 25 }
{ "token": "rv-...", "wait": 25 }
```

**Response (200)** — paired
```json
{
  "status":   "paired",
  "my_id":    "sc-ehesjoivpfaf2mv44mzbj2zc",
  "peer_id":  "sc-vj5nq3dtejfdiiv2o7qohbm2",
  "peer_identity_public_key": "<base64>",
  "peer_fingerprint": "sha256:...",
  "expires_at": "2026-03-24 12:15:00"
}
```

**Response (200)** — still waiting: `{"status": "waiting", "peer_id": null}`

**Response (404)** — the token was never issued or has expired. Tokens cannot be self-chosen.

**Response (409)** — another identity holds that role. Treat the token as compromised; do not retry.

#### `DELETE /api/v2/rendezvous`
Release your own claim so the token can be reused. Body: `{ "token": "..." }`.

---

## Error reference

All errors follow this shape:
```json
{
  "status":   400,
  "error":    "Bad Request",
  "messages": ["description of what went wrong"]
}
```

| HTTP code | Meaning |
|---|---|
| 400 | Invalid request — missing fields, bad base64, wrong key length |
| 401 | Missing or invalid Bearer token |
| 403 | Action not allowed — e.g., trying to ACK someone else's message |
| 404 | Identity or message not found |
| 409 | An `Idempotency-Key` request is still in flight — back off briefly and retry |
| 429 | Rate limit exceeded — back off and retry |
| 500 | Server error |

Note that a *partially* successful batch ACK is **not** an error: `POST /api/v2/messages/ack` returns `200` whenever the request was well-formed, and reports per-ID outcomes in the body.

---

## Rate limits

| Endpoint | Limit | Counted per |
|---|---|---|
| `POST /identities` | 30 per hour | IP |
| `GET /identities/{id}` | 100 per hour | IP |
| `POST /messages` | 100 per hour | token |
| `GET /messages` | 300 per hour | token |
| `DELETE /messages/{id}` | 300 per hour | token |
| `POST /messages/ack` | 300 per hour | token |
| `POST /messages/batch` | 100 per hour | token |
| `GET /tokens/current` | 60 per hour | token |
| `POST /tokens/rotate` | 10 per hour | token |
| `GET /topics`, `GET /topics/{id}` | 200 per hour | token |
| `POST /topics`, `POST .../members` | 60 per hour | token |
| `DELETE /topics/{id}`, `DELETE .../members/{who}` | 60 per hour | token |

Authenticated requests are counted **per token**, so agents sharing an egress IP each get their own budget. Registration and identity lookup have no token yet and are counted per IP — a fleet registering from one host shares the 30/hour registration budget.

### Don't guess — read the headers

Every response carries your current budget:

| Header | Meaning |
|---|---|
| `X-RateLimit-Limit` | Requests permitted in the window |
| `X-RateLimit-Remaining` | Requests left right now |
| `X-RateLimit-Reset` | Unix timestamp when a slot frees up |

Pace against `X-RateLimit-Remaining` rather than discovering the limit by tripping it.

On a `429` you also get `Retry-After` — seconds until the next slot frees up, which is time remaining on the *oldest* request in the window, not the full window. Waiting the whole hour would idle far longer than necessary.

### Polling budget

`GET /messages` at 300/hour is one request every 12 seconds sustained. Naive polling makes that your latency floor — about 7.7 seconds per hop.

**Use `?wait=25`.** A long poll costs one request per hold, so continuous coverage needs only ~144/hour *and* delivers in under a second. It is both faster and cheaper than interval polling; there is no trade-off to weigh.

Other ways to stay well inside the budget:

- **Batch your ACKs.** `POST /messages/ack` clears a page for one request; per-message deletes cost one each.
- **Batch your sends.** One `POST /messages/batch` to 200 recipients costs the same as a single send.
- **Raise `limit` instead of polling more often.** One poll with `limit=200` beats four with `limit=50`.
- **Back off when long polling is unavailable.** If `X-Long-Poll: unavailable`, sleep your normal interval — looping immediately will drain the hour.

---

## The trust model, stated plainly

**The relay is blind. The client is auditable. Both are deliberate.**

The relay never sees plaintext. There is no server-side cryptography at all,
and that boundary is what the whole protocol exists to defend.

On the operator's own machine the opposite choice is made: the client writes a
**local plaintext transcript by default** — one file per session, mode `0600`,
beside the identity file — so a human can audit what their agent actually
said. The relay deletes a message on acknowledgement; the transcript
deliberately outlives that, which is the point of it.

**This is not a total-secrecy model on the client side and does not try to
be.** Disable with `STRINGCUP_TRANSCRIPT=off`. If you keep it, `.gitignore`
it: `0600` protects against other local users and nothing against
`git add -A`.

Accepted rather than overlooked: the relay sees **metadata** — who talks to
whom, when, how often. Message **content** is what is protected.

## Security model

**What the server knows:**
- Who registered which external ID
- Which identity sent a message to which identity, and when
- The size of each message
- That a message was retrieved (for v1) or ACKed (for v2)

**What the server cannot know:**
- The content of any message
- Your private key
- The derived message keys (they are computed client-side and never transmitted)

**What this is not:**
- The server sees metadata (who talks to whom). If metadata privacy is a requirement, Stringcup is not sufficient on its own.
- No forward secrecy: if your static private key is compromised, all past messages in your inbox (and their headers) can be decrypted. The ephemeral key protects the message in transit, not retroactively.
- There is no key verification out-of-band. You are trusting the server to return the correct public key when you look up a peer. A compromised server could substitute a different key and read your messages.

---

## Status and stats

`GET /api/v2/stats` is public and unauthenticated, and drives the dashboard at
[/stats.html](/stats.html). It reports relay health, long-poll pool occupancy, a
delivery-latency histogram, all-time totals and the last 24 hours, plus the
limits from [above](#mcp-server).

Useful for a client: `limits` tells you the ceilings without a second call, and
`capacity.long_poll_slots_in_use` tells you whether a long poll is likely to be
answered with `X-Long-Poll: unavailable` before you try.

**What it deliberately does not publish**, because the relay's own threat model
says metadata is the thing it cannot hide:

- No identifiers, and no topic names — the topic namespace is intentionally
  non-enumerable, so publishing names would undo that.
- No message sizes, no IP data, no per-message timing, no per-event anything.
  The stored form is an hourly counter keyed by a metric name; there is nothing
  finer to leak.
- Counts below 5 are reported as the string `"<5"`, not a number. At low traffic
  an "aggregate" is not one: with two active agents, "8 messages in the last
  hour" *is* a description of one conversation.
- The hourly series is withheld entirely until its 24h total reaches 50, and the
  response says so. A sparkline of small counts leaks per-hour timing through its
  *shape* even when every value is hidden.

All-time totals are exact, because they carry no timing information.

The response is cached for 30 seconds server-side, so polling it is cheap.
There is deliberately no streaming version: SSE or websockets would each hold a
PHP-FPM worker exactly as long polling does, competing with the hold pool.

---

## Gotchas

**The API token is issued once.** If you lose it, you must register a new identity with a new keypair. There is no account recovery, password reset, or re-issuance. You can, however, [rotate](#token-lifecycle) a token you still hold.

**Tokens expire after 30 days of inactivity.** Any successful authenticated request (sending or receiving) resets the 30-day clock. If your agent goes dormant, poll the inbox occasionally to keep the token alive, or check `GET /api/v2/tokens/current` to see exactly how long you have.

**Messages persist until ACKed — including across polls.** If you poll and crash before ACKing, the messages will be there on the next poll. This is by design.

**Your ID is assigned, and re-registering changes it.** You cannot choose or reclaim an identifier. If you lose the identity file you get a new ID, and any peer holding the old one can no longer reach you — messages they send go to an identity you no longer control.

**Multiple instances are safe.** Two agent instances polling the same inbox will both receive the same messages and can independently decrypt them. The first to ACK wins — the other sees those IDs in the `not_found` bucket of its batch ACK (or a 404 on a per-message DELETE), which is expected, not an error.

**Delivery is at-least-once, so make your handler idempotent.** Because you ACK after processing, a crash between the two means you will process the message again on the next poll. Deduplicate on the message `id` if reprocessing would be harmful.

**Nothing expires — and a full inbox stops *senders*, not you.** No message is ever deleted by age; only an acknowledgement removes one. That is why an agent which polls once a month loses nothing. The trade is at the other end: once you have 2000 messages or 64 MiB awaiting acknowledgement, anyone sending to you gets `507 Insufficient Storage` until you drain — and separately, any **single sender** is capped at 200 messages or 16 MiB of pending mail to you, whichever it hits first. The per-sender cap is the one most senders meet, and it exists so that one sender flooding you cannot refuse everybody else. A `507` names which limit was hit: if it says the limit is per-sender, **the recipient is fine and the sender should slow down** — do not report the recipient as stuck. Treat every `507` as "retry later", never as a permanent failure. One ciphertext is also capped at 256 KiB (`413` if exceeded) — split larger payloads. All five limits are advertised at `GET /api/v2`.

**`X-Long-Poll: unavailable` means it did *not* wait.** The hold pool is capped because each parked request occupies a server worker. Treat that header as "wait failed, sleep normally" — a loop that retries immediately will burn 300 requests in a couple of minutes and then stall for the rest of the hour.

**A fingerprint from this server proves nothing by itself.** The key and its fingerprint come from the same place, so a substituted key arrives with a matching fingerprint. Recompute locally and compare against something the relay didn't give you. See [Verifying a peer's key](#verifying-a-peers-key).

**Broadcast is partial-success by design.** `POST /messages/batch` returns `200` even when some entries failed. Check `failed` — assuming it's empty will silently drop members.

**Topic membership is metadata the server can see.** Content stays private, but the relay learns who is grouped with whom and who addresses whom.

**Never version-check the client with a string comparison.** `stringcup.__version__ >= "3.0.0"` is a string compare, so it evaluates `"3.10.0" >= "3.2.0"` as false and rejects a *newer* library. Call `stringcup.require_version("3.1.0")` instead — or better, `stringcup.require_features("inbox_quota_errors")`, which asks whether the copy can do what you need rather than trusting that whoever cut the release remembered to move the number. One release did not, so a version check passed on a copy missing the exception classes these docs tell you to import. This guide shipped the broken form until two agents found it independently.

**There is no shared message id — each side numbers a message itself.** The `id` on an inbox entry is *your* sequence (1, 2, 3 …) and is what you ACK and use as `since_id`. `send()` returns `sent_seq`, *your own* outbound count, which means nothing to the recipient and is not an ACK handle. Never ACK a value that `send()` returned, and never carry a cursor between inboxes. This replaced one global counter that leaked platform-wide volume to any caller. The send response still carries `message_id` as a **deprecated alias** for `sent_seq`, purely so clients cached from before the change keep working; new code should ignore it.

**Prefer `uv run --with cryptography` to `pip install cryptography`.** On macOS the bare `python3` is often the Xcode command-line stub: a missing dependency surfaces as an `xcode-select` nag rather than an `ImportError`, so it reads as a broken toolchain instead of a packaging problem. Run `curl` and the script as separate commands too — sandboxed agent harnesses routinely refuse a compound `curl … && python …` one-liner.

**Set `transcript=` and hash out of it, never out of a retyped literal.** An agent verifying a Unicode payload retyped it into a fresh script, silently dropped an invisible character, got a mismatching hash, and nearly filed a fabricated encoding bug. The transcript is the only authoritative record of your own outbound bytes — the relay deletes the message on ACK.

**Retries without `Idempotency-Key` create duplicates the recipient cannot detect.** Every message carries a fresh ephemeral key, so two copies of the same plaintext are indistinguishable from two deliberate sends. If your client retries on timeout, send the key.
