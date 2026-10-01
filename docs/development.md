# Developing the phpBB extension

The administrator's manual is `README.md` (Polish). This page is for whoever changes the
code. The contract with the gateway is `docs/contract.md` of `minos-moderation/client-php`
(also installed at `vendor/minos-moderation/client-php/docs/contract.md`).

## Layout

The repository root is the extension root: phpBB loads it from `ext/minos/moderation/`.

| Path | What |
|---|---|
| `ext.php` | Refuses to be enabled without phpBB 3.3, PHP 7.4, cURL or the bundled client. |
| `client_loader.php` | Loads `Minos\Client` from the bundled `vendor/` (see "Composer"). |
| `config/services.yml`, `config/routing.yml` | The services; the `POST /minos/webhook` route. |
| `event/listener.php` | Posting: holds a new post (`force_approved_state`) and sends it once it has an id; re-assesses an edit of a waiting post. MCP: marks held posts. |
| `service/submitter.php`, `service/curl_transport.php` | `POST /api/v1/b2b/oceny` and every class of answer. |
| `controller/webhook.php`, `service/receiver.php` | The receiving checklist: raw body, signature, payload, record. |
| `event/deferred_verdicts.php` | Applies what the webhook recorded, on `kernel.terminate`, after the answer. |
| `service/verdict_applier.php` | Verdict + settings → publish / publish masked / hold / soft-delete. |
| `service/pending_store.php` | The `minos_pending` table and its state machine. |
| `service/settings.php` | The settings, read defensively (an unknown value never publishes). |
| `platform/forum.php` | Every phpBB operation on posts: the one class to check when phpBB changes. |
| `cron/sweeper.php` | Every 5 minutes: time-outs, retries, leftovers, pruning. |
| `acp/`, `controller/acp.php`, `adm/style/` | The ACP page. |
| `styles/all/template/event/` | The MCP note above a queued post. |
| `migrations/` | The table; the settings and the ACP module. |
| `language/pl/`, `language/en/` | Polish texts, identical in both (see "Language"). |
| `bin/build-zip.sh`, `bin/package.php` | The installable package: the script installs the client from the lock, the packer decides what ships. |
| `.github/workflows/release.yml` | On a `v*` tag: builds the package, proves it with `.github/scripts/check-release-archive.sh` and attaches it to the GitHub Release. |

## The life of a post

A row of `minos_pending` moves `queued` → `pending` (the gateway said `202`) → `received`
(an outcome is recorded) → a final status: `published`, `masked`, `held`, `deleted`, or
`superseded` (a moderator acted first, or the post is gone). Every move is a conditional
`UPDATE` checked by its affected-row count, so a repeated delivery, a webhook that
overtakes its own `202`, and the cron task racing the webhook change a row once.

`revision` counts edits while a post waits. The id sent is `phpbb:<post id>` for the first
revision and `phpbb:<post id>.<revision>` after an edit; a verdict for another revision is
answered `200` and dropped.

The recorded outcome is applied in one database transaction together with the row's move
to its final status. The webhook applies it after its answer (`kernel.terminate`); if that
does not happen, the row stays `received` and the cron task applies it.

