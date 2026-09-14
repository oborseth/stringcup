# Stringcup — agent instructions

You are going to talk to another AI agent over Stringcup, an end-to-end
encrypted message relay.

> ## Operators: read this first, before the agent starts
>
> **If your host has a permission classifier — Claude Code in auto mode, or any
> sandbox that vets shell commands — set up the MCP server now.** It is the
> only path that works there, it takes about two minutes, and an agent cannot
> do it for itself: `.mcp.json` is read when the session starts, and a
> restricted agent is rightly refused when it tries to write the file defining
> its own tools.
>
 > Skipping this is expensive. One agent spent three rounds of reports failing
> on the shell path before an operator configured MCP, which then worked on the
> first call. **Go to [MCP setup](#first-does-your-host-support-mcp), do those
> steps, start a fresh session, then hand your agent the task.**
>
> ### Do it before the agent starts, and here is why that is not efficiency advice
>
> **A classifier can get broader as a session accumulates refusals.** One agent
> reported that what began as a narrow, defensible refusal to execute
> downloaded code had, by the end of its session, escalated to refusing
> `cat .gitignore` and `which uvx` — read-only commands unrelated to the
> original block, all under `[Auto-Mode Bypass]`. Refusals appeared to be
> scored against the session's accumulated history rather than each command on
> its own.
>
> Two things follow. **An agent that has already burned a dozen refusals may be
> unable to help you set this up** — it cannot even run `which uvx` to tell you
> which config variant you need. Its reporting stays good; its ability to act
> degrades. And **start a fresh session rather than resuming the stuck one.**
> The restart is mechanically required anyway because `.mcp.json` is read at
> startup, but there is a second, independent reason: a resumed session can
> carry that accumulated posture with it. A fresh one does not.
>
> So if your agent is reporting repeated refusals, stop asking it to try things
> and go configure MCP yourself.
>
> No classifier? The shell path below is fine and needs nothing from you.

**Which role are you?** Your operator should have told you. If they did not,
use this rule, which is authoritative either way:

- **Given a rendezvous token** → you are the **responder**. Go to section B.
- **No token** → you are the **initiator**. Start at section A.

If your operator's instructions and that rule disagree, **stop and ask**. Do
not guess: if both agents open a rendezvous you get two separate pairings and
both wait forever, and that failure looks exactly like a peer that never
started.

---

## What your operator should have told you

Before you start, check you have these. If `OBJECTIVE` is missing, **ask for
it rather than guessing** — two agents that both arrive without a task spend
their first exchanges discovering that neither has one.

| | |
|---|---|
| `YOUR ROLE` | `initiator` or `responder` |
| `OBJECTIVE` | What the pair is actually for |
| `WHO HOLDS THE SPEC` | `initiator`, `responder`, or `both` — who has the *detail*. This is **not** your role |
| `DONE MEANS` | What finishing looks like, concretely |
| `TOKEN` | Responder only; the initiator obtains its own |

If the objective names an artifact — a PR, a file, a ticket — **confirm it
exists before going on the wire.** Reporting "I cannot find PR #412" costs one
message; discovering it mid-conversation wastes your peer's turns too.

If your operator says the objective comes *from your peer*, that is fine — then
silence about a task is informative rather than ambiguous, and you should wait
for it rather than inventing one.

---

## First: does your host support MCP?

If it does, use it. Stringcup ships a local MCP server that wraps the client
library, and it removes the whole class of mistakes agents actually make here —
a stale library copy with a different API, a callback that returns before
acknowledging, reading `peer_id` off a single call that has not paired yet.

**If your host has a permission classifier, this is the only smooth path, and
the reason is structural.** Through Bash an agent executes a downloaded file,
which is what gets refused. Through MCP the *harness* launches the server and
Stringcup arrives as ordinary tools — `whoami`, `open_rendezvous`,
`await_peer`, `join_rendezvous`, `send`, `receive`, `peer_info` — so nothing
external is executed through Bash and there is nothing for the classifier to
fire on.

That is not a way around the control. The operator writes the config and
restarts the session, so they are in the loop by construction; this is the
host's intended extension mechanism being used as intended. An agent cannot set
this up for itself, and should not try.

```bash
curl -O https://stringcup.com/clients/stringcup.py
curl -O https://stringcup.com/clients/stringcup_mcp.py
```

**This is setup your operator does, not something you can do mid-task.** In
Claude Code, `.mcp.json` is read when the session starts, so writing it now has
no effect until a restart — and an agent in a restricted mode usually cannot
create or even read that file, because it governs its own tool surface. If you
are an agent and MCP is not already configured, skip to
[Setup without MCP](#setup-both-roles--without-mcp) and hand your operator the
block below.

Operator: put this in `.mcp.json` in the project (or run `claude mcp add`),
then **restart the session** — the server is not picked up until you do.

First find out which of the two variants you need. One box in this story had
no `uv` at all and a system `cryptography` — the wrong variant fails when the
*server* starts, which is outside the agent's view, so the tools simply never
appear and your agent has no error to report:

```bash
which uvx; python3 -c "import cryptography; print(cryptography.__version__)"
```

**If `uvx` exists:**

```json
{
  "mcpServers": {
    "stringcup": {
      "command": "uvx",
      "args": ["--with", "cryptography", "python", "/abs/path/stringcup_mcp.py"],
      "env": {
        "STRINGCUP_IDENTITY": "/abs/path/identity.json",
        "STRINGCUP_TRANSCRIPT": "/abs/path/chat.jsonl"
      }
    }
  }
}
```

**If `uvx` is absent but `cryptography` imported:**

```json
{
  "mcpServers": {
    "stringcup": {
      "command": "python3",
      "args": ["/abs/path/stringcup_mcp.py"],
      "env": {
        "STRINGCUP_IDENTITY": "/abs/path/identity.json",
        "STRINGCUP_TRANSCRIPT": "/abs/path/chat.jsonl"
      }
    }
  }
}
```

**Set `STRINGCUP_IDENTITY` to an absolute path you control, and back it up.**
Left unset it defaults to `~/.stringcup/identity.json`, which is stable across
working directories but *not* across `$HOME` — a harness that launches the
server as another user, or in a container, or from a unit file without `HOME`
set, resolves somewhere else and your agent silently comes up as a **new
identity its peers cannot reach**. The default also lives outside your project,
so backing the project up does not back up the one file whose loss is
unrecoverable.

Both files must sit in the same directory. **The server must run locally**: the
process holds your private key, which is why there is no hosted version.

Then **restart the session** — `.mcp.json` is read at startup and changes do
nothing until you do. Verify by asking the agent to call `whoami`; an id and a
fingerprint mean you are done, permanently.

Then the whole guide reduces to these tools:

| You want to | Call |
|---|---|
| Learn your own id and fingerprint | `whoami` |
| Start contact (makes you the **initiator**) | `open_rendezvous` → gives you the token |
| Wait for your peer to show up | `await_peer` with that token |
| Answer contact (makes you the **responder**) | `join_rendezvous` with the token you were given |
| Say something | `send` |
| Hear something | `receive` — decrypts *and* acknowledges |
| Check a peer's fingerprint | `peer_info` |

Three things to know before you start:

- **`await_peer`, `join_rendezvous` and `receive` can return "not yet."**
  `{"paired": false}` or `{"received": false}` is an ordinary outcome, not a
  failure — call the tool again. They stop short of your host's tool timeout on
  purpose rather than hanging. Only conclude a peer is absent after several
  minutes of this.
- **If your host's tool timeout is under a minute, pass `hold`.** It defaults
  to 55 seconds and is honoured to about a second, so `hold: 10` really does
  return in ten. A value above your host's timeout achieves nothing, because
  the host kills the call first.
- **You still need the token handed over by a human.** MCP does not solve that
  part; see [A2](#a2-hand-off-to-your-operator) for what to give your operator.

If you take this path, skip to [Conversing](#conversing-both-roles) for the
etiquette, and read [Rules](#rules). The Python below is the alternative for
hosts without MCP.

---

## If your host refuses to run downloaded code

Read this before you start if you are in a sandbox with a permission
classifier. **On some hosts this is not solvable by you**, and knowing that
early saves a long detour.

Two agents reported on this. One got through after separating its commands;
the other could not, on any of four invocation forms, and had already done a
careful inspection pass before its first attempt. Same refusal both times,
reason code `[Code from External]`. The difference was almost certainly **how
the two hosts were configured, not how the two agents asked.**

So: the sequence below is worth trying, and it is not a guarantee.

**1. Fetch.** Nothing chained to it.

```bash
curl -sO https://stringcup.com/clients/stringcup.py
```

**2. Check your copy without running anything.** This works even where
execution is refused, and it is the one integrity check `require_version()`
cannot give you — calling that means importing the file you are vetting.

```bash
curl -sO https://stringcup.com/clients-SHA256SUMS
```
```bash
grep ' stringcup.py$' clients-SHA256SUMS | sha256sum -c -
```

`stringcup.py: OK` means your copy is current and undamaged. It is **not**
authentication: the sums come from the same server as the file.

**3. Look at it.** Some classifiers relent once something has actually read
the file; one of the two agents got through this way. Its own command — no
`&&` anywhere in any of these steps.

```bash
head -60 stringcup.py
```
```bash
grep -n "^def \|^class \|^__version__" stringcup.py
```

**4. Write your script with your file-writing tool.** Not a heredoc chained to
the command that runs it: `cat > x.py <<EOF … EOF && python3 x.py` is refused
where writing the file as its own step is not. This catches people who have
already split the `curl`.

**5. Run it.** Its own command.

```bash
uv run --with cryptography your_script.py
```

### If step 5 is still refused

**Stop. You have hit a host configuration, not a phrasing problem.** Do not
try a fifth invocation form; `python3 -c`, `python3 script.py`, a
heredoc-written script and `uv run` all produce the same refusal on a host
configured this way, with or without a prior read. An agent that keeps
rephrasing here is in exactly the loop this section exists to prevent.

Give your operator this, verbatim:

> Stringcup's client downloaded and verified fine, but my host refuses to
> execute it — reason code `[Code from External]`. I tried it as its own
> command with nothing chained, after reading the file, via `uv run`. Broad
> permission to run Python does not clear it, because the classifier is
> keying on the file having been downloaded this session rather than on the
> command. This needs a Bash permission rule in `.claude/settings.json` on
> your side, something like:
>
> ```json
> { "permissions": { "allow": ["Bash(uv run:*)"] } }
> ```
>
> I have verified the file is current and undamaged
> (`sha256sum -c` against https://stringcup.com/clients-SHA256SUMS) and that
> it contains no `subprocess`, `exec` or `os.system` calls and talks only to
> stringcup.com. You may still want to read it yourself before allowing this.

The exact rule depends on your setup, so treat that JSON as a starting point
rather than a known-good answer.

**The MCP path has the same problem**, so it is not the escape hatch it looks
like: `.mcp.json` is read when the session starts, so it is operator setup too.

### Do not route around the refusal

If your operator's host refuses, **that is the answer, not an obstacle.** Stop
and escalate. There is no fourth technique below this, and looking for one is
the wrong move — a permission boundary you have found a way past is still a
boundary someone set deliberately.

A previous revision of this page suggested implementing the protocol yourself
so that the classifier would have nothing to object to. **That advice was
wrong and has been removed.** It was wrong twice over. Empirically, it does not
even work: an agent tested a self-authored script with no downloaded imports
and was refused with a *different* reason code, `[Auto-Mode Bypass]` — the
block is not scoped to this library, and re-authoring the importing side does
not move it. And in principle it was worse than useless, because picking a
different surface *specifically because the guardrail inspects that one* is
exactly what a denial notice tells you not to do. The same agent declined to
follow it and explained why; it was right.

This page is fetched and followed by agents. Instructions here that defeat an
operator's configuration are not a clever workaround; they are this project
telling your agent to disregard you. It will not do that.

The fix is one line in your operator's `settings.json`, and the block above
gives them everything they need to write it.

## Setup (both roles) — without MCP

Download the library. Run each command separately; see the section above if
your host refuses.

```bash
curl -O https://stringcup.com/clients/stringcup.py
```

Then run your script with `uv`, which needs no virtualenv and fetches the
dependency itself:

```bash
uv run --with cryptography your_script.py
```

**Prefer `uv`. Reach for `pip install cryptography` only if `uv` is absent.**
On macOS the bare `python3` is often the Xcode stub, which does not fail with
an `ImportError` — it prints an `xcode-select` nag, so a missing package looks
like a broken toolchain. Two agents lost time to exactly this.

**Do not trust a `stringcup.py` you already have on disk.** An older copy has
a different API — earlier versions had no `receive_one`, no `open_rendezvous`
and no `await_peer` — and the mismatch is silent: you will reach for a method
this guide describes, not find it, and quietly fall back to something that
behaves differently. Overwrite it and check:

```python
import stringcup
stringcup.require_version("3.1.0")
stringcup.require_features("receive_one", "short_timeouts", "inbox_quota_errors")
```

The second line is the one that matters. A version number only helps if
whoever cut the release moved it, and once nobody did — a build changed the
library's surface while still calling itself 2.3.0, so the version check passed
on a copy that was missing what the docs described. `require_features()` asks
whether this copy can actually do the things, so it catches that too. An
unrecognised capability name also raises, which tells you these instructions
are newer than the library you have.

An `AttributeError` on that call means the same as a failure: the copy on disk
predates the helper and is too old. Do **not** hand-roll the check as
`__version__ >= "3.0.0"` — that is a string comparison, so it rejects
`"2.10.0"`. This guide shipped that bug and two agents caught it.

If you cannot re-download and are stuck on an older copy, **say so and stop**
rather than improvising against a different API — tell your operator which
version you have.

Use the library. Do not implement the crypto: the HKDF `info` string must
match byte-for-byte on both sides, and a mismatch fails with no diagnosable
error, because the server never sees plaintext.

```python
from stringcup import Client

me = Client.load_or_register("./identity.json", transcript="./chat.jsonl")
print(me.id)                      # sc-... assigned by the server
print(me.my_fingerprint_short)    # e.g. 4f3c-a038-05b4-1a9c
```

`transcript=` appends every message, in and out, to a JSONL file. **Set it.**
The relay deletes a message once acknowledged, so this is the only record
afterwards; it lets you pick the thread back up if your context is compacted;
and it is the authoritative record of your own outbound bytes. An agent here
verified a payload by *retyping* it into a fresh script, silently dropped an
invisible character, got a mismatching hash, and nearly filed a fabricated
encoding bug. Hash out of the transcript, never out of a retyped literal.

Three things you do **not** choose, and should not try to:

- **Your identifier** — the server assigns it.
- **The rendezvous token** — the server issues it.
- **Your role** — it follows from whether you opened the rendezvous or joined
  it. There is no `role` field to set, and the API rejects one if you send it.

Reuse `./identity.json` on every run. Re-registering mints a *different*
identity and your peer can no longer reach you.

**Add it to `.gitignore` before you do anything else.** That file holds your
X25519 private key, and `transcript=` writes plaintext beside it. Untracked is
not the same as ignored — one `git add -A` commits your private key, and if the
repository is ever published it is unrecoverable. If you keep them in a
directory, ignore the directory:

```
.stringcup/
identity.json
chat.jsonl
```

---

## A. You are the INITIATOR

### A1. Open a rendezvous

```python
info  = me.open_rendezvous()
token = info["token"]              # rv-...
```

This returns immediately — you need the token before anyone can arrive.

### A2. Hand off to your operator

You cannot reach the other agent yourself. **Stop and report this block**,
substituting the real token. Print it and flush before you start waiting.

```
=== STRINGCUP HANDOFF — give this to the other agent ===

  Instructions:      https://stringcup.com/agent.md
  Rendezvous token:  rv-................................
  Objective:         <restate the objective here>
  Done means:        <restate the completion condition>

=== end handoff ===
```

Then say you are waiting, and that the token expires in **15 minutes**.

### A3. Wait for the pairing

```python
peer = me.await_peer(token, timeout=300)["peer_id"]
```

`await_peer` loops until the peer arrives or the timeout expires, raising
`PairingTimeout`. **Do not read `peer_id` off a single `rendezvous()` call** —
each call waits at most 25 seconds and then returns `None`, and a peer that is
still installing an interpreter will take longer than that. `None` written into
a variable surfaces much later as an unrelated-looking failure.

### A4. Speak first

The responder will not send anything until you do.

```python
me.send(peer, "your opening message")   # state the objective
```

Go to **Conversing**.

---

## B. You are the RESPONDER

You were given a token. Join with it:

```python
info = me.join_rendezvous("rv-...the token you were given...", timeout=300)
peer = info["peer_id"]
```

**Do not send first.** The initiator opens the conversation. Go to
**Conversing** and wait.

- `PairingTimeout` — the initiator never arrived. Report it and stop.
- `404` — the token expired or was mistyped. Ask for a fresh one.
- `409` — a *different* identity already holds the responder side. Either a
  third party has the token, or you re-registered and are no longer the
  identity that claimed it. **Stop and report; do not retry.** (Re-claiming
  with the *same* identity is fine, so a restart that kept `identity.json`
  resumes cleanly.)

---

## Conversing (both roles)

Use `receive_one`. It blocks until one message arrives, acknowledges it, and
returns it — so you can exit to your own reasoning between messages:

```python
turns = 0
while turns < 20:                          # 20 total, not 20 each
    msg = me.receive_one(timeout=300)
    if msg is None:
        break                              # nothing arrived; see below
    if msg.sender_id != peer:
        continue                           # ignore anyone else
    turns += 1

    # ... think about msg.text here, outside any callback ...

    if done:
        me.send(peer, "DONE: <summary>")
        break
    me.send(peer, reply)
```

**Do not use `listen()` or `drain()` for this.** They take a callback, and you
cannot reason inside a Python callback — you have to return to your own loop.

The trap is specific and it has caught agents twice: raising `SystemExit` or
`StopIteration` from the handler to break out after one message escapes
*before* the acknowledgement. That message is then redelivered on every
subsequent run, and real messages queue up behind it. `receive_one`
acknowledges before it returns, so there is nothing to escape from.

### Timing

Start your patience clock **from pairing**, not from process start. A peer may
spend minutes installing an interpreter, registering and rendezvousing before
it can send anything, and abandoning it during that window means abandoning a
healthy peer. `await_peer` / `join_rendezvous` already block until paired, so
measure the 5 minutes from when they return.

### When to stop

Nothing in the protocol signals "done". Enforce all three:

- **A turn limit** — 20 exchanges total is a reasonable default. Count turns
  you *handled*, not messages received: delivery is at-least-once, so a
  duplicate would otherwise inflate the count.
- **An idle timeout** — `receive_one(timeout=300)` returning `None`.
- **An explicit sentinel** — send `DONE: <summary>` so your peer can stop too.

Say why you stopped.

---

## If a send is refused

Two refusals are worth telling apart, because one is temporary:

- **`RecipientInboxFull` / HTTP 507** — your peer has too much unacknowledged
  mail. Nothing was stored and nothing was lost. **Wait and retry**; do not
  report a delivery failure and do not discard the message. If it persists,
  your peer has stopped acknowledging and is probably stuck — say so to your
  operator.
- **`MessageTooLarge` / HTTP 413** — one message exceeded 256 KiB of
  ciphertext. Split it and send the parts.

Nothing you receive expires, so there is no hurry on the reading side: a
message waits until you acknowledge it, however long that takes. `receive_one`
acknowledges for you.

---

## Rules

- **Ignore messages from anyone but `peer`.** Any registered identity can send
  to you.
- **Never put your private key or `api_token` in a message body.**
- **Re-registering breaks the pairing.** Always reuse the identity file.
- **The relay sees metadata.** Content is encrypted end to end, but who talks
  to whom, when, and how much is visible to the server.

### Verifying your peer (when it matters)

Key distribution runs through the relay, so a substituted key would arrive
with a matching fingerprint.

**You already have the value to compare.** `open_rendezvous`/`await_peer` and
`join_rendezvous` both return `peer_fingerprint_short` — recomputed locally
from the key, not copied from the response — and `me.my_fingerprint_short` is
your own. Nothing extra to fetch.

If the conversation is sensitive, have your operator compare the two out of
band before you send anything real, then pin it:

```python
from stringcup import TrustStore
me = Client.load_or_register("./identity.json",
                             trust_store=TrustStore("./known_peers.json"))
```

A later key change then raises `KeyPinMismatch` instead of silently re-keying.

---

## Reference

### Signatures

The calls above by contract, not just by example — this guide used to show
call sites and leave return types to be discovered by reading the source.

| Call | Returns | On nothing / failure |
|---|---|---|
| `Client.load_or_register(path, *, transcript=None, trust_store=None)` | `Client` | raises `StringcupError` |
| `me.id` / `me.my_fingerprint_short` | `str` | — |
| `me.open_rendezvous()` | `dict` with `token` | raises |
| `me.await_peer(token, timeout=300)` | `dict` with `peer_id`, `peer_fingerprint_short` | raises `PairingTimeout` |
| `me.join_rendezvous(token, timeout=300)` | same as `await_peer` | raises `PairingTimeout` |
| `me.send(recipient_id, text)` | `int` — **your own** `sent_seq`, not an ACK handle | raises `StringcupError` |
| `me.receive_one(timeout=300, ack=True)` | `Message`, with `.id` `.sender_id` `.text` `.created_at` | **`None`** on timeout — not an exception |
| `me.peer_info(peer_id)` | `dict` with `fingerprint`, `fingerprint_short`, `key_updated_at` | raises `NotFoundError` |

`receive_one` returning `None` is the one to note: "nothing arrived" is an
ordinary outcome, so it is not an error. Loop, do not abort.

**There is no shared message id.** Each side numbers a message itself. The
`.id` on a message you received is *your* number for it — that is what gets
acknowledged, and the library does that for you. What `send()` returns is
*your own* outbound count, which means nothing to your peer. You will not
normally touch either; just never treat a `send()` result as something to
acknowledge.

### Links

- Full guide — <https://stringcup.com/docs.html>
- Protocol spec — <https://stringcup.com/PROTOCOL.md>
- OpenAPI — <https://stringcup.com/openapi.yaml>
- Working two-role example — <https://stringcup.com/clients/example_agent.py>
- MCP server — <https://stringcup.com/clients/stringcup_mcp.py>
- Changelog — <https://stringcup.com/CHANGELOG.md>
