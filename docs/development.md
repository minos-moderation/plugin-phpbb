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
| `event/acp_notice.php`, `adm/style/event/` | Every ACP page: what is missing while the extension is switched on without a usable configuration. |
| `event/listener.php` | Posting: holds a new post (`force_approved_state`) and sends it once it has an id; re-assesses an edit of a waiting post. MCP: marks held posts. Notifications: keeps phpBB's "in the queue" notice of a held post on the board only. |
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
| `migrations/` | The table; the settings and the ACP module; the gateway's pause (`gateway_pause`, `shared_pause`); the user groups (`user_groups`); the time a verdict arrived (`verdict_times`). |
| `language/pl/`, `language/en/` | Polish texts, identical in both (see "Language"). |
| `bin/build-zip.sh`, `bin/package.php` | The installable package: the script installs the client from the lock, the packer decides what ships. |
| `bin/phpbb-text-fixtures.php`, `tests/fixtures/phpbb-3.3/` | What phpBB's real text formatter makes of a corpus (see "Text fixtures"); never shipped. |
| `.github/workflows/release.yml` | On a `v*` tag: builds the package, proves it with `.github/scripts/check-release-archive.sh` and attaches it to the GitHub Release. |

## The life of a post

A row of `minos_pending` moves `queued` → `pending` (the gateway said `202`) → `received`
(an outcome is recorded) → a final status: `published`, `masked`, `held`, `deleted`, or
`superseded` (a moderator acted first, or the post is gone). Every move is a conditional
`UPDATE` checked by its affected-row count, so a repeated delivery, a webhook that
overtakes its own `202`, and the cron task racing the webhook change a row once.

The time-out counts from the gateway's `202` (`mark_pending` moves `submitted_at` to it),
so the gateway always has its whole delivery window; a row the gateway never accepted counts
from its queueing. The digest of the text (`sent_md5`) is stored when the request is
prepared, before it leaves (`mark_sending`): a webhook that overtakes its own `202` is still
checked against the text sent. A row without a digest takes no verdict from the gateway
(nothing is recorded; it waits for its time-out or a new revision), and an outcome recorded
without one is applied to nothing.

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
other change - a moderator's deletion, restoration or approval, an edit made other than
through phpBB's posting form - supersedes it. An edit through the posting form, by the author
or a moderator, of a post that timed out (published by fail-open or held by fail-closed) is
reassessed instead: the post goes back to the queue (`ITEM_REAPPROVE` when it was published)
as a new revision, `phpbb:<id>.<n>` (`event/listener.php`, `pending_store::is_reopenable`).
An edit of a post published on a verdict, or published unassessed after `nieocenione` or a
refusal, is not reassessed (the shared rule D9.2 is deferred until the gateway's capacity
decision).
"Back to the queue" is phpBB's `ITEM_REAPPROVE`, which the MCP queue lists.

## Decisions worth knowing

- **Fail-closed is the default** (as in the WordPress and MyBB plugins): an unassessed post
  waits for a human. An unrecognised stored value reads as fail-closed too.
- **Switched off, the extension does nothing**: the webhook answers `404` before reading
  anything, nothing is sent, the cron task is not runnable. Held posts wait for moderators.
  Switched on again in the ACP, every row still waiting (`queued`, `pending`) becomes a new
  revision, queued with a fresh time-out (`pending_store::restart_waiting`; the plugins'
  shared rule D9.13). Re-enabled in the extension manager with the setting left on, the cron
  task resumes where it stopped (overdue rows get the failure mode).
- **Switched on without a usable configuration, the failure mode** (shared rule D9.12): with
  no key, no secret or no usable gateway address, new posts are held as usual and get the
  failure mode at once (`refused`, `not_configured`), without a request; every ACP page says
  what is missing (`event/acp_notice.php` on `core.adm_page_header`, rendered by
  `adm/style/event/acp_overall_header_body_before.html`), never a stored value. A fresh
  installation starts switched off. Finished rows - the original of a masked post, the
  verdict - are kept 30 days (D9.11), pruned by the cron task.
