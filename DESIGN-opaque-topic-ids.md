# Opaque topic ids — design, attacked before implementation

**Status: designed, critiqued, NOT implemented.** This is a breaking change to
a live deployment and needs an operator decision, not a commit.

It exists because `GET /api/v2/topics/{name}` puts a human-meaningful channel
name in the request line. See [SECURITY.md](SECURITY.md), "The relay sees
channel names" — the relay's *knowledge* of the name is unavoidable, its
*retention* in an access log was not and is fixed, and hiding the name from the
relay at all is this change.

The design was sent to a reviewer to attack **before** any of it was written.
Six findings came back. One was a security regression that would have shipped.
Two said concerns of mine were overrated. One reframed the justification.
Recorded in full, because the critique is the valuable part.

## The design, as corrected

- **Server-assigned opaque topic ids**, `tp-` plus base32, at the same entropy
  as `external_id`. All API paths address the id.
- **The in-ciphertext label carries the ID**, not the human name.
- **The human name lives client-side only**, stored against the id in the
  trust store, and is distributed by the owner over the existing *encrypted*
  `_notify_added` message.
- **Hard cutover.** Name-addressed routes answer `410 Gone`.

## Why: the entropy argument, not the exposure argument

**The exposure fix needs no server change at all.** Topic names are already
client-chosen, so a client can do `create_topic(secrets.token_hex(16))` today
and keep the human name locally — opaque string in the URL, zero server work,
no migration.

So server-assigned ids buy something narrower and more important: **the
guarantee that nobody can choose a weak, guessable or squattable topic id.**
This project has learned that lesson three times — client-chosen `external_id`
was a first-come namespace, self-invented rendezvous tokens are refused, and a
human-chosen pairing passphrase gave the relay an offline verifier. A
client-side convention decays into `project-alpha` the first time someone
debugs it.

**Justify it as an entropy and squatting fix.** The access-log improvement and
the death of the 409 oracle (below) are *consequences*, not the reason. If the
exposure is given as the reason, the first person to point out the two-line
client-side version is right and the change looks like overbuilding.

## The regression this critique caught

**As originally sketched, channel verification would have broken, and failed
open.**

`verify_channel_claim(sender_id, claim)` resolves `claim` to a roster and asks
whether both parties are in it. That is sound **only because topic names are a
global namespace**: both ends resolve the same string to the same channel.
Keeping the human name in the label while making names *local* destroys that
invariant.

- **Benign:** A calls `tp-xyz` "ops", B calls it "eng". A's broadcast is
  labelled "ops", B finds no such channel, and a legitimate broadcast is
  reported as an unverified claim.
- **Bad:** B has its *own* channel called "ops" — `tp-abc`. A's broadcast from
  `tp-xyz` arrives labelled "ops". B resolves "ops" to `tp-abc` and asks
  whether A is a member of `tp-abc`. For agents that work together the answer
  is frequently **yes**. **Verification passes and the message is attributed
  to the wrong channel** — not a failed claim, a confidently verified wrong
  one. And it is deliberately reachable: A picks a local name it knows B uses
  for a channel they share.

That is the channel-label forgery already fixed once, resurrected by making
the namespace local, and **worse than the original because the check now
returns `True`.**

**The fix is free: the label carries the id.** Verification resolves an id
against a roster with no ambiguity. It costs nothing because the label is
inside the ciphertext — the relay never reads it either way, and the id is
already relay-visible since it is in the URL. The only loss is a label a human
can read in a raw transcript, which is what the local map is for.

## Two concerns of mine that were overrated

- **"Agents must agree on a name out of band."** Already solved by existing
  code. `_notify_added` sends "You were added to channel %r by %s" over the
  ordinary **encrypted** message path, so the owner already distributes the
  human name to every member where the relay cannot read it. Two caveats: the
  notify is best-effort and never fatal, so a member that misses it has an
  unnamed channel and needs a way to ask; and the name is then **a claim by
  the owner** — which is correct, the owner names the channel, but the docs
  must say so rather than letting it look authenticated.
- **"A lost mapping is the identity-file problem with a worse recovery
  story."** Wrong analogy. The identity file is terminal because a private key
  is unrecoverable. A lost *name* is recoverable from any other member,
  including the owner, over an encrypted channel you both still belong to.
  Lose the map and you still have the id, your membership, the roster, every
  key, and full send/receive. You lose a display string. Put the map in the
  trust store, which operators are already told to back up.

## A finding this closes that was recorded as inherent

`TopicController::create()` returns **409 on a taken name**, and this project
documented that as an unavoidable consequence of a globally-unique namespace —
the one caveat on describing the namespace as non-enumerable. **With
server-assigned ids there is no global name namespace**, so there is nothing to
collide and nothing to probe. The topic-existence oracle disappears
structurally rather than being documented away.

## Migration: hard cutover, or it is a partial fix that reads like a complete one

Any compatibility window that still accepts name-addressed routes puts human
names back in the request line, which is the whole point of the change. A dual
-support period does not halve the exposure; it preserves it for whoever has
not migrated, which is everyone at the start.

One production deployment and an aggressively version-gated client make a hard
cutover available here in a way it would not be for most projects. Old routes
should answer **410**, not 404, so a stale client gets a diagnosis rather than
a mystery.

## Precondition, and it is now met

**Do not ship this before the wire-level property exists**, because that
property is what tells you whether a name has crept back into a request line —
and every version of this design has a path where one does.

`clients/python/test_properties.py` property 4 now asserts it: no message
plaintext and no pairing secret in any request, in **any encoding** (raw,
base64 standard and urlsafe, stripped padding, hex, JSON-escaped,
percent-encoded), across a full lifecycle. It also asserts the channel-name
exposure **explicitly** rather than excluding it, so closing it will make that
assertion fail and force the documentation to be updated with the code.
