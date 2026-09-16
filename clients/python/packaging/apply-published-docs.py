#!/usr/bin/env python3
"""
Switch the published docs to the PyPI install path — AFTER the package is live.

WHY THIS IS A SCRIPT AND NOT A COMMIT. The relay serves straight out of the
working tree, so editing `setup.md` publishes it instantly. Writing
`pip install stringcup` before the package exists would put an instruction that
404s in front of every reader, which is the exact failure this project has a
rule about: *a reader cannot check a claim against a file that 404s.*

So the edits are staged here and gated on reality:

    python3 apply-published-docs.py --check     # is it actually on PyPI?
    python3 apply-published-docs.py --dry-run   # what would change
    python3 apply-published-docs.py             # apply (refuses if not live)

It verifies the version on PyPI matches `__dist_version__` before touching a
file, so it cannot be run too early, and it is idempotent so it cannot be run
twice to bad effect.
"""

import json
import os
import re
import sys
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", "..", ".."))
PKG = "stringcup"


def dist_version():
    src = open(os.path.join(HERE, "..", "stringcup.py"), encoding="utf-8").read()
    return re.search(r'__dist_version__ = "([^"]+)"', src).group(1)


def pypi_versions():
    """Versions PyPI actually serves, or None when the project is absent."""
    try:
        with urllib.request.urlopen(
            "https://pypi.org/pypi/%s/json" % PKG, timeout=20
        ) as r:
            return sorted(json.load(r)["releases"])
    except urllib.error.HTTPError as exc:
        if exc.code == 404:
            return None
        raise


#: (path, old, new). Every `old` must appear exactly once, or the anchor has
#: drifted and applying blindly would corrupt the page.
def edits(version):
    pip = "pip install stringcup"
    uvx = "uvx --from stringcup stringcup-mcp"
    return [
        # app/Views/home.php -- THE HOMEPAGE WAS NOT ON THIS LIST, and that is why
        # it kept curl instructions after every other surface had moved. It is the
        # front door: the first page anyone reads, and the only one a visitor reaches
        # without being sent.
        #
        # Only the "Sixty seconds" block is a publish-switch concern. The hero block
        # deliberately is NOT: it is one prompt plus a URL, and the agent tells the
        # operator what to configure, so it says nothing about how the server is
        # installed and does not change when the package ships.
        (
            "app/Views/home.php",
            '  <p>\n    Using an MCP host? Skip this — register\n    <a href="/clients/stringcup_mcp.py">the MCP server</a> instead and the code below\n    becomes a tool call. Otherwise:\n  </p>\n  <pre><code>curl -O https://stringcup.com/clients/stringcup.py\nuv run --with cryptography your_script.py   # or: pip install cryptography</code></pre>\n',
            '  <p>\n    Using an MCP host? Skip this — do the one command above instead, and the code\n    below becomes a tool call. Otherwise, for a script:\n  </p>\n  <pre><code>pip install stringcup          # library + a stringcup-mcp console script</code></pre>\n  <p style="margin:-.4rem 0 1rem">\n    The single file is still served if you would rather read one file than\n    install a package &mdash; <code>curl -O\n    https://stringcup.com/clients/stringcup.py</code>, then\n    <code>uv run --with cryptography your_script.py</code>. The package ships\n    that identical file.\n  </p>\n',
        ),
        # setup.md — PASTE 1 becomes one command instead of five steps.
        (
            "public/setup.md",
            """> ```bash
> curl -O https://stringcup.com/clients/stringcup.py
> curl -O https://stringcup.com/clients/stringcup_mcp.py
> ```""",
            """> **One command, if you have `uv`:**
>
> ```bash
> claude mcp add stringcup -- %s
> ```
>
> That is the whole of PASTE 1 on a host with `uv` — no download, no path to
> get right, no variant to choose, and nothing to re-copy when a new version
> ships. Otherwise `%s` and use the `python3` variant below.
>
> Installing by hand still works and is unchanged:
>
> ```bash
> curl -O https://stringcup.com/clients/stringcup.py
> curl -O https://stringcup.com/clients/stringcup_mcp.py
> ```"""
            % (uvx, pip),
        ),
        # The client reference leads with the package, keeps the single file.
        (
            "clients/python/README.md",
            """```bash
curl -O https://stringcup.com/clients/stringcup.py
uv run --with cryptography your_script.py
```""",
            """```bash
pip install stringcup          # library + `stringcup-mcp` console script
```

Or keep it to one file with no install at all — the module is published
standalone and the package ships the identical file:

```bash
curl -O https://stringcup.com/clients/stringcup.py
uv run --with cryptography your_script.py
```""",
        ),
        (
            "README.md",
            """curl -O https://stringcup.com/clients/stringcup.py
uv run --with cryptography your_script.py   # or: pip install cryptography""",
            """pip install stringcup                       # library + MCP server
# or, one file and no install:
curl -O https://stringcup.com/clients/stringcup.py
uv run --with cryptography your_script.py   # or: pip install cryptography""",
        ),
        (
            "public/docs.md",
            """**In a hurry?** `curl -O https://stringcup.com/clients/stringcup.py` —""",
            """**In a hurry?** `pip install stringcup`, or
`curl -O https://stringcup.com/clients/stringcup.py` —""",
        ),
        (
            "public/llms.txt",
            """- [setup.md](https://stringcup.com/setup.md): **Operator setup — one config
  file and a restart, once per machine.**""",
            """- **Install:** `pip install stringcup` gives the library and a
  `stringcup-mcp` console script, so an MCP entry needs no file path. Both
  modules ship in ONE distribution, which means they cannot be partially
  upgraded — the drift `whoami`'s `versions_note` reports is impossible for a
  pip install. The single files remain fetchable for anyone who would rather
  read one file than install a package.
- [setup.md](https://stringcup.com/setup.md): **Operator setup — one config
  file and a restart, once per machine.**""",
        ),
        (
            "app/Controllers/Api/V2/IndexController.php",
            """                'python_client' => rtrim(base_url(), '/') . '/clients/stringcup.py',""",
            """                'python_client' => rtrim(base_url(), '/') . '/clients/stringcup.py',
                'pypi' => 'https://pypi.org/project/stringcup/',
                'pypi_note' => 'pip install stringcup -- library plus a stringcup-mcp '
                    . 'console script, both modules in ONE distribution so they cannot '
                    . 'be partially upgraded. The single files stay fetchable for '
                    . 'anyone who would rather read one file than install a package.',""",
        ),
    ]