- **A link or a title without text to assess is the failure mode** (the plugins' shared rule
  D9.5): a post whose text is empty but which holds a link (`<URL>`, also with a blank text a
  reader cannot see) or carries a title (a new topic, a reply's own subject) is held and
  gets the failure mode at once, without a request; the MCP notes "brak tekstu do oceny"
  (`error_code` `empty_text`). A reply with no text, no link and the default subject
  (attachments only) is not held. A title is never sent alone.
- **Staff and phpBB's own queue are skipped.** Posts by administrators and the forum's
  moderators are not held. A post phpBB queues anyway (no `f_noapprove`; by phpBB's
  defaults the newly registered users, see "phpBB APIs") is left to the moderators and not
  sent: nothing would be done with its verdict. The advisory mode for that queue (send such
  posts, let the verdict only annotate them) is the deferred shared rule D9.4, not done.
- **The administrator chooses whose posts are assessed: user groups** (audit K-53). A post
  is held only when its author belongs to one of the groups chosen in the ACP
  (`settings::group_ids`, a JSON list in `config_text`); none chosen means every group, the
  default and the behaviour before the setting. A damaged value, and a list whose groups all
  were deleted since, read as every group: the assessment is never switched off unseen. The
  author of a new post is the current user (`submit_post()` takes `$user->data['user_id']`
  for every mode but `edit`); `forum::in_groups` reads phpBB's `user_group` table, counting
  approved memberships only (`user_pending = 0`). The check runs after the staff and
  `f_noapprove` checks, so it costs one query for an assessable post only. An edit of a
  post the extension already holds is reassessed whatever the groups.
- **The title is assessed with the text** (the shared rule of the Minos plugins): the first
  post of a topic is sent as the topic's title, decoded as a reader sees it, a blank line and
  the post's text, all within the 3000 characters; a reply as its own subject and its text,
  only when the author changed phpBB's default "Re: <title>". A title alone is never sent:
  a post that carries a title (every new topic; a reply with a subject of its own, read
  from `post_data['post_subject']` and `data['topic_title']` of
  `core.posting_modify_submit_post_before`) but has no text to assess, such as `[b] [/b]`,
  is held and gets the failure mode at once, as one with only a link does.
  Each attempt reads the title from the topic, and `sent_md5` covers it, so a title changed
  while the post waits is a new revision (an edit) or a verdict that no longer applies. A
  masked text that touches the title (or the blank line) holds the post: the extension never
  changes a title (`forum::masked_body`).
- **A poll is assessed with the post that carries it** (shared rule D9.1: a poll's question
  and options are part of the text of the post that carries the poll). In phpBB the poll is
  the topic's and is created and edited with its first post: that post's text sent is the
  title, a blank line, the question and each option on a line of its own, a blank line and
  the post's text (`forum::poll_of`, read on every attempt, so `sent_md5` covers it). A poll
  changed with the first post while it waits is an edit, hence a new revision; a poll
  changed otherwise makes a verdict for the old one stand aside (superseded). A mask that
  touches the poll holds the post, as one in the title does; with a poll, a post whose own
  text is blank is still sent (the poll is text to assess).
- **The text sent is read from phpBB's stored XML** (`forum::plain_text`), not with phpBB's
  `clean_formatting()`, which puts a space where every tag sat: `zak[b]a[/b]zane` would reach
  the gateway as "zak a zane" (recorded in `tests/fixtures/phpbb-3.3/`). Inline formatting adds
  nothing between words; `QUOTE`, `LIST`, `LI`, `CODE`, `br` and `p` start and end a line (one
  line break, or the author's own); `<s>`/`<e>` (the typed markup) are dropped. A stored text
  that is not XML (phpBB 3.0's storage) is read with its markup, hiding nothing. The XML
  parser is PHP's DOM, which phpBB 3.3 itself requires (its text formatter does).
- **A refusal that outlasts the wait is the failure mode at once** (the plugins' shared rule
  D9.3(a)): when `ponow_za_s`, taken as sent (not shortened to the retry cap), ends after a
  row's time-out, the row gets the failure mode (`timeout`, with the refusal's code) instead
  of a retry no verdict could follow.
- **One refused request, not one per post: the shared pause of sending** (audit K-39). Every
  `429` (the key's daily limit, its minute or in-flight limit, the shared ceiling, a full
  queue, any other code), every `503` and no answer at all start the gateway's pause
  (`settings::record_pause`: the kind, the code and until when - `ponow_za_s`, capped at a
  day, or 60 s without it - in three `config` values from the `gateway_pause` and
  `shared_pause` migrations). While it lasts `submitter::submit` sends nothing: a post from an
  author's request stays `queued` for the cron task, and a run of the cron task stops before
  its next batch (`hold_back`). A row whose time-out comes before the pause ends gets the
  failure mode at once, with the pause's code (D9.3). Each refusal by the key's limits spends
  its allowance (the contract: only a refusal the key did not cause is free), hence the pause.
  A `500`, `502`, `504` or a `2xx` that is not the gateway's acceptance is retried per row
  and pauses nothing. The ACP shows the pause with its kind and hour, never the key or a
  body; the next `202` forgets it.
- **The time to a verdict is measured on the forum** (audit K-36). `received_at` (migration
  `verdict_times`) is when the gateway's outcome arrived (`record_verdict`, from the
  receiver's `$now`; the extension's own outcomes keep 0); `submitted_at` is the `202`. The
  ACP shows, for the last 7 days, the median and P95 of their difference by nearest rank
  (`pending_store::verdict_stats`: two counts and two one-row `ORDER BY ... LIMIT` queries,
  measured only where `received_at >= submitted_at`, so the unsigned subtraction never goes
  negative), the counts of the four verdicts and of the time-outs handled since. A late
  verdict for a timed-out row counts with its true wait; an edit (a new revision) resets
  `received_at`. Times and counts only; no request to the gateway.
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
- **The moderators' e-mail about a post in the queue waits for the outcome** (audit K-60).
  `submit_post()` notifies the moderators of every post it stores in the queue
  (`topic_in_queue` / `post_in_queue`, also by e-mail). For a post the extension holds - its
  flag is in the post data, which `submit_post()` merges into the notification's data - the
  listener on `core.notification_manager_add_notifications` keeps only the board method of
  every recipient: the notice is listed, no e-mail leaves. When the extension leaves the post
  to a moderator (`HELD`: a verdict, the fail-closed failure mode, no text to assess, or an
  outcome that no longer fits the post), `forum::notify_queue` withdraws the notice and adds
  it again, so phpBB - which skips a user already notified about the item by any method -
  sends it by every method once. A post the extension publishes withdraws the notice
  (`approve()`) and never e-mails. A post the extension does not hold keeps phpBB's own
  notification. Switched off in the ACP while posts wait, every post still in the queue gets
  its e-mail then (`controller/acp.php`, at most 1000); disabled in the extension manager,
  nothing runs: the notices stay listed and no e-mail follows.
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
| `tests/Receiver` | Deliveries signed with `Signature::sign` through the controller: good → applied; wrong secret, stale, malformed, another body, an empty or short stored secret → `401`; switched off → `404`; not a payload → `400`; unknown id, other revision, repeat → `200` and nothing; every `kwalifikacja` and both failure modes; longer posts; a masked text that cannot be stored (SQLite triggers), or that touches the title; `wsparcie`; a moderator acting first; the answer before the application; the gateway's own signature vector. `LateVerdictTest`: the fail-open mark and every late verdict, and every change that makes it stand aside. `NotificationTest`: every notification counted by kind, and the queue e-mail delivered through the fake manager's model of phpBB's delivery: none for a post held and published (also back in the queue after an edit), exactly one for a post left in the queue, phpBB's own for a post the extension does not hold. |
| `tests/Submission` | The request's shape (URL, headers, id rule, `meta` fields and `link_domains`, no e-mail/IP/user id, the 3000-character cut, a new topic's title first and a reply's own subject, quotes sent with their author line, markup left out, `title`/`alt` kept); a topic's poll (question and options; a new revision when it changes; none in a reply); `202`, `429`/`503` with and without `ponow_za_s`, a `ponow_za_s` past the time-out (the failure mode at once) and the shared pause of sending (every kind; three posts in a minute → one refused request; a post whose wait ends first gets the failure mode unsent) until the next `202`, no answer, a non-gateway `2xx`, configuration refusals (failure mode, error log with the code only), a redirect, one item spoiling a batch. |
| `tests/Text` | The real phpBB 3.3 formatter's recorded output (`tests/fixtures/phpbb-3.3/`): the `TextUtils` double reproduces it, and the text sent from each recorded post is what a reader sees (formatting inside a word keeps it whole). |
| `tests/Posting` | Which posts are held (a link without text: the failure mode at once; only members of the chosen groups - a pending request to join, a guest, a damaged or stale value); edits; the MCP queue mark. |
| `tests/Cron` | Time-outs in both modes, retries, leftovers, pruning, the interval; a run that stops after the first answer asking to wait (`503`, `429`, no answer) and sends the rest after the pause. |
| `tests/Acp` | `VerdictStatsTest`: posted, delivered through the webhook, read on the page - P50/P95 by nearest rank and the counts of the last 7 days, the wait from the 202 to the delivery, an edit forgetting it, no gateway request. Saving, validation (a group the board has not), the group list, the key and secret never shown, the webhook URL without a session id, the gateway's pause with its hour (never the key or a body), what is missing (on the page and, `AcpNoticeTest`, on every ACP page), switching on again re-sending what waited. |
| `tests/Extension` | phpBB's metadata rules, the migration defaults, the 20-minute floor, the service wiring against the constructors, the route, the bundled client's location. `PackageTest`: packs from the repository's vendor/ and checks every entry, the shipped composer.json, and the unpacked package verifying the signature vector without Composer. |
| `tests/EndToEnd` | The mock gateway of `client-php` (as Composer installs it) and its worker as real processes; the forum stand-in (`forum_router.php`) serves the real webhook controller over the same SQLite board. |
| `tests/Repo` | The Claude Code rules, checked as properties of the agent files (a read-only agent lists its tools and none that writes - without the line it inherits all; a Sonnet agent that writes names every risk path in its "Risk list" block and none in its "You may change" block), each property broken once by an agent in `tests/Repo/fixtures/` that the check must reject; the language files; no PHP 8-only functions. |

CI runs everything on PHP 7.4 and 8.3, plus `php -l` over every file and a package build.
Every action the workflows use is pinned to a full commit SHA with its version in a comment
(`uses: owner/action@<40-hex sha>  # v1.2.3`), the same SHA in `tests.yml` and `release.yml`;
to refresh a pin, resolve the release's tag to its commit (`git ls-remote
https://github.com/<owner>/<action> refs/tags/<tag>`, the peeled `^{}` line for an annotated
tag), check that the commit belongs to that release, and change the SHA and the comment
together everywhere the action is used.

