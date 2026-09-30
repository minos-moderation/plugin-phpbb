# Minos for phpBB

A phpBB 3.3 extension (`ext/minos/moderation`) that sends new posts to the Wergiliusz
gateway and applies the verdict the gateway delivers to a signed webhook. Part of Minos;
its backlog item is `minos-moderation/minos#4`. Layout, tests and the verified phpBB APIs:
`docs/development.md`.

## The boundary
- The plugin talks only to the gateway, over HTTPS, with the forum's key. The key and the
  webhook secret never reach a log, a page or a public file.
- The gateway decides and the plugin applies. It never guesses a verdict: `nieocenione`
  goes to the administrator's fail-open or fail-closed setting.
- The contract is `docs/contract.md` in `minos-moderation/client-php`; its receiving
  checklist is binding: read the raw body first, verify the signature, drop repeated
  deliveries, answer 2xx fast.
- A PHP plugin bundles `minos-moderation/client-php` at a pinned version and never forks
  its verification. Another language implements it and tests it on the gateway's signature
  vector, copied byte for byte.
- Only the post's plain text (first 3000 characters, quote attributions included),
  `links`, `link_domains` and `author_first_post` leave the forum; never the account's
  e-mail, IP address, id or login.
- Switched off, it does nothing (webhook 404, no sending, cron idle). Fail-closed is the
  default; a text is published only if the gateway saw it whole and it is really stored.

## Code
- Code, comments and commits in English. Everything an administrator or a user reads is
  in Polish; `language/en/` holds the same Polish texts as `language/pl/`.
- Wire strings (JSON keys, codes, headers, enum values) are Polish and never renamed.
- The oldest versions the plugin promises: phpBB 3.3 and PHP 7.4, tested in CI on 7.4
  and 8.3. No PHP 8 syntax: no `match`, `readonly`, `mixed`, union types, promoted
  constructors, named arguments, `?->`, `str_contains` or `str_starts_with`.
- phpBB's conventions: tabs, braces on their own line, lower-case class names. Every
  phpBB call on posts goes through `platform/forum.php`.
- No real posts in tests: use the mock gateway from `minos-moderation/client-php`.
- Never `git add -A`. Sessions open PRs; only the owner or the coordinating session merges.
- Model-pinned agents in `.claude/agents/`, copied from `client-php` and fitted to the
  plugin's paths (`programista-prosty`'s risk list above all).
- This file stays small (`tests/Repo/ClaudeRulesTest.php` pins its size).
