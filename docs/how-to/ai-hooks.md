# AI Agent Hooks (Claude Code)

AI coding agents such as **Claude Code** can run a *stop hook* — a command executed
when the agent finishes a turn. If the command blocks, the agent is told *why* and
keeps working instead of handing back unfinished, failing code.

GitHooks closes this loop natively:

- **Input side** — [`--fast-dirty`](../execution-modes.md#fast-dirty-mode-fast-dirty)
  analyses the unified working tree (modified + untracked files), exactly the set an
  agent touches without staging.
- **Output side** — `--format=claude-code` emits the Claude Code stop-hook protocol
  directly, so no wrapper script is needed.

## The `--format=claude-code` contract

| Outcome | stdout | Exit code |
|---|---|---|
| All jobs pass | *(empty)* | `0` |
| One or more jobs fail | `{"decision":"block","reason":"## job\n<output>…"}` | `0` |
| Configuration / invocation error | error message on **stderr** | `1` |

!!! important "Why a failing run still exits `0`"
    The Claude Code stop-hook protocol only honours the `{"decision":"block"}` JSON
    when the process **exits 0**. A non-zero exit makes Claude Code surface stderr and
    treat it as a native, unexplained block. So `--format=claude-code` always exits 0
    on a lint failure and signals the block through stdout instead. A genuine
    *configuration* error (bad `githooks.php`, undefined flow) still exits `1` — that
    is a tooling problem for you to fix, not a reason to block the agent.

The `reason` aggregates the plain-text output of **every** failed job under a Markdown
`## <jobName>` heading, `\n\n`-separated. ANSI colours are stripped and the payload is a
single valid JSON line, so the agent receives clean, readable feedback.

## Configure the Claude Code stop hook

Add a single command to your `~/.claude/settings.json` (or the project-level
`.claude/settings.json`):

```json
{
  "hooks": {
    "Stop": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "vendor/bin/githooks flow qa --fast-dirty --format=claude-code"
          }
        ]
      }
    ]
  }
}
```

When the agent stops:

- **Working tree is clean of QA issues** → the command prints nothing and exits 0.
  Claude Code ends the turn normally.
- **A tool reports problems** → the command prints the `block` JSON. Claude Code reads
  the `reason`, keeps the turn open, and the agent fixes the reported issues.

!!! tip "Before vs after"
    Previously this required a per-repo bash wrapper that captured stdout + exit code
    and re-emitted the JSON. With `--format=claude-code` that wrapper disappears — the
    `settings.json` command is the whole integration.

## Works with `flow`, `flows` and `job`

`--format=claude-code` is available on all three execution commands, so you can scope
the stop hook to a single fast job instead of the whole flow:

```bash
# Whole QA flow over the working tree
githooks flow qa --fast-dirty --format=claude-code

# A single fast job
githooks job phpstan_src --fast-dirty --format=claude-code

# A meta-flow of several flows
githooks flows pre-commit --fast-dirty --format=claude-code
```

## Other agents

The stop-hook protocol is **not** standardised across IDEs, so the format is named after
its consumer: `claude-code`. When other agents (Cursor, Cline, …) stabilise a comparable
protocol, GitHooks will add `--format=cursor`, `--format=cline`, etc. — each opt-in, each
with its own shape. There is deliberately **no** auto-detection from environment
variables: the format you want is always explicit.

## See also

- [Output Formats](output-formats.md) — the full `--format` reference.
- [Execution modes](../execution-modes.md#fast-dirty-mode-fast-dirty) — what `--fast-dirty` analyses.