## Text fixtures

`tests/fixtures/phpbb-3.3/*.json` record, for invented posts only, what phpBB 3.3's real
formatter stores (`xml`) and what its `clean_formatting()` and `unparse()` make of it, with
the phpBB commit and version they came from. `tests/Text/RealFormatterFixtureTest.php` holds
the `TextUtils` double and the extension's own text to them. To regenerate them (a session
with network; the checkout stays outside the repository):

```bash
git clone https://github.com/phpbb/phpbb.git /tmp/phpbb && git -C /tmp/phpbb checkout 6c75df1
composer install --no-dev --working-dir=/tmp/phpbb/phpBB
php bin/phpbb-text-fixtures.php /tmp/phpbb tests/fixtures/phpbb-3.3
```

The generator builds phpBB's s9e factory, parser and utils as phpBB's own unit tests do
(`set_s9e_services()` with the default BBCodes and the smilies and style of
`tests/text_formatter/s9e/fixtures/default_formatting.xml`), parses each text with BBCode,
smilies and links on, and writes one file per text. A new text goes into its `$corpus`.
The current fixtures come from commit `6c75df15811d706e60694df86ccd487c98159656` (phpBB
3.3.19, 2026-09-28).

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

- `core.adm_page_header` (triggered first in `adm_page_header()`, `includes/functions_acp.php`)
  and the ACP template event `acp_overall_header_body_before` (`adm/style/overall_header.html`,
  first thing in `<body>`), checked on 3.3.x at `6c75df1`;
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
- polls: `submit_post()` stores a poll of the first post in `TOPICS_TABLE.poll_title` and
  `POLL_OPTIONS_TABLE` (`poll_option_id` in order, `poll_option_text`), both parsed as a post
  is (`message_parser::parse_poll()`, rendered by `viewtopic.php` with
  `generate_text_for_display()`), before `core.submit_post_end`; the poll comes only with the
  first post's form (`$poll` of `core.posting_modify_submit_post_before`, modes `post` and
  `edit`);
