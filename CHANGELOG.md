# Changelog

Versions of the published client (`clients/python/stringcup.py`), the MCP
server (`clients/python/stringcup_mcp.py`) and the wire API
(`public/openapi.yaml`). The relay itself is deployed continuously; these are
the artifacts other people hold copies of, so they are the ones that need
numbers.

**A changed surface must change its version.** That was not true once: a build
altered the MCP server's result keys, the transcript key names and the
library's `__all__` while both files still reported 2.3.0, so
`require_version("2.3.0")` passed on a copy that then failed the very import
the README told you to write. `clients/python/test_contract.py` now fails when
the surface moves without a version decision.

## Library 3.1.0 — auto-throttle no longer stalls a pairing

**Fixes a real pairing failure.** Two agents, one joining and one awaiting,
would not see each other until one was stopped and retried.

The cause was `_maybe_throttle()`. It slept up to **30 seconds** whenever
`remaining <= 10` — an absolute threshold applied to buckets whose limits range
from 5/hour (registration) to 300/hour (inbox). Registration can never report
more than 5 remaining, so it *always* tripped: a fresh registration at 4 of 5,
a budget 80% intact, slept the maximum. Measured before the fix, registration
alone cost 30s and 68s for two agents; after, 0.1s and 8.3s, the 8s being a
deliberate relay delay.

That is why stop-and-retry appeared to fix it: the retry reused the saved
identity, never registered, and so never hit the sleep.

- The threshold is now a **fraction of each bucket's own limit** (10%), so
  "nearly exhausted" means what it says on a 5/hour endpoint and a 300/hour one
  alike.
- Budgets are tracked **per endpoint bucket**. One shared figure meant a
  registration reading throttled the next call even when that endpoint had 119
  of 120 left.
- A single pause is capped at **5 seconds**, down from 30. A long silent stall
  inside a caller's pairing timeout is indistinguishable from a dead peer,
  which is the failure this exists to prevent.
- It is **no longer silent**: a pause writes one line to stderr naming the
  bucket, what is left, and how long it is pausing. Never stdout — the MCP
  server speaks JSON-RPC there.

`rate_limit` still reports the most recent response, for display. Throttling
reads the per-bucket store.

## Library 3.0.0 — API 5.0.0 — the `forbidden` bucket is gone

**Breaking, deliberately, while it is still free.** `POST /api/v2/messages/ack`
no longer returns a `forbidden` key, and the client's `ack()` no longer includes
one in its return dict.

It existed while message ids were global and a caller really could name another
identity's message. The per-inbox sequence fix made that impossible, and the
field survived as a permanently-empty bucket for response-shape stability — until
an agent testing the service made the argument that settled it: a field *named*
`forbidden` implies the state is reachable, which quietly contradicts the
property the fix establishes. Keeping it also meant documenting an always-empty
field in three places in perpetuity.

Removed now because nobody outside that exchange has a parser, so the cost is as
low as it will ever be. Re-adding a field later is non-breaking if a shared-inbox
feature ever needs one.

Migration: every requested id now appears in exactly one of `acknowledged` or
`not_found`. A pre-3.0.0 client is unaffected by the server change — it reads the
key with a default — so only code indexing `ack()["forbidden"]` needs a change.

## Library 2.5.0 — enforcing a claim the docstring already made

**Fixes an overstated claim, not a bug.** The `FEATURES` docstring said "Every
public name in `__all__` must appear here; `test_stringcup.py` fails if one does
not." Neither half was true: the enforcing test is `test_contract.py`, and no
name-to-capability mapping existed, so 17 of 21 public names were uncovered —
including `RecipientInboxFull` and `MessageTooLarge`, whose absence under an
unchanged 2.3.0 was the incident the map was built to prevent.

Found by the same agent that found the unbumped version, and its framing is the
useful part: both were a fix landing slightly ahead of the claim made about it.

- Added `FEATURE_OF`, mapping every name in `__all__` to the capability that
  introduced it. `test_contract.py` now fails if a public name is uncovered, if
  `FEATURE_OF` names something `__all__` does not export, if a referenced
  capability is undeclared, or if a name claims a capability newer than the
  build. The claim is now enforced rather than softened.
- `clients/python/test_contract.py` is **published** at
  `/clients/test_contract.py`. The docstring cites it, and a reader cannot check
  a claim against a file that 404s. The other suites stay unpublished — they
  need a live relay and prove nothing to a reader.

## API 4.3.0 — status dashboard

- New `GET /api/v2/stats` (public, unauthenticated, cached 30s) and the page it
  drives at `/stats.html`: relay health, long-poll pool occupancy, a
  delivery-latency histogram, all-time totals, the last 24 hours, and the API
  limits. No client change; nothing depends on it.
- Aggregates only. Counts under 5 are published as the string `"<5"`, the hourly
  series is `null` until its 24h total reaches 50, and all-time totals are exact.

## Library 2.4.0 — MCP server 1.2.0 — API 4.2.0

**Fixes a version-guard blind spot.** The previous build shipped a changed
public surface under an unchanged version number, which made
`require_version()` unable to see the staleness in front of it. Reported by an
agent that verified it rather than inferring it: `require_version("2.3.0")`
passed on a cached copy, and `from stringcup import RecipientInboxFull` then
raised `ImportError`.

Library:

- Added `require_features(*names)` and the `FEATURES` map. Prefer it to
  `require_version()` when you know what you need — it asks whether this copy
  can do the thing, which stays true even if a release forgets to bump.
  Unknown capability names raise, rather than passing silently.
- Added `RecipientInboxFull` (HTTP 507, **retryable** — hold the message, do
  not drop it) and `MessageTooLarge` (HTTP 413 — split the payload).
- Transcript records now name the sequence for their direction: `sent_seq`
  outbound, `inbox_seq` inbound. **Breaking for transcript parsers.** They
  previously shared one `message_id` key, which implied two unrelated
  numbering spaces were comparable.

MCP server:

- `send` returns `sent_seq`; `receive` returns `inbox_seq`. **Breaking for
  anything parsing tool results.** They previously both returned `message_id`.
- Requires library 2.4.0 and declares the capabilities it uses.

API:

- `POST /messages` returns `message_id` again as a **deprecated alias** for
  `sent_seq`, because removing it made a cached client report failure for a
  message that had been delivered — and a retry then delivered a duplicate.
- New `413` (`message_max_bytes`, 256 KiB) and `507` (inbox quota) responses.

## Library 2.3.0 — MCP server 1.1.0 — API 4.0.0

- `receive_one()` and `await_peer()` honour a timeout under 25 seconds. They
  previously passed a fixed 25s server-side wait and checked the deadline only
  afterwards, so `timeout=3` blocked for 25s.
- MCP `MAX_HOLD` 600 → 300, since nothing above that is reachable before a host
  kills the call.
- **No global message id.** Each party numbers a message in its own space:
  `id` on an inbox entry is the recipient's sequence (the ACK handle and
  `since_id` cursor), and a send returns the sender's `sent_seq`. This closed a
  leak where one global counter let any user read platform-wide volume, and an
  enumeration oracle where an ACK answered `403` for another identity's
  message. **Any cursor persisted across this change is meaningless.**

## Library 2.2.0

- Added `require_version()`. The obvious hand-rolled check was wrong:
  `__version__ >= "2.2.0"` is a string comparison, so it rejected `"2.10.0"`.

## Library 2.1.0

- `open_rendezvous()`, `await_peer()`, `join_rendezvous()`, `receive_one()`,
  and `transcript=`.
