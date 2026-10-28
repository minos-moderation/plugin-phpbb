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
| `event/webhook_access.php` | The webhook only: past a disabled or overloaded board (`core.user_setup`). |
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
| `migrations/` | The table; the settings and the ACP module; the gateway's pause (`gateway_pause`, `shared_pause`); the user groups (`user_groups`); the time a verdict arrived (`verdict_times`); the moderators told once (`queue_notice`); the refused signatures (`signature_trace`). |
| `language/pl/`, `language/en/` | Polish texts, identical in both (see "Language"). |
| `bin/build-zip.sh`, `bin/package.php` | The installable package: the script installs the client from the lock, the packer decides what ships. |
| `bin/phpbb-text-fixtures.php`, `tests/fixtures/phpbb-3.3/` | What phpBB's real text formatter makes of a corpus (see "Text fixtures"); never shipped. |
| `bin/phpbb-checkout.sh` | phpBB 3.3.x at the one commit the stubs, the fixtures and the live board use. |
| `tests/functional/`, `phpunit.functional.xml.dist` | The extension on a live phpBB board (see "The live board"); never shipped, never run by `vendor/bin/phpunit`. |
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
- **A disabled board still takes the gateway's deliveries** (review of #7, C-45). phpBB
  answers a guest on a disabled board (`board_disable`) or over its load limit (`limit_load`)
  with its own page and `200` before any route runs, which the gateway takes as delivered: the
  verdict would be lost. `event/webhook_access.php`, on `core.user_setup` (triggered before
  both checks), for `POST /minos/webhook` only (`getPathInfo()` of phpBB's `symfony_request`,
  the same with or without `app.php`), defines `SKIP_CHECK_DISABLED` - phpBB's own way past the
  disabled board, as `ucp.php` and `memberlist.php` use it - and sets `limit_load` to `0` in
  memory for that request (phpBB itself sets `board_disable` in memory there). The verdict is
  applied as a moderator would apply it; readers see nothing until the board is enabled. No
  other route or method passes (`tests/Receiver/WebhookAccessTest.php`, each case in its own
  process; the live board's suite). Not covered, and written in the README: phpBB's check for
  its `install/` directory (an update of phpBB in progress, `allow_install_dir` off), which
  answers the same `200` page and respects no constant the extension may define; the README
  advises switching the assessment off for an update of phpBB.
- **Switched on without a usable configuration, the failure mode** (shared rule D9.12): with
  no key, no secret or no usable gateway address, new posts are held as usual and get the
  failure mode at once (`refused`, `not_configured`), without a request; every ACP page says
  what is missing (`event/acp_notice.php` on `core.adm_page_header`, rendered by
  `adm/style/event/acp_overall_header_body_before.html`), never a stored value. A fresh
  installation starts switched off. Finished rows - the original of a masked post, the
  verdict - are kept 30 days (D9.11), pruned by the cron task.
- **A post without text to assess is the failure mode** (the plugins' shared rules D9.5 and
  F7 of client-php's `docs/plugin-behaviour.md`): every post the settings cover is held
  (`listener::should_hold` no longer looks at the text), and one whose text sent would be
  empty gets the failure mode at once, without a request; the MCP notes "brak tekstu do
  oceny" (`error_code` `empty_text`). Empty means nothing a reader reads: only an image
  (`IMG`, also `[img=WxH]`, which phpBB stores as an `IMG`), an inline attachment
  (`ATTACHMENT`), a video (`FLASH`), a link with a blank label (`<URL>` whose text is blank),
  formatting around nothing, or a title alone (a new topic, a reply's own subject). Characters
  a reader does not see count as nothing (`forum::is_blank`: white space, `\p{Z}` and the
  format characters `\p{Cf}` - U+200B, U+00AD, U+2060, U+200E, ...), in the text, beside an
  image, in a link's label and in a poll; a text the pattern cannot read (not UTF-8) is kept
  and sent: more text, never less. The entity `&#8203;` typed as text stays visible text. Before
  F7 such a reply without a link or a title of its own was published unassessed, and an
  image went to the gateway as its address. A title is never sent alone; an address a reader
  sees (a bare one, `[url]X[/url]`) is text and is sent.
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
  post the extension already holds is reassessed whatever the groups. A member may leave an
  open group (`GROUP_OPEN`, `GROUP_FREE`) by himself in the UCP (`ucp_groups` refuses
  "resign" for any other type) and post unassessed from then on: the ACP explains it and
  marks such groups in the list (`MINOS_GROUP_OPEN`).
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
  line break, or the author's own); `<s>`/`<e>` (the typed markup) are dropped; `IMG`,
  `ATTACHMENT` and `FLASH` add no text (`forum::MEDIA_ELEMENTS`: their content is an address
  or a file name the reader does not read), and the `title`/`alt` attributes collected are
  added only beside other text, never alone (F7). A stored text
  that is not XML (phpBB 3.0's storage) is read with its markup, hiding nothing. The XML
  parser is PHP's DOM, which phpBB 3.3 itself requires (its text formatter does).
- **A refusal that outlasts the wait is the failure mode at once** (the plugins' shared rule
  D9.3(a)): when `ponow_za_s`, taken as sent (not shortened to the retry cap), ends after a
  row's time-out, the row gets the failure mode (`timeout`, with the refusal's code) instead
  of a retry no verdict could follow.
- **One refused request, not one per post: the shared pause of sending** (audit K-39). Every
  `429` (the key's daily limit, its minute or in-flight limit, the shared ceiling, a full
  queue, any other code), every `503`, a `408` (counted as no answer), no answer at all and
  a `2xx` other than `202` (kind `foreign`: something else answered at the gateway's
  address; the ACP says to check it) start the gateway's pause
  (`settings::record_pause`: the kind, the code and until when - `ponow_za_s`, capped at a
  day, or 60 s without it - in three `config` values from the `gateway_pause` and
  `shared_pause` migrations). While it lasts `submitter::submit` sends nothing: a post from an
  author's request stays `queued` for the cron task, and a run of the cron task stops before
  its next batch (`hold_back`). A row whose time-out comes before the pause ends gets the
  failure mode at once, with the pause's code (D9.3). Each refusal by the key's limits spends
  its allowance (the contract: only a refusal the key did not cause is free), hence the pause.
  A `500`, `502` or `504` is retried per row and pauses nothing.
- **Only `202` accepts** (the plugins' shared rule D9.6, client-php's `F5a`): a `202` accepts
  each item its `przyjete` names, and an item it leaves out is retried (`bad_answer`, the
  backoff) while the others wait for their webhooks; any other `2xx`, also one naming every
  item, is retried with the backoff and pauses the sending as a `503` does (`http_<status>`),
  never a configuration error; a `408` is a network failure (retried, a pause), not a
  configuration error. `tests/EndToEnd` proves it over HTTP: a 200 naming the post, then a
  408, at the gateway's address, then the mock's 202 and its verdict. The ACP shows the pause with its kind and hour, never the key or a
  body; the next `202` forgets it, and so does every save of the ACP form
  (`settings::save`): a new key or gateway address is tried at once, and a pause that still
  holds comes back after that one refused request.
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
  sends it by every method once. Once per post: the row's `announced` mark (migration
  `queue_notice`, kept by every new revision) is claimed first (`mark_announced`), because a
  second withdraw-and-add would remove the board notice of a moderator whom phpBB then skips
  for the e-mail it remembers; a later hold (a late verdict, an edit, the assessment switched
  off again) leaves the first notice listed and sends no second e-mail. A post the extension publishes withdraws the notice
  (`approve()`) and never e-mails. A post the extension does not hold keeps phpBB's own
  notification. Switched off in the ACP while posts wait, every post still in the queue gets
  its e-mail then (`controller/acp.php`, at most 1000); disabled in the extension manager,
  nothing runs: the notices stay listed and no e-mail follows.
- **The key and the secret** live in `config_text`, are read raw from the ACP form (phpBB's
  `request->variable()` would HTML-escape a secret), and are shown back only as a prefix. A
  stored secret that is not 16-255 visible characters refuses every delivery.
- **A refused signature leaves a trace for the ACP** (audit K-60): the receiver checks with
  the client's `Signature::check` (0.2.0; `verify` with the reason, so the verification is
  still the client's) and counts each refusal in one dynamic `config` value (migration
  `signature_trace`; dynamic, so an unauthenticated request never empties phpBB's config
  cache): how many since a delivery last passed, and the reason and moment of the last -
  `malformed`, `stale` (more than 300 s from the forum's clock) or `mismatch` (another secret
  or another body). Never the header, the body or the secret. The answer stays `401` with an
  empty body whatever the reason. A delivery that passes, and a new secret saved in the ACP,
  forget it. Anyone who knows the webhook URL can add to it, which the ACP says.
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

No phpBB checkout is needed (the live board is a suite of its own: "The live board").
`tests/stubs/` declares the phpBB and Symfony classes the
extension names, with only the members it calls and phpBB 3.3's signatures;
`content_visibility` and `notification\manager` are also fakes that record every call.
`tests/Fake/Board.php` wires the real services as `config/services.yml` does over SQLite
tables shaped like phpBB's (the extension's own table is created from its migration), and
drives phpBB's posting events and the webhook the way phpBB would. A stub member must exist
in phpBB 3.3 with the same parameters; a new one belongs in the API list below.

