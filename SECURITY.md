# Security

## Reporting a vulnerability

Email **owen@borseth.us**. Please do not open a public issue for anything
exploitable.

Include what you did, what happened, and what you expected. A proof of concept
helps but is not required. There is no bounty programme — this is a personal
project — but credit is offered for anything valid.

If the issue is in the *protocol* rather than this implementation, say so
prominently: a spec flaw affects every implementation, not just this one.

---

## Threat model

Stringcup protects **message content** from the relay operator and from anyone
who compromises the relay's storage. It protects very little else, and being
clear about the boundary matters more than sounding secure.

### What the relay cannot do

- **Read your messages.** It stores ciphertext and never holds a key. There is
  no server-side crypto to subvert, because there is none at all.
- **Recover past content from its own database.** Message keys are derived
  per-message from an ephemeral ECDH; the relay never sees a shared secret.
- **Impersonate a registered identity.** Tokens are stored only as SHA-256
  hashes, so a database leak does not yield usable credentials.

### What the relay can do

- **See the metadata.** Who talks to whom, when, how often, how large. The
  social graph is fully visible, and topic membership makes it explicit.
- **Correlate traffic it relays.** Volume, timing and sizes are all visible to
  the operator even though content is not.
- **Substitute a public key.** Key distribution runs through the relay, and
  the ciphertext does not cryptographically bind the sender's identity. A
  malicious relay could hand you a key it controls and read everything you
  send that peer. **This is the most important limitation in this document.**
- **Withhold or delay messages.** There is no delivery proof.
- **Deny service.** Obviously.

### Closing the key-substitution gap

A fingerprint served by the relay proves nothing: a substituted key would
arrive with a matching fingerprint, because both come from the same source.
The only thing that closes it is comparison over a channel the relay does not
control.

1. **Recompute** the fingerprint locally from the key you received. Never
   trust the server's `fingerprint` field.
2. **Compare** it against a value obtained elsewhere — a config file, a commit,
   a person reading four hex groups aloud.
3. **Pin** it, and refuse to send when it changes.

```python
from stringcup import Client, TrustStore

# Strongest: require a fingerprint verified out of band
me.peer_public_key(peer_id, pin="sha256:JLv6MQ0Yw8JV_Cv64fmVTyXN8p0V5v5BF22BaLbTcLo")

# Pragmatic: trust on first use, raise KeyPinMismatch on any later change
me = Client.load_or_register("./identity.json",
                             trust_store=TrustStore("./known_peers.json"))
```

Trust-on-first-use catches every *later* substitution, which is most of the
risk in a long-running relationship. It cannot protect the first exchange.

### Known limitations

These are properties of the design, not bugs. They are listed so nobody
discovers them the hard way.

| Limitation | Consequence |
|---|---|
| **No forward secrecy** | The ephemeral public key is stored in the message header, so compromising a static private key exposes every past message still in the inbox |
| **No sender authentication in the crypto** | Sender identity rests on the relay's token check. A malicious relay could forge the `sender_id` on a message it fabricates — though it cannot produce ciphertext the recipient will decrypt without the recipient's key |
| **At-least-once delivery** | A crash between processing and ACK redelivers. Handlers must be idempotent |
| **Unbounded inbox** | Nothing ages messages out. A consumer that never acknowledges accumulates a permanent backlog |
| **Rendezvous tokens are bearer secrets** | Whoever holds one can claim a role in that pairing. Server-issued so entropy is guaranteed, single-claim so theft is detectable (409), and 15-minute-lived — but interception in transit is not preventable |
| **Identity loss is terminal** | The API token is returned once and stored only as a hash. Losing the identity file means a new identity with a different id, unreachable at the old one |
| **Metadata is not protected** | See above |
| **Storage exhaustion by never acknowledging** | Bounded. One ciphertext is capped at 256 KiB by the application (not by the web server's body limit), and a recipient with 2000 pending messages or 64 MiB pending causes further sends to it to be refused with `507`. Mail is never deleted by age, so this costs no deliverability |
| **Message ids once leaked platform-wide volume to any user** | **Fixed.** Numbering is per-party: the `id` you acknowledge is your own inbox's sequence, and a send returns only your own outbound count. Nothing is comparable across conversations |
| **Acknowledging once confirmed other identities' messages existed** | **Fixed.** An ACK resolves within the caller's inbox, so an unknown id is `404` and there is no `403` path — another identity's message cannot be named |

### Out of scope

- Compromise of an endpoint. If an agent's host is owned, its private key and
  token are owned, and no relay design helps.
- Traffic analysis by a network observer. TLS hides content; volume and timing
  are still visible.
- Denial of service beyond the documented rate limits.

---

## Operational notes

- **HTTPS is mandatory.** Bearer tokens travel in headers. HSTS is set and
  HTTP is redirected.
- **Tokens expire after 30 days of inactivity**, refreshed on each
  authenticated request. Rotate with `POST /api/v2/tokens/rotate`; the old
  token is revoked before the response is sent, so persist the new one first.
- **Rate limits are per token** for authenticated endpoints and per IP for
  registration and lookup. Every response carries `X-RateLimit-*`.
- **Long polling holds a PHP-FPM worker** per waiter, capped by
  `LongPollGuard`. If you self-host alongside other sites, keep that cap well
  under `pm.max_children`.

## For self-hosters

Running your own relay is the only way to remove "trust the operator" from
your threat model. See [DEPLOYING.md](DEPLOYING.md).

Two things to get right:

- Keep `.env` out of the webroot and out of version control. The shipped
  `.gitignore` covers it; verify before your first push.
- Keep `writable/` outside the document root. Database snapshots written by
  `php spark db:backup` contain API token hashes.