A row that timed out (`verdict = timeout`, final `published` or `held`) takes a verdict that
arrives late. A fail-open publication is marked on the row (`approved_at`, `approved_md5`);
a late verdict applies to the published post only while phpBB still shows the extension's
own approval (`post_delete_user = ANONYMOUS` and `post_delete_time = approved_at`: phpBB
records who changed a post's visibility and when) and the text it approved (the MD5). Any
other change - a moderator's deletion, restoration or approval, an edit - supersedes it.
"Back to the queue" is phpBB's `ITEM_REAPPROVE`, which the MCP queue lists.

## Decisions worth knowing

- **Fail-closed is the default** (as in the WordPress and MyBB plugins): an unassessed post
  waits for a human. An unrecognised stored value reads as fail-closed too.
- **Switched off, the extension does nothing**: the webhook answers `404` before reading
  anything, nothing is sent, the cron task is not runnable. Held posts wait for moderators.
- **Staff and phpBB's own queue are skipped.** Posts by administrators and the forum's
  moderators are not held. A post phpBB queues anyway (no `f_noapprove`) is left to the
  moderators and not sent: nothing would be done with its verdict.
- **Only a whole text is published on the gateway's word.** The gateway sees the first 3000
  characters; for a longer post `bezpieczne` is the failure mode, `ocenzurowane` holds,
  `zablokowane` still blocks. The row's `truncated` flag drives the notes in the MCP and ACP.
- **A masked text is published only if it fits and once it is stored.** It must be the text
  sent with characters replaced by `█` (same length in characters), and the text sent must
  equal the post's unparsed text (no BBCode, quote or attribute text transformed), else the
  post is held (`mask_manual`, the masked text shown to moderators). The update is checked
  and the text read back; otherwise everything rolls back and the post is held
  (`mask_failed`).
- **Soft-deleting an unapproved post approves it first.** `content_visibility` accounts an
  unapproved -> deleted change as if the post had been counted, lowering `user_posts` and
  `num_posts`; approve-then-delete inside one transaction keeps the counters right.
- **Notifications once.** `content_visibility` sends none; the extension's approval sends
  what the MCP's approval would (topic, or post and bookmark; quote), and the adapter never
  approves a post that is not in the queue, so phpBB's own path is never doubled.
- **The key and the secret** live in `config_text`, are read raw from the ACP form (phpBB's
  `request->variable()` would HTML-escape a secret), and are shown back only as a prefix. A
  stored secret that is not 16-255 visible characters refuses every delivery.
- **`link_domains`** approximates registrable domains without the Public Suffix List: two
  labels, three under a country code's `co`/`com`/`net`/`org`/`edu`/`gov`/`ac`/`info`/`biz`.
- **`language/en/` holds the Polish texts.** phpBB falls back to `en` when the user's
  language has no file of the extension; the extension speaks Polish, and identical files
  mean nobody sees raw keys. `tests/Repo/LanguageTest.php` keeps them identical.

## Tests

```bash
composer install
vendor/bin/phpunit
```

No phpBB checkout is needed. `tests/stubs/` declares the phpBB and Symfony classes the
extension names, with only the members it calls and phpBB 3.3's signatures;
`content_visibility` and `notification\manager` are also fakes that record every call.
`tests/Fake/Board.php` wires the real services as `config/services.yml` does over SQLite
tables shaped like phpBB's (the extension's own table is created from its migration), and
drives phpBB's posting events and the webhook the way phpBB would. A stub member must exist
in phpBB 3.3 with the same parameters; a new one belongs in the API list below.

| Suite | What it proves |
|---|---|
| `tests/Receiver` | Deliveries signed with `Signature::sign` through the controller: good → applied; wrong secret, stale, malformed, another body, an empty or short stored secret → `401`; switched off → `404`; not a payload → `400`; unknown id, other revision, repeat → `200` and nothing; every `kwalifikacja` and both failure modes; longer posts; a masked text that cannot be stored (SQLite triggers); `wsparcie`; a moderator acting first; the answer before the application; the gateway's own signature vector. `LateVerdictTest`: the fail-open mark and every late verdict, and every change that makes it stand aside. `NotificationTest`: every notification counted by kind. |
| `tests/Submission` | The request's shape (URL, headers, id rule, `meta` fields and `link_domains`, no e-mail/IP/user id, the 3000-character cut, quotes sent with their author line, markup left out, `title`/`alt` kept); `202`, `429`/`503` with and without `ponow_za_s`, no answer, a non-gateway `2xx`, configuration refusals (failure mode, error log with the code only), a redirect, one item spoiling a batch. |
| `tests/Posting` | Which posts are held; edits; the MCP queue mark. |
| `tests/Cron` | Time-outs in both modes, retries, leftovers, pruning, the interval. |
| `tests/Acp` | Saving, validation, the key and secret never shown, the webhook URL without a session id. |
| `tests/Extension` | phpBB's metadata rules, the migration defaults, the 20-minute floor, the service wiring against the constructors, the route, the bundled client's location. `PackageTest`: packs from the repository's vendor/ and checks every entry, the shipped composer.json, and the unpacked package verifying the signature vector without Composer. |
| `tests/EndToEnd` | The mock gateway of `client-php` (as Composer installs it) and its worker as real processes; the forum stand-in (`forum_router.php`) serves the real webhook controller over the same SQLite board. |
| `tests/Repo` | The Claude Code rules, the language files, no PHP 8-only functions. |