| Suite | What it proves |
|---|---|
| `tests/Receiver` | Deliveries signed with `Signature::sign` through the controller: good → applied; wrong secret, stale, malformed, another body, an empty or short stored secret → `401`; switched off → `404`; not a payload → `400`; unknown id, other revision, repeat → `200` and nothing; every `kwalifikacja` and both failure modes; longer posts; a masked text that cannot be stored (SQLite triggers), or that touches the title; `wsparcie`; a moderator acting first; the answer before the application; the gateway's own signature vector. `LateVerdictTest`: the fail-open mark and every late verdict, and every change that makes it stand aside. `SignatureTraceTest`: on the gateway's signature vector, the clock 301 s off → the ACP names the clock, the body changed → the secret, no header → the header; the same empty `401` for every reason; nothing secret kept; forgotten by a delivery that passes and by a new secret. `WebhookAccessTest`: `POST /minos/webhook` passes a disabled and an overloaded board, no other path or method does. `NotificationTest`: every notification counted by kind, and the queue e-mail delivered through the fake manager's model of phpBB's delivery: none for a post held and published (also back in the queue after an edit), exactly one for a post left in the queue, phpBB's own for a post the extension does not hold. |
| `tests/Submission` | The request's shape (URL, headers, id rule, `meta` fields and `link_domains`, no e-mail/IP/user id, the 3000-character cut, a new topic's title first and a reply's own subject, quotes sent with their author line, markup left out, `title`/`alt` kept); a topic's poll (question and options; a new revision when it changes; none in a reply); `202`, `429`/`503` with and without `ponow_za_s`, a `ponow_za_s` past the time-out (the failure mode at once) and the shared pause of sending (every kind; three posts in a minute → one refused request; a post whose wait ends first gets the failure mode unsent) until the next `202`, no answer, `408` and every `2xx` other than `202` (also one naming every item) retried with a pause, a `202` accepting only the items it names, configuration refusals (failure mode, error log with the code only), a redirect, one item spoiling a batch. |
| `tests/Text` | The real phpBB 3.3 formatter's recorded output (`tests/fixtures/phpbb-3.3/`): the `TextUtils` double reproduces it, and the text sent from each recorded post is what a reader sees (formatting inside a word keeps it whole; an image, `[img=WxH]`, an inline attachment, a video and a link with a blank label give nothing; a bare or `[url]` address is text). |
| `tests/Posting` | Which posts are held (every post covered; one without text to assess - each F7 fixture of the real formatter, in both modes - the failure mode at once and no request; an address a reader sees sent with its link; an image's title beside text only; only members of the chosen groups - a pending request to join, a guest, a damaged or stale value); edits; the MCP queue mark. |
| `tests/Cron` | Time-outs in both modes, retries, leftovers, pruning, the interval; a run that stops after the first answer asking to wait (`503`, `429`, no answer) and sends the rest after the pause. |
| `tests/Acp` | `VerdictStatsTest`: posted, delivered through the webhook, read on the page - P50/P95 by nearest rank and the counts of the last 7 days, the wait from the 202 to the delivery, an edit forgetting it, no gateway request. Saving, validation (a group the board has not), the group list, the key and secret never shown, the webhook URL without a session id, the gateway's pause with its hour (never the key or a body), what is missing (on the page and, `AcpNoticeTest`, on every ACP page), switching on again re-sending what waited. |
| `tests/Extension` | phpBB's metadata rules, the migration defaults, the 20-minute floor, the service wiring against the constructors, the route, the bundled client's location. `PackageTest`: packs from the repository's vendor/ and checks every entry, the shipped composer.json, and the unpacked package verifying the signature vector without Composer. |
| `tests/EndToEnd` | The mock gateway of `client-php` (as Composer installs it) and its worker as real processes; the forum stand-in (`forum_router.php`) serves the real webhook controller over the same SQLite board; `foreign_router.php` answers at the gateway's address as something else would (a 200 naming every item, a 408), which is retried until the mock's 202. |
| `tests/functional` | Not in `vendor/bin/phpunit`: the extension's package on a live phpBB 3.3 board with phpBB's own harness and the mock gateway (see "The live board"). |
| `tests/Repo` | The Claude Code rules, checked as properties of the agent files (a read-only agent lists its tools and none that writes - without the line it inherits all; a Sonnet agent that writes names every risk path in its "Risk list" block and none in its "You may change" block), each property broken once by an agent in `tests/Repo/fixtures/` that the check must reject; the language files; no PHP 8-only functions. |

CI runs everything on PHP 7.4 and 8.3, plus `php -l` over every file, a package build and
the functional suite on a live board (job `phpbb-functional`, on PHP 7.4 and 8.3).
Every action the workflows use is pinned to a full commit SHA with its version in a comment
(`uses: owner/action@<40-hex sha>  # v1.2.3`), the same SHA in `tests.yml` and `release.yml`;
to refresh a pin, resolve the release's tag to its commit (`git ls-remote
https://github.com/<owner>/<action> refs/tags/<tag>`, the peeled `^{}` line for an annotated
tag), check that the commit belongs to that release, and change the SHA and the comment
together everywhere the action is used.

