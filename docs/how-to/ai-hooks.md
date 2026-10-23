# AI Agent Hooks (Claude Code)

AI coding agents such as **Claude Code** can run a *stop hook* — a command executed
when the agent finishes a turn. If the command blocks, the agent is told *why* and
keeps working instead of handing back unfinished, failing code.

Alfil closes this loop natively:

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
    *configuration* error (bad `alfil.php`, undefined flow) still exits `1` — that
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
            "command": "vendor/bin/alfil flow qa --fast-dirty --format=claude-code"
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

## Claude Code's side of the hook

!!! note "This section is about Claude Code, not Alfil"
    Nothing below changes what `--format=claude-code` prints. It describes how Claude Code
    runs a stop hook — its loop protection and the shell it uses — and how to write the
    `settings.json` command so that it works with that behaviour. Both details come from the
    [Claude Code hooks reference](https://code.claude.com/docs/en/hooks) and may change with
    Claude Code releases; Alfil does not control them.

### Avoid blocking forever

The command above blocks on **every** attempt to stop while the problem is still there. When
the agent cannot fix it (a pre-existing error, a tool that is not installed), each stop is
blocked again, and Claude Code only ends the turn after **8 consecutive continuations**. The
counter resets each time Claude calls a tool, and `CLAUDE_CODE_STOP_HOOK_BLOCK_CAP` raises it.

Claude Code sends the hook event as JSON on stdin, and for the Stop event it includes
`stop_hook_active: true` when the agent is already continuing because of a stop hook. Check
it to block once and then let the agent stop:

```json
{
  "hooks": {
    "Stop": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "grep -q '\"stop_hook_active\": *true' && exit 0; vendor/bin/alfil flow qa --fast-dirty --format=claude-code"
          }
        ]
      }
    ]
  }
}
```

The first stop runs the flow and, if a job fails, blocks with its output. The next consecutive
stop finds `stop_hook_active: true` and exits silently, so the turn ends — **whether or not the
agent fixed everything**: on that second stop the flow does not run at all. The agent gets one
round of fixes per turn; whatever is left is for you to see, and the git `pre-commit` hook still
stops it from being committed. Drop the guard if you prefer the agent to keep working until the
flow passes, and accept that a problem it cannot fix will cost it up to 8 rounds.

### Hook commands run under `sh -c`

On Linux and macOS, Claude Code passes the `command` string to `sh -c`, not to your login
shell. On Debian and Ubuntu, `sh` is `dash`, which does not understand bash syntax.

!!! warning "Do not redirect the command, and mind the shell"
    With `--format=claude-code` there is nothing to silence: stdout carries only the
    protocol (empty, or the `block` JSON) and stderr stays empty unless the configuration
    itself is broken (exit `1`). Any redirection of stdout swallows the JSON, and the hook
    never blocks again.

    If the hook line runs other commands before Alfil and silences them, remember it runs
    under `dash`: `cmd &>/dev/null` is not a redirection there but "run `cmd` in the
    background", so its output lands on stdout next to the JSON and Claude Code can no longer
    parse it. The hook then never blocks, and nothing reports it. Write `cmd >/dev/null 2>&1`
    (POSIX), or set `"shell": "bash"` on the hook.

When the hook needs no shell at all, declare the arguments in `args`. Claude Code then runs
the command directly, without a shell:

```json
{
  "type": "command",
  "command": "vendor/bin/alfil",
  "args": ["flow", "qa", "--fast-dirty", "--format=claude-code"]
}
```

## Works with `flow`, `flows` and `job`

`--format=claude-code` is available on all three execution commands, so you can scope
the stop hook to a single fast job instead of the whole flow:

```bash
# Whole QA flow over the working tree
alfil flow qa --fast-dirty --format=claude-code

# A single fast job
alfil job phpstan_src --fast-dirty --format=claude-code

# A meta-flow of several flows
alfil flows pre-commit --fast-dirty --format=claude-code
```

## Other agents

The format is named after the consumer it was written for, `claude-code`, but the stop-hook
protocol it emits is no longer exclusive to Claude Code.

### GitHub Copilot CLI (tested)

Copilot CLI reads hooks from `.github/hooks/*.json` and fires `agentStop` when the agent
ends its turn. It accepts the same stdout protocol — `{"decision":"block","reason":…}` forces
another turn with the `reason` as the next instruction, and an empty output lets the agent
stop — so the command is the same one as above, guard included:

```json
{
  "version": 1,
  "hooks": {
    "agentStop": [
      {
        "type": "command",
        "bash": "grep -q '\"stop_hook_active\": *true' && exit 0; vendor/bin/alfil flow qa --fast-dirty --format=claude-code",
        "timeoutSec": 60
      }
    ]
  }
}
```

Tested with GitHub Copilot CLI 1.0.95 (October 2026). Two details from that run: the event JSON on stdin
uses camelCase names (`sessionId`, `stopReason`, …) **except** `stop_hook_active`, which keeps
its snake_case name, so the guard works unchanged; and the
[Copilot hooks reference](https://docs.github.com/en/copilot/reference/hooks-reference) documents
the same limit of 8 consecutive blocks.

### Others

OpenAI Codex CLI (`Stop`) and Gemini CLI (`AfterAgent`) document the same JSON shape, but
Alfil has not been tested with them. Cursor's `stop` hook uses a different field
(`followup_message`) and is not supported. There is deliberately **no** auto-detection from
environment variables: the format you want is always explicit.

## See also

- [Output Formats](output-formats.md) — the full `--format` reference.
- [Execution modes](../execution-modes.md#fast-dirty-mode-fast-dirty) — what `--fast-dirty` analyses.