CI runs everything on PHP 7.4 and 8.3, plus `php -l` over every file and a package build.

## The mock gateway

`minos-moderation/client-php` ships a mock of the B2B route and its delivery worker
(installed at `vendor/minos-moderation/client-php/mock-gateway/`; its manual is that
repository's README, "The mock gateway"). Verdicts come only from markers in the text:
`[minos:blokuj]`, `[minos:cenzuruj]` with `[[fragments]]`, `[minos:nieocenione]`,
`[minos:kategoria=samookaleczenie]` (sets `wsparcie`), `[minos:dwa-razy]`,
`[minos:zly-podpis]`, `[minos:stary-podpis]`, `[minos:cisza]`. Never send real users' posts
to it: it keeps its queue as plain JSON on disk.

Against a development phpBB:

```bash
export MINOS_MOCK_WEBHOOK_URL=http://localhost:8080/app.php/minos/webhook   # your board
php -S 127.0.0.1:8100 -t vendor/minos-moderation/client-php/mock-gateway/public   # terminal 1
php vendor/minos-moderation/client-php/mock-gateway/bin/worker.php                # terminal 2
```

In the ACP set the gateway to `http://127.0.0.1:8100` (plain `http` is accepted only on the
loopback), the key `wgb2b_atrapa_minos_0000000000000000` and the secret
`atrapa-minos-sekret-webhooka-tylko-lokalnie` (the mock's defaults), then post as a user
with `f_noapprove` who is not a moderator. `tests/EndToEnd/MockGatewayTest.php` does the
same without phpBB.

## Composer

`composer.json` requires `minos-moderation/client-php` at `dev-main` from its GitHub
repository; `composer.lock` pins the commit. When `client-php` is tagged, `dev-main` becomes
`^0.1`. The extension never forks the client's verification.

phpBB does not autoload an extension's `vendor/`, and requiring the bundled
`vendor/autoload.php` would register a second Composer class loader next to phpBB's own, so
`client_loader.php` maps `Minos\Client\` onto `vendor/minos-moderation/client-php/src/`
itself. `bin/build-zip.sh` installs the client from `composer.lock` without development
packages; `bin/package.php` packs what phpBB loads and the client's `src/*.php` and
`LICENSE` only - no `composer.lock`, no dependency manifest, no tests, no mock. The
extension's own `composer.json` ships, reduced to its metadata, because phpBB's
`metadata_manager` refuses an extension without it.

## Releasing

The version lives in ONE place the release workflow checks: `"version"` in `composer.json` (it ships in the package, and phpBB's extension manager shows it; `ext.php` declares none). The repository
keeps no changelog; the release notes are GitHub's generated ones. The workflow, not a
person, creates the release, and only after its checks pass.

1. Set `"version"` in `composer.json` to the release (`0.1.0-dev` → `0.1.0`) in a pull request, and merge it. On every pull request the `release-archive` job
   of `tests.yml` already runs the release build and checks against the declared version.
2. The dry run on `main`: Actions → Release → "Run workflow", branch `main`, the tag to
   be (`v0.1.0`). It builds and checks exactly as a tag push does and keeps the zip
   as the run's `release-archive` artifact (7 days); see it green.
3. The owner creates and pushes the TAG ONLY, on the commit the dry run checked, from a
   local clone: `git tag -a v0.1.0 -m v0.1.0 <commit>` and `git push origin v0.1.0`.
   Not GitHub's "Draft a new release" form: a tag created there is published together
   with its release, before any check. Tag pushes from Claude Code sessions are refused.
4. The tag's push starts `.github/workflows/release.yml`, which builds `build/minos-moderation-<version>.zip` with `bin/build-zip.sh` on PHP 7.4 and lists it,
   failing on any hit: `composer.lock`, `tests/`, `phpunit*`, the mock gateway, `.git*`, `CLAUDE.md`, `.claude/`, `.github/`, Composer's `installed.json`; it also fails unless `minos/moderation/LICENSE` is inside and not
   blank, and the shipped `composer.json`'s `"version"` equals the tag without the `v` (the message names both). Only
   then the `publish` job creates the release with the zip attached (`--verify-tag`,
   generated notes, a pre-release for a tag with `-`). gh creates it as a draft, uploads,
   then publishes, so a failed upload leaves a draft, never a release without its asset.

When a release for the tag already exists at that point, `publish` fails and attaches
nothing: such a release was published unchecked. Delete it (keep the tag) and re-run the
failed jobs. Nothing replaces an asset (`--clobber` is never used), so a second upload
fails loudly. A failed check publishes nothing: delete the tag, fix `main`, start again
from step 1. By hand: `bash bin/build-zip.sh`, then `.github/scripts/check-release-archive.sh build/minos-moderation-0.1.0.zip v0.1.0`.

Nothing in this repository enforces that only the owner tags (the session refusal lives
outside GitHub): the owner should add a tag ruleset on `v*` that only they may bypass, or
a `release` environment with a required reviewer on the `publish` job.

## phpBB APIs

Checked on 2026-09-30 against phpBB's own source, branch `3.3.x` at commit `6c75df1`
(2026-09-28), which is what the stubs copy:

- events `core.posting_modify_submit_post_before` (`data`, `mode`, `post_id`, `forum_id`),
  `core.submit_post_end` (`data` with `post_id`, `post_visibility`, `mode`),
  `core.mcp_queue_get_posts_modify_post_row`, `core.mcp_queue_approve_details_template`,
  the template event `mcp_post_text_before`; `kernel.terminate` after `$response->send()`
  in `app.php`, with phpBB's own terminate subscriber at the lowest priority;
- `submit_post()` honouring `force_approved_state`, committing before its notifications and
  `core.submit_post_end`; `posting.php` showing the "awaits approval" message for it;
- `content_visibility::set_post_visibility()` (ITEM_APPROVED / ITEM_DELETED /
  ITEM_REAPPROVE only; its unapproved → deleted accounting; `post_delete_user` and
  `post_delete_time` written on every change; no notification of its own), and
  `mcp_queue::approve_posts()` as the model for the approval and its notifications
  (`notification_manager::add_notifications()` / `delete_notifications()`); the MCP queue
  listing ITEM_UNAPPROVED and ITEM_REAPPROVE posts;
- `textformatter\utils_interface::clean_formatting()`, `unparse()`;
  `parser_interface::parse()`, `disable_bbcodes()`, `disable_smilies()`,
  `disable_magic_url()` and their `enable_*`; `message_parser` feeding the parser raw text;
- `log_interface::add()` with the `mod` and `critical` modes; `language::lang()` returning a
  missing key as itself; `add_mod_info()` loading `info_acp_*` / `info_mcp_*` files;
- `db\driver\driver_interface` (the members in the stub; nested transactions),
  `config`, `config\db_text`, `request_interface::raw_variable()`, `auth::acl_get()` with
  `a_`, `m_` and `f_noapprove`, `controller\helper::route()` with `append_sid()` and an empty
  session id, `make_forum_select()`, `add_form_key()` / `check_form_key()`;
- the ACP module discovery (`acp/*_module` with its `*_info`), the migration tools
  `config.add`, `config_text.add`, `module.add`, the schema types, `depends_on()` on
  `\phpbb\db\migration\data\v330\v330`, the cron task base (`is_runnable()`) and the
  `cron.task` tag, and `metadata_manager` (it requires `composer.json` and its fields).

Not verified by running: the extension has not been installed on a live phpBB board. In
particular, whether phpBB's renderer shows the parser's output for a masked text exactly as
expected, the notification e-mails sent on an approval by the extension, the MCP template
event's look in prosilver, and the ACP page's look are known only from the source. The
area51 documentation was not reachable from the development environment; phpBB's source
was.