## The live board

`tests/functional/moderation_test.php` runs the extension on a real phpBB 3.3 board, with
phpBB's own functional test harness (`phpbb_functional_test_case`, `tests/` of phpBB's
repository): the harness installs a board on MySQL, the board is served over HTTP, and the
tests drive it as a browser would (Goutte), logging in, posting, using the ACP and the
extension manager. The extension is installed from its own package (`bin/build-zip.sh`,
unzipped into `phpBB/ext/`), the functional tests and `phpunit.functional.xml.dist` beside
it; the mock gateway runs from the repository's `vendor/` (`MINOS_MOCK_PATH`), with an
invented key and secret. Scenarios, in order on one board: enabling the extension (its
table, every migration, starts switched off, the ACP page and the webhook URL); a post by a
registered user (`f_noapprove` by phpBB's defaults) held, ONE item in the mock's queue, the
mock's signed delivery through `app.php/minos/webhook` publishing it; a mask (the reader sees
`██████`); `zablokowane` held, with the MCP queue's mark and details; the webhook while the
board is disabled, the assessment switched off and the extension disabled; "Delete data"
removing the table, every `config` and `config_text` value, the ACP modules and the
migrations. The queue e-mail to moderators (audit R20, K-60) is checked in phpBB's own e-mail
queue (`cache/<environment>/queue.php`; phpBB queues e-mails by default) for an administrator
subscribed to it (`notification.type.needs_approval`): none while a post waits, none when it
is published, exactly one when it is left to the moderators.

