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
