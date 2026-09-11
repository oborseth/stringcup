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
"""

from __future__ import annotations

import argparse
import os
import sys
import time
from typing import Optional

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from stringcup import Client, StringcupError  # noqa: E402


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
        token = args.session
        deadline = time.monotonic() + args.rendezvous_timeout

        while peer is None:
            try:
                # Mint with wait=0 so the token is printed immediately. Left
                # at the default the first call would block for the whole
                # long-poll window before revealing the one value the peer
                # needs in order to show up at all.
                info = agent.rendezvous(args.role, token, wait=0 if token is None else 25)
            except StringcupError as exc:
                # 409: someone else holds this role under the token.
                print(f"[{me}] rendezvous failed: {exc}", file=sys.stderr)
                return 1

            if token is None:
                token = info["token"]
                print()
                print(f"  Rendezvous token:  {token}")
                print("  Give it to the responder:")
                print(f"    python3 example_agent.py --role responder --session {token}")
                print()
                # stdout is block-buffered when redirected, so without this an
                # orchestrator capturing output would not see the token until
                # the process exits — by which time the pairing has timed out.
                sys.stdout.flush()

            peer = info.get("peer_id")
            if peer is None and time.monotonic() > deadline:
                print(f"[{me}] peer did not arrive within "
                      f"{args.rendezvous_timeout:.0f}s", file=sys.stderr)
                return 1

        print(f"[{me}] paired with {peer} "
              f"(fingerprint {info.get('peer_fingerprint_short')})")

    args.me, args.peer = me, peer

    if args.role == "initiator":
        opening = args.open or "Hello — ready to start."
        agent.send(args.peer, opening)
        print(f"[{args.me}] -> {opening}")

    state = {"turns": 0, "done": False}

    def handle(msg):
        if msg.sender_id != args.peer:
            print(f"[{args.me}] ignoring message from {msg.sender_id}")
            return

        state["turns"] += 1
        print(f"[{args.me}] <- {msg.text}")

        if state["turns"] >= args.max_turns:
            print(f"[{args.me}] turn limit reached")
            agent.send(args.peer, "DONE")
            state["done"] = True
            return

        answer = reply(msg.text, state["turns"])
        if answer is None:
            print(f"[{args.me}] peer signalled completion")
            state["done"] = True
            return

        agent.send(args.peer, answer)
        print(f"[{args.me}] -> {answer}")

    try:
        agent.listen(
            handle,
            poll_interval=args.poll,
            idle_timeout=args.idle_timeout,
            stop=lambda: state["done"],
        )
    except KeyboardInterrupt:
        print(f"\n[{args.me}] interrupted")
    except StringcupError as exc:
        print(f"[{args.me}] error: {exc}", file=sys.stderr)
        return 1

    print(f"[{args.me}] finished after {state['turns']} turn(s)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