How the harness comes up, from phpBB's checkout at `6c75df1` (`tests/RUNNING_TESTS.md`,
`.github/setup-phpbb.sh`, `tests/test_framework/`): the tests run from the checkout's root
(`tests/bootstrap.php` sets `$phpbb_root_path = 'phpBB/'`) with phpBB's own PHPUnit; the
database and the board's URL come from the environment (`PHPBB_TEST_DBMS`, `..._DBHOST`,
`..._DBPORT`, `..._DBNAME`, `..._DBUSER`, `..._DBPASSWD`, `PHPBB_FUNCTIONAL_URL`), the
database must exist (the harness empties it and installs the board in-process through
phpBB's installer, writing `phpBB/config.php`), and any web server serving `phpBB/` at that
URL will do: phpBB's CI uses nginx and PHP-FPM; this job uses PHP's built-in server with four
workers (`PHP_CLI_SERVER_WORKERS`), which also gives `app.php/minos/webhook` its path. On
PHP 8 the harness needs phpBB's dependency swap (PHPUnit 9, `misantron/dbunit`), which
`bin/phpbb-checkout.sh --tests` does as phpBB's CI does. Two quirks of the harness, not of
real boards: it installs `server_protocol` and `force_server_vars` swapped (`'0'` and
`'http://'`), which makes every absolute URL `//host/...` without its port, so the tests reset
`force_server_vars` to a new board's `0`; and it leaves `cache/<environment>/queue.php` from an
earlier run, which the tests remove.

```bash
bash bin/phpbb-checkout.sh /tmp/phpbb --tests
composer install --no-dev
unzip -q "$(bash bin/build-zip.sh | tail -n 1)" -d /tmp/phpbb/phpBB/ext/
mkdir -p /tmp/phpbb/phpBB/ext/minos/moderation/tests
cp -R tests/functional /tmp/phpbb/phpBB/ext/minos/moderation/tests/
cp phpunit.functional.xml.dist /tmp/phpbb/phpBB/ext/minos/moderation/
# a MySQL database phpbb_tests and a user for it; then, in /tmp/phpbb:
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t phpBB &
PHPBB_TEST_DBMS='phpbb\db\driver\mysqli' PHPBB_TEST_DBHOST=127.0.0.1 PHPBB_TEST_DBPORT=3306 \
PHPBB_TEST_DBNAME=phpbb_tests PHPBB_TEST_DBUSER=<user> PHPBB_TEST_DBPASSWD=<password> \
PHPBB_FUNCTIONAL_URL=http://127.0.0.1:8080/ MINOS_MOCK_PATH=<this repository>/vendor/minos-moderation/client-php/mock-gateway \
phpBB/vendor/bin/phpunit -c phpBB/ext/minos/moderation/phpunit.functional.xml.dist
```

What the live board showed (2026-10-28, phpBB 3.3.19 at `6c75df1`, PHP 8.3, MySQL 8.0):

- **The webhook while the board is disabled** (ACP "Disable board", also a board over its
  load limit): `app.php` answered `200` with phpBB's "board disabled" page before any route
  ran (`user::setup()` -> `trigger_error()`), so the gateway took the delivery as done and the
  verdict was lost (a bot gets `503`; the gateway is not one). Since the review of #7,
  `event/webhook_access.php` keeps the webhook open (see "Decisions worth knowing"); the suite
  delivers to a disabled board and sees `200` with the controller's empty body, the verdict
  applied and every other page still disabled.
- **The webhook while the extension is disabled** in the extension manager: `404`, phpBB's
  HTML page (the route does not exist); the gateway retries until its TTL. Switched off in the
  extension's settings: `404` with an empty body, from the controller.
- **phpBB requires an enabled extension's `vendor/autoload.php`** when there is one
  (`di\container_builder`, the extensions' autoloaders). The package ships none (see
  "Composer"); a clone with `composer install` (development packages) maps `phpbb\` onto
  `tests/stubs/` and breaks the board (`500` on every page). Install from the package.
- The queue e-mail to moderators (K-60), the MCP marks, the masked text as readers see it, the
  ACP form, "Delete data" and switching on again (a new revision, D9.13): as described above.

## Text fixtures

`tests/fixtures/phpbb-3.3/*.json` record, for invented posts only, what phpBB 3.3's real
formatter stores (`xml`) and what its `clean_formatting()` and `unparse()` make of it, with
the phpBB commit and version they came from. `tests/Text/RealFormatterFixtureTest.php` holds
the `TextUtils` double and the extension's own text to them. To regenerate them (a session
with network; the checkout stays outside the repository; `bin/phpbb-checkout.sh` fetches the
same commit the live board uses):

```bash
bash bin/phpbb-checkout.sh /tmp/phpbb
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

Against a development phpBB, with the extension installed there from its package
(`bash bin/build-zip.sh`, unzipped into `ext/`; never a clone with `composer install`, see
"Composer"):

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
repository; `composer.lock` pins the commit: `e5df7d6d`, client-php's `main` with 0.2.0
(`Signature::check`). When `client-php` is tagged `v0.2.0`, `dev-main` becomes `^0.2`. The
lock moves only with a reviewed `composer update minos-moderation/client-php`, in a commit of
its own. The extension never forks the client's verification.

phpBB 3.3 requires an enabled extension's `vendor/autoload.php` when there is one
(`di\container_builder`; seen on the live board). The package ships none: a second Composer
class loader next to phpBB's own is avoided, and this repository's development autoloader
would map `phpbb\` onto `tests/stubs/` (a clone with `composer install` on a board breaks
it). So `client_loader.php` maps `Minos\Client\` onto
`vendor/minos-moderation/client-php/src/` itself. `bin/build-zip.sh` installs the client from `composer.lock` without development
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

An installed board updates by disabling the extension, replacing its files and enabling it:
phpBB runs new migrations only on enabling (`extension\base::enable_step()`), so files
replaced under an enabled extension meet a schema without their columns. The README says so
("Aktualizacja"); a release that adds a migration must not be installed otherwise.

Nothing in this repository enforces that only the owner tags (the session refusal lives
outside GitHub): the owner should add a tag ruleset on `v*` that only they may bypass, or
a `release` environment with a required reviewer on the `publish` job.

## phpBB APIs

Checked on 2026-09-30 against phpBB's own source, branch `3.3.x` at commit `6c75df1`
(2026-09-28), which is what the stubs copy:

- `core.user_setup` (triggered in `user::setup()`, `phpbb/user.php:232`, before the check of
  `install/` (`:366`), of `board_disable` (`:382`, skipped when `SKIP_CHECK_DISABLED` is defined
  or for admins and moderators) and of `limit_load` (`:393`, on `$config`, the `config`
  service, whose `offsetSet()` changes the value in memory only); `app.php` calls
  `setup('app')` before routing; `symfony_request::getPathInfo()` and `isMethod()`;
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
  `config.add` (its third argument `is_dynamic` stores the value outside phpBB's config
  cache, and `config\db::set($key, $value, false)` then writes it without emptying the
  cache), `config_text.add` (which keeps an existing value), `module.add`, the schema types, `depends_on()` on
  `\phpbb\db\migration\data\v330\v330`, the cron task base (`is_runnable()`) and the
  `cron.task` tag, and `metadata_manager` (it requires `composer.json` and its fields).

Verified by running on a live board since 2026-10-28 (the `phpbb-functional` job, "The live
board"): enabling the extension and its migrations, the posting events holding a post, the
webhook through `app.php` and `kernel.terminate`, a masked text as readers see it, the "in the
queue" e-mail held back while a post is assessed and sent once when it is left to a
moderator, the MCP queue's mark and details (prosilver), the ACP form, "Delete data"
(`revert_schema` and the reverted `config.add`, `config_text.add`, `module.add`).

Still not verified by running: the notification e-mails to watchers when the extension
publishes a post (no watcher in the scenarios), the cron task on a live board (the tests call
nothing through `cron.php`), MySQL's MyISAM (the job runs InnoDB, MySQL 8.0's default; audit
N1), other databases than MySQL, and the look of the ACP and MCP pages beyond their text. The
area51 documentation was not reachable from the development environment; phpBB's source
was.
