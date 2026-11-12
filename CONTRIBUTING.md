# Contributing to Sovera Mail

Thanks for helping. Bug reports, ideas, documentation and code are all welcome.

## Reporting bugs and ideas

- Search the [issues](https://github.com/hljubic/soveramail/issues) first, then open a new one.
- For bugs, include what you did, what you expected, what happened, your OS, and (for AI
  problems) the Ollama version and model name.
- **Never paste real e-mails, addresses, tokens or logs with personal data.** Replace them with
  made-up examples.

## Contributing code

1. Read [AGENTS.md](AGENTS.md): architecture, the AI pipeline and the rules the code must keep
   (nothing is sent or deleted without the user, nothing runs the AI on its own).
2. Start the stack with `docker compose up -d --build` (see the [README](README.md)).
3. Make your change, with tests. Tests use made-up fixtures only, never real mailbox data.
4. Run the checks:

   ```bash
   make test     # Laravel test suite
   make lint     # Pint (PHP code style)
   ```

   For UI changes, run `npx nuxi typecheck` in the `web` container.
5. After backend changes run `make restart`: the queue workers keep old code until restarted.
6. Open a pull request that explains what changed and why. Keep it focused on one thing.

## Contributor License Agreement (CLA)

Sovera Mail is open source under the [AGPL-3.0-or-later](LICENSE) and is also offered under a
commercial license (dual licensing), which funds its development. To make that possible,
every contributor signs the [Contributor License Agreement](CLA.md) once.

You keep the copyright to your work, and every public release stays under the AGPL. The CLA
only lets the maintainer license your contribution under other terms as well.

On your first pull request the CLA bot posts a comment. Sign by replying with:

```
I have read the CLA Document and I hereby sign the CLA
```

That's all; it covers your later pull requests too.
