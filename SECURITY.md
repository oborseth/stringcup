# Security

## Reporting a vulnerability

Email **security@stringcup.com**. Please do not open a public issue for anything
exploitable.

Include what you did, what happened, and what you expected. A proof of concept
helps but is not required. There is no bounty programme — this is a personal
project — but credit is offered for anything valid.

`security@stringcup.com` forwards to one person, so expect a human-speed reply
rather than a triage system. If something is actively being exploited and you
get no answer, that is a capacity limit, not indifference; say so in the subject
line and it will be read first.

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

#### This is weakest exactly where Stringcup is aimed

Out-of-band comparison assumes somebody is available to do the comparing. For
two fully autonomous agents there usually is not, so the step most likely to be
skipped is the one the whole guarantee rests on. Be clear-eyed about that.

**The rendezvous token does not help, and it is worth understanding why.** It
arrives out of band — an operator carries it — which makes it tempting to treat
as a shared secret the two agents could authenticate against. It is not: *the
relay issues it*, so the relay knows every token. It therefore proves nothing
about a key the relay served.

The consequence, stated plainly: **for a first contact between two autonomous
agents with no human comparison and no pre-shared value, a malicious relay can
substitute both keys and read everything.** Pinning closes every subsequent
exchange; it cannot close that one. Nothing in this implementation currently
does.

What closes it is a secret the relay never sees: a passphrase provisioned out
of band, with each agent checking `HMAC(passphrase, both public keys in sorted
order)` against the value its peer computed. A relay that substituted a key
cannot produce a matching tag without the passphrase. Not implemented today.

**But be clear about which gap that closes.** An agent reviewing this section
pushed back on it, correctly: a passphrase has to be carried by a human at some
point, and the case described above is the one where no human is present. So it
does not fix the unsupervised case — it fixes the *supervised* case, where a
fingerprint comparison was already possible and merely tedious and error-prone.

The honest framing is that a passphrase provisioned **once, at deployment**
would then cover many later pairings with nobody present, which is a real
improvement over comparing hex per pairing. It is not a solution to two agents
that have never shared anything, and there is no known solution to that against
a relay which distributes the keys and issues the meeting token. Treat it as
better ergonomics for an operator who is willing to set something up once, not
as a fix for autonomy.

### Known limitations

These are properties of the design, not bugs. They are listed so nobody
discovers them the hard way.

| Limitation | Consequence |
|---|---|
| **No forward secrecy** | The ephemeral public key is stored in the message header, so compromising a static private key exposes every past message still in the inbox |
| **No sender authentication in the crypto** | Sender identity rests on the relay's token check. A malicious relay could forge the `sender_id` on a message it fabricates — though it cannot produce ciphertext the recipient will decrypt without the recipient's key |
| **At-least-once delivery** | A crash between processing and ACK redelivers. Handlers must be idempotent |
| **Nothing expires** | Only an acknowledgement deletes a message — deliberate, since it is what makes delivery at-least-once and crash-safe. Bounded at the sending end instead; see the storage row below |
| **Rendezvous tokens are bearer secrets** | Whoever holds one can claim a role in that pairing. Server-issued so entropy is guaranteed, single-claim so theft is detectable (409), and 15-minute-lived — but interception in transit is not preventable |
| **Identity loss is terminal** | The API token is returned once and stored only as a hash. Losing the identity file means a new identity with a different id, unreachable at the old one |
| **Metadata is not protected** | See above |
| **Request bodies were logged until 2026-09-12** | **Fixed, and the historical exposure was purged.** Bodies had been logged since the vhost was set up, retained 10 days by rotation: ~385 rendezvous tokens and ~2,300 ciphertexts across four files. All were redacted in place (other vhosts' lines on the same shared log untouched); no log-shipping agent, remote rsyslog or CloudWatch agent was configured, so nothing left the host by that route. **If you operated a copy of the old vhost config, you have the same exposure and the same window.** Host snapshots taken while bodies were being written are outside what a config change can undo |
| **Request bodies must not be logged** | **Fixed.** The host-wide nginx format ended with `$request_body`, so every POST body on this vhost was written to the access log: rendezvous tokens in plaintext, outliving the 15 minutes they are scoped to, and message ciphertext with both party ids for mail the relay had already deleted on ACK. The store honoured "only an acknowledgement deletes"; the log did not, and a later compromise of a recipient's static key would have decrypted messages the relay reported as gone. This vhost now logs with a body-free format. **If you self-host, check your own access-log format before trusting the ACK-deletion guarantee** |
| **Identity lookup confirms existence** | Not mitigated, and not considered exploitable. `GET /identities/{id}` and a send to an unknown id both answer `404`, so anyone already holding an id learns whether it is still registered — a deregistration signal. Assigned ids carry 120 bits, so sweeping for valid ones is infeasible; the disclosure is limited to ids you were already given |
| **Storage exhaustion by never acknowledging** | Bounded. One ciphertext is capped at 256 KiB by the application (not by the web server's body limit), and a recipient with 2000 pending messages or 64 MiB pending causes further sends to it to be refused with `507`. Mail is never deleted by age, so this costs no deliverability |
| **Message ids once leaked platform-wide volume to any user** | **Fixed.** Numbering is per-party: the `id` you acknowledge is your own inbox's sequence, and a send returns only your own outbound count. Nothing is comparable across conversations |
| **Acknowledging once confirmed other identities' messages existed** | **Fixed.** An ACK resolves within the caller's inbox, so an unknown id is `404` and there is no `403` path — another identity's message cannot be named |

### Assurance — what has actually been verified

Stated bluntly, because "it's open source" is not the same as "it's been
reviewed", and a reader has no way to tell them apart from the outside.

| | |
|---|---|
| External security review | **None.** No audit, no penetration test, no third-party cryptographic review |
| Who maintains it | One person, as a personal project. No organisation stands behind it |
| Cryptographic primitives | Not hand-rolled. X25519, HKDF-SHA256 and AES-256-GCM come from `cryptography` (Python) and libsodium / `paragonie/sodium_compat` (PHP) |
| Protocol implementation | Two independent implementations are held in agreement by `clients/python/test_interop.py`, which drives one from the other and asserts both derive identical message keys. **That proves they agree, not that the construction is sound** — two implementations of a bad idea agree perfectly |
| Server-side crypto | There is none to get wrong. The relay validates envelope shape and token ownership and stores opaque bytes |
| Test coverage | Four HTTP suites plus the Python client, MCP and contract suites, run against a live relay. Coverage of behaviour, not a proof of security |

If you are considering this for anything whose disclosure would actually hurt,
read `public/PROTOCOL.md` and the two client implementations yourself, and
treat the absence of review as the material fact it is.

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
