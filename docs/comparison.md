# Comparison

How GitHooks compares to other PHP git hook managers.

## Feature comparison

| Feature | GitHooks | GrumPHP | CaptainHook |
|---|---|---|---|
| **Distribution** | `.phar` standalone | Composer dependency | Composer dependency |
| **Dependency isolation** | Full (`.phar` bundles all deps) | Shares project deps | Shares project deps |
| **Config format** | PHP array | YAML | JSON |
| **Config approach** | Declarative (type + keywords) | YAML tasks | PHP classes or shell commands |
| **Parallel execution** | Thread budget (`processes`) | Limited | Not built-in |
| **Fast mode (staged files)** | `--fast` | File-based | Not built-in |
| **Branch diff mode** | `--fast-branch` | Not available | Not available |
| **Multiple hooks** | All git events | Pre-commit focused | All git events |
| **Conditional execution** | Branch + file glob patterns | Limited | PHP condition classes |
| **Job inheritance** | `extends` keyword | Not available | Not available |
| **Output formats** | Text, JSON, JUnit | Text | Text |
| **Dry-run** | `--dry-run` | Not available | Not available |
| **Auto-detection** | `conf:init` detects `vendor/bin/` tools | Not available | `configure` command |
| **Config validation** | `conf:check` (deep) | Not available | Not available |
| **Migration from v2** | `conf:migrate` | N/A | N/A |
| **Custom commands** | `custom` type (simple + structured) | Script task | Shell commands |
| **Plugin ecosystem** | Built-in tools + `custom` | Extensions | Plugins + hooks |

## When to choose GitHooks

- You want **isolated dependencies** — the `.phar` binary never conflicts with your project's Composer packages.
- You need **parallel execution with thread budgeting** — distribute CPU cores across tools automatically.
- You need **`--fast-branch` mode** for CI/CD — analyze only the files that changed in a branch.
- You want **declarative configuration** — no PHP classes to write, just type + keywords.
- You want **structured output** (JSON, JUnit) for CI integration.
- You need **conditional execution** by branch and file patterns using simple glob syntax.

## When to consider alternatives

- **GrumPHP** has a larger plugin ecosystem with extensions for commit message validation, file size checks, and more. If you need a specific extension that GitHooks doesn't cover, GrumPHP may be a better fit. However, GitHooks' `custom` type can run any command, so most gaps are bridgeable.
- **CaptainHook** uses PHP classes as actions, which gives maximum flexibility for complex custom logic. If your hooks require sophisticated PHP logic beyond what a shell command can do, CaptainHook's class-based approach may be more appropriate.

## Migration guides

- [From GrumPHP](migration/from-grumphp.md)
- [From CaptainHook](migration/from-captainhook.md)
