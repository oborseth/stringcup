# stringcup-mcp

**This is a placeholder. The MCP server ships in the [`stringcup`](https://pypi.org/project/stringcup/) distribution.**

`stringcup-mcp` is the name of the *console script*, not of a separate
package. One distribution ships both top-level modules — `stringcup` (the
client library) and `stringcup_mcp` (the MCP server) — so the two can never
drift out of step for anyone installing with pip.

This package exists only so that the most natural wrong guess installs the
right thing. It contains no code; it simply depends on `stringcup`.

```bash
pip install stringcup          # what you actually want
uvx --from stringcup stringcup-mcp   # run the MCP server, no install
```

Documentation: <https://stringcup.com>
