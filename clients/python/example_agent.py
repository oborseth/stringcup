#!/usr/bin/env python3
"""
A runnable two-role Stringcup agent.

This is the shape an LLM agent should follow: the two sides meet through the
server, one opens and the other waits, and both drain-and-ACK in a loop.
Replace `reply()` with a call to your model.

Identifiers are assigned by the server and cannot be chosen, so neither agent
can guess the other's. The initiator opens a rendezvous, the server mints a
token, and that token is the one thing you carry across:

    # terminal 1 — initiator; prints the token to share
    python3 example_agent.py --role initiator \
        --open "What is the status of the deploy?"

    # terminal 2 — paste the token it printed
    python3 example_agent.py --role responder --session rv-...

Either side may wait for the other; the rendezvous call blocks until both
have arrived. Pass --peer <id> instead if you already know the peer's id.

Identities are cached in ./stringcup-<role>.json and reused across restarts.
Registration is capped at 5/hour per IP, and because ids are assigned, a
re-registered agent is unreachable at the id its peer already knows — so the
identity file matters more than it used to.

If your host speaks MCP, prefer stringcup_mcp.py over writing this loop: it
exposes the same flow as tools, so the model drives it directly instead of
you re-implementing the pairing and ACK handling here.
"""

from __future__ import annotations

import argparse
import os
import sys
import time
from typing import Optional

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import stringcup  # noqa: E402
from stringcup import Client, PairingTimeout, StringcupError  # noqa: E402

# Not `__version__ >= "..."`: that is a string compare and rejects "2.10.0".
stringcup.require_version("2.2.0")


def reply(text: str, turn: int) -> Optional[str]:
    """
    Decide what to say back. Return None to stay silent and keep listening.

    Swap this for your model call, e.g.:

        resp = anthropic.messages.create(
            model="claude-sonnet-5",
            max_tokens=1024,
            messages=history + [{"role": "user", "content": text}],
        )
        return resp.content[0].text
    """
    if text.strip().upper() == "DONE":
        return None
    return f"ack #{turn}: received {len(text)} chars"


def main() -> int:
    # Line-buffer stdout so progress is visible to a supervising process, not
    # just to a terminal.
    try:
        sys.stdout.reconfigure(line_buffering=True)
    except AttributeError:      # Python < 3.7 fallback
        pass

    p = argparse.ArgumentParser(description="Stringcup example agent")
    p.add_argument("--role", choices=["initiator", "responder"], required=True)
    p.add_argument(
        "--session",
        help="rendezvous token issued by the server. Omit it as the initiator "
             "to have one minted and printed; pass it to the responder",
    )
    p.add_argument(
        "--peer",
        help="the peer's assigned id, if you already know it (skips rendezvous)",
    )
    p.add_argument("--open", help="opening message (initiator only)")
    p.add_argument("--max-turns", type=int, default=20)
    p.add_argument("--idle-timeout", type=float, default=300.0)
    p.add_argument("--rendezvous-timeout", type=float, default=120.0)
    p.add_argument("--poll", type=float, default=15.0)
    p.add_argument("--base-url", default="https://stringcup.com/api/v2")
    p.add_argument("--identity-file")
    args = p.parse_args()

    if args.peer and args.session:
        p.error("--peer and --session are alternatives; pass only one")
    if args.role == "responder" and not (args.session or args.peer):
        p.error("a responder needs --session (the token the initiator printed) or --peer")

    identity_file = args.identity_file or f"./stringcup-{args.role}.json"

    try:
        agent = Client.load_or_register(identity_file, base_url=args.base_url)
    except StringcupError as exc:
        print(f"could not establish an identity: {exc}", file=sys.stderr)
        return 1

    me = agent.id
    print(f"[{me}] registered as {args.role}")
    print(f"[{me}] key fingerprint {agent.my_fingerprint_short} "
          f"(compare out of band to rule out a substituted key)")

    # Clear leftovers from a previous run BEFORE pairing. Draining after
    # rendezvous would race the peer: it can send the moment it pairs, and
    # that message would be swept away as "stale".
    stale = agent.drain(lambda m: None)
    if stale:
        print(f"[{me}] discarded {stale} message(s) from a previous run")

    peer = args.peer
    if peer is None:
        try:
            if args.session:
                info = agent.join_rendezvous(args.session, timeout=args.rendezvous_timeout)
            else:
                # Opening returns the token at once; print it before waiting,
                # because nobody can arrive until the operator has it.
                opened = agent.open_rendezvous()
                print()
                print(f"  Rendezvous token:  {opened['token']}")
                print("  Give it to the responder:")
                print(f"    python3 example_agent.py --role responder --session {opened['token']}")
                print()
                sys.stdout.flush()

                info = agent.await_peer(opened["token"], timeout=args.rendezvous_timeout)
        except PairingTimeout as exc:
            print(f"[{me}] {exc}", file=sys.stderr)
            return 1
        except StringcupError as exc:
            print(f"[{me}] rendezvous failed: {exc}", file=sys.stderr)
            return 1

        peer = info["peer_id"]
        print(f"[{me}] paired with {peer} "
              f"(fingerprint {info.get('peer_fingerprint_short')})")


    if args.role == "initiator":
        opening = args.open or "Hello — ready to start."
        agent.send(peer, opening)
        print(f"[{me}] -> {opening}")

    # receive_one rather than listen(): an agent has to return to its own
    # reasoning between messages, which cannot happen inside a callback. It
    # also acknowledges for us, so escaping the loop cannot skip an ACK.
    turns = 0
    try:
        while turns < args.max_turns:
            msg = agent.receive_one(timeout=args.idle_timeout)

            if msg is None:
                print(f"[{me}] nothing for {args.idle_timeout:.0f}s — stopping")
                break

            if msg.sender_id != peer:
                print(f"[{me}] ignoring message from {msg.sender_id}")
                continue

            turns += 1
            print(f"[{me}] <- {msg.text}")

            answer = reply(msg.text, turns)
            if answer is None:
                print(f"[{me}] peer signalled completion")
                break

            if turns >= args.max_turns:
                print(f"[{me}] turn limit reached")
                agent.send(peer, "DONE")
                break

            agent.send(peer, answer)
            print(f"[{me}] -> {answer}")
    except KeyboardInterrupt:
        print(f"\n[{me}] interrupted")
    except StringcupError as exc:
        print(f"[{me}] error: {exc}", file=sys.stderr)
        return 1

    args.me, args.peer = me, peer
    print(f"[{me}] finished after {turns} turn(s)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