def main():
    mode = sys.argv[1] if len(sys.argv) > 1 else ""
    version = dist_version()
    released = pypi_versions()

    print("local __dist_version__ : %s" % version)
    print("on PyPI                : %s" % (released if released else "NOT PUBLISHED"))

    if mode == "--check":
        ok = released is not None and version in released
        print("ready to apply         : %s" % ("yes" if ok else "no"))
        return 0 if ok else 1

    if mode != "--dry-run":
        if released is None:
            print("\nREFUSING: %s is not on PyPI. Publish first." % PKG)
            return 1
        if version not in released:
            print("\nREFUSING: PyPI has %s but this tree is %s. Versions must match."
                  % (released, version))
            return 1

    changed = failed = skipped = 0
    for rel, old, new in edits(version):
        path = os.path.join(ROOT, rel)
        text = open(path, encoding="utf-8").read()

        if new in text:
            print("  already applied : %s" % rel)
            skipped += 1
            continue
        if text.count(old) != 1:
            print("  ANCHOR DRIFTED  : %s (found %d)" % (rel, text.count(old)))
            failed += 1
            continue

        print("  %s : %s" % ("would change" if mode == "--dry-run" else "changed     ", rel))
        if mode != "--dry-run":
            open(path, "w", encoding="utf-8").write(text.replace(old, new, 1))
        changed += 1

    print("\n%d changed, %d already applied, %d drifted" % (changed, skipped, failed))
    if failed:
        print("Fix the drifted anchors before applying -- a blind replace would "
              "corrupt a published page.")
        return 1
    if mode != "--dry-run" and changed:
        print("\nNow run: php spark clients:checksums && tests/run_all.sh")
    return 0


if __name__ == "__main__":
    sys.exit(main())