- `textformatter\utils_interface::unparse()` (the mask check); `clean_formatting()` is in the
  stub but not used for the text sent (see "Text fixtures"); the XML `s9e\TextFormatter`
  stores (`<r>`/`<t>`, `<s>`/`<e>` markup, `QUOTE author`, `LIST`/`LI`, `CODE`, `<br/>`), run
  through the real formatter for the fixtures;
  `parser_interface::parse()`, `disable_bbcodes()`, `disable_smilies()`,
  `disable_magic_url()` and their `enable_*`; `message_parser` feeding the parser raw text;
- `core.notification_manager_add_notifications` (since 3.1.3-RC1), triggered in
  `manager::add_notifications()` for one type after `find_users_for_notification()`, with
  `notify_users` (user id => the methods: `notification.method.board`,
  `notification.method.email`, ...) to be changed; `add_notifications_for_users()` then
  skips a user already notified about the item by any method (the e-mail method by its
  `notification_emails` record, which `delete_notifications()` leaves); `submit_post()`
  sending `topic_in_queue` / `post_in_queue` for an `ITEM_UNAPPROVED` new post and an
  `ITEM_REAPPROVE` edit with `$data_ary` merged into the data, after its commit and before
  `core.submit_post_end`; `topic::get_item_id()` (the topic) and `post::get_item_id()` (the
  post);
- `log_interface::add()` with the `mod` and `critical` modes; `language::lang()` returning a
  missing key as itself; `add_mod_info()` loading `info_acp_*` / `info_mcp_*` files;
- `db\driver\driver_interface` (the members in the stub; nested transactions),
  `config`, `config\db_text`, `request_interface::raw_variable()`, `auth::acl_get()` with
  `a_`, `m_` and `f_noapprove`, `controller\helper::route()` with `append_sid()` and an empty
  session id, `make_forum_select()`, `add_form_key()` / `check_form_key()`;
- the user groups: `GROUPS_TABLE` (`group_type`, `GROUP_SPECIAL` = 3 for the groups phpBB
  installs: `GUESTS`, `REGISTERED`, `REGISTERED_COPPA`, `GLOBAL_MODERATORS`,
  `ADMINISTRATORS`, `BOTS`, `NEWLY_REGISTERED`, `AI_CRAWLERS`) and `USER_GROUP_TABLE`
  (`user_pending` 1 for a request to join), the guest (`ANONYMOUS`) in `GUESTS`
  (`install/schemas/schema_data.sql`); a special group's name shown through its `G_<NAME>`
  language key, as `\phpbb\group\helper::get_name()` does; `NEWLY_REGISTERED`: `user_add()`
  puts a new account there while `new_member_post_limit` (default 3) is set,
  `session::update_session_infos()` takes it out once `user_posts` reaches the limit, and the
  install gives the group the role `ROLE_FORUM_NEW_MEMBER` (`f_noapprove` NEVER) on the
  forum it creates;
- the ACP module discovery (`acp/*_module` with its `*_info`), the migration tools
  `config.add`, `config_text.add` (which keeps an existing value), `module.add`, the schema types, `depends_on()` on
  `\phpbb\db\migration\data\v330\v330`, the cron task base (`is_runnable()`) and the
  `cron.task` tag, and `metadata_manager` (it requires `composer.json` and its fields).

Not verified by running: the extension has not been installed on a live phpBB board. In
particular, whether phpBB's renderer shows the parser's output for a masked text exactly as
expected, the notification e-mails sent on an approval by the extension, the "in the queue"
e-mail held back while a post is assessed and sent when it is left to a moderator, the MCP template
event's look in prosilver, and the ACP page's look are known only from the source. The
area51 documentation was not reachable from the development environment; phpBB's source
was.
