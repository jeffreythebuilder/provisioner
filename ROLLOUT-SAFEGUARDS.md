# Rollout safeguards (1.4.59)

These changes are not a 300-phone capacity certification. Installation and all
database/scheduler changes must use official FreePBX Module Admin hooks, without
manual edits to installed module or core files. Isolated validation is summarized
below; a package does not establish physical mass-deployment performance.

## Durable workflow and entry points

`lib/RolloutQueue.php` stores a wave manifest/cursor, idempotency key, lease,
heartbeat, worker fencing token, report summary, and per-MAC ownership. Push,
apply-with-notify, saved-report retry, selected retry, legacy single SIP notify,
and discovered-device push all submit durable work rather than notifying in the
web request. Ordinary generation-only operations do not claim delivery or clear
pending handset changes. Apply-with-reboot also uses the rollout queue.

Submission atomically saves the report, queue entry, and all selected/expanded
swap-partner ownership. Duplicate active submissions return the same report;
explicit `request_id` values remain idempotent after completion and reject changed
request mappings. Conflicting overlapping selections are rejected as a whole.
Reboot checks/dispatch serialize with submission using the DB control row, reject
owned handsets, and never clear pending mappings on a reboot acknowledgement.

`rollout_worker.php` is CLI-only and uses a single database-backed global lease:
only one rollout wave can run on the PBX at a time, even across cron processes.
It runs at most 20 waves/240 seconds between waves per invocation (an active wave
may take longer), with later ticks picking up remaining durable work. Source
hooks register a one-minute FreePBX `Cron()` entry; no core files are modified.
Version 1.4.47 scheduling was installed through Module Admin on .13 and .21;
uninstall cleanup is covered by isolated fixtures, not a live uninstall trial.
The hook removes complete matching cron lines through FreePBX's exact-line API,
including duplicates or a previous module path, while preserving unrelated jobs.
Version 1.4.48 resolves Asterisk commands through a fixed list of supported
absolute executable paths rather than Cron's restricted PATH. Registration/MAC
verification, SIP transport inspection, diagnostic version checks and existing
reboot fallbacks share that resolution; no host PATH or crontab edits are needed.

## Wave policy and verification

Version 1.4.51 runs identity preflight before config generation/delivery. Matching
existing routed evidence skips probes; otherwise known IPs and recorded endpoints
are tried first. Only explicitly approved `push_search_subnets` ranges permit
wider searches (two private /22-or-smaller ranges maximum). Callback identities
are bound to the attempted IP/nonce and allowed mapped MACs. Wrong MACs stop the
wave; unknown ranges require explicit input and a linked retry. Automatic
identification is bounded to 128 anonymous responders per range, in chunks of
32 with the existing 16 identification workers. No guessed range, silent force
push, automatic configuration retry or tab-triggered identification is added.

Local active ARP is best effort only and does not tighten push requirements.
It uses existing ARP tooling on an on-link interface with bounded probes. The
module hook only checks availability: **no OS packages, sudoers or capabilities
are installed/changed**. Missing tooling, permissions, or responses leave the
existing local resolver/fallback rules intact. See README for operator behavior.

- Default wave cap **25**, configurable **1–30** in PBX Settings. Complete swap
  groups are indivisible; groups larger than the cap are rejected, not split.
- Notification transport concurrency is at most 10 inside the current wave.
  The Python child is bounded to 30 seconds and 1 MB output, with lease renewal.
- Default settling time is **20 seconds** after observed downloads, configurable
  **5–120 seconds**. This bounds rollout rate even if SIP acknowledgements and
  existing contacts are fast. The slot is held through final verification.
- Mapping and config-file revisions are rechecked when each wave starts.
  Targets and routed MAC/IP identity are freshly resolved in the worker; nothing
  is authorized from a stale submission-time IP. A final identity failure blocks
  the entire affected swap group before any member is notified.
- Existing staged/atomic generation, custom preservation, snapshot guards, and
  complete-swap finalization remain. Config hashes are retained across queue
  activation, checked during verification, and rechecked under the config-write
  lock before clearing pending state. A fetch or SIP acknowledgement alone is
  not final registration/MAC/final-IP verification.
- A mapping/ownership/config guard rejection at finalization supersedes the
  entire affected swap unit, retains pending changes, and stops later waves.
  Earlier successful registration checks cannot remain verified in the report.
  Device status is invalidated only while it still belongs to this attempt;
  newer push attempts are never overwritten.
- Version 1.4.49 disables manual Verify Last Push while the phone or any pending
  swap partner is rollout-owned, or a standalone verifier is still active. The
  backend rechecks eligibility while holding the same control-row transaction
  lock as submission/worker claims, so a stale browser cannot race automatic
  verification or a newly submitted rollout. Rejection changes no handset or
  report state. Manual verification becomes available again after ownership and
  automatic verification finish; it never sends a provisioning notification.
- Worker download/verification defaults are 30/180 seconds per wave. Existing
  `push_download_timeout` and `push_verify_timeout` settings apply, bounded to
  120/600 seconds in rollout context. Legacy standalone verification retains its
  previous deadline limits. These defaults need physical timing measurements.
- Any unverified wave stops the rollout for review; later waves are not sent.
  Offline peers cannot create an unbounded pipeline of rebooting handsets.

## Recovery semantics

The global worker lease lasts 300 seconds and renews during notification,
download observation, settling, and verification. Before invoking transport, the
worker commits dispatch intent for the owned current wave. Stale workers cannot
pass another checkpoint or finalize after losing their token.

Recovery can resume pre-dispatch work, including safe staged regeneration.
**Any recovered dispatch checkpoint stops the rollout as uncertain; it never
automatically resends.** Even a crash after completed verification but before
cursor advancement can conservatively require review. Already verified outcomes
are preserved; unsent items are marked as not sent. Operators must inspect
uncertain handset state before an explicit retry. Remaining ownership is released
transactionally when the rollout reaches complete/review.

Queued/running reports stay active based on durable job state, not batch age.
Stale leases are shown as recovery-pending; report reads do not expire managed
jobs. The legacy standalone verifier cannot claim rollout-owned verification
jobs. Old non-rollout reports/jobs keep their previous reconciliation behavior.

## Observation, reports, and identification

`lib/FetchLogObserver.php` shares one incremental cursor/open inode per access
log for the wave. It advances offsets, buffers partial lines, drains old inodes
on rotation, handles truncation, and matches exact selected MAC filenames.
The existing atime fallback remains separately labelled weak delivery evidence;
neither log nor atime evidence substitutes for registration verification.

Verification bulk-loads pending rows, writes verifying transitions once, shares
contacts/ARP, and polls at two-second intervals. Completion reconciles reports
once per affected batch. New rollout history uses stored per-wave summaries
instead of loading every report/current mapping; legacy history retains its old
path. Job/owner/report/parent indexes are installed through hooks.

The modal distinguishes queued from delivered/verified, shows expanded selection
counts, and links to the live saved report. Phone Status marks owned phones in
progress and disables competing push/reboot actions. Reports retain action
guidance; identity/mapping-blocked items are not blindly included in delivery
retries. Uncertain attempts carry explicit review-before-retry warnings. Saved
reports provide an **Overview** view alongside the **Phones** table view,
a header freshness indicator (`Updating…`, `Last checked: HH:MM:SS`,
`Updates paused`, `Refresh failed — Retry`), and short batch IDs (`#12345678`) in
history, summarizing **Rollout progress** across the 5 stages (Target & Identity,
Config Generation, SIP Trigger, Config Download, Verification) with
stage-accurate done/active/attention/not-reached/not-recorded counts (based on
recorded identity evidence rather than IP presence alone), grouped outcome +
specific reason breakdowns, wave/timing execution status, and structured
brand/source/IP-group (`/24` bucket) breakdowns (using `Mappings saved` for
imports) with 1:1 predicate drilldown into the per-phone list.
Live report polling updates the Overview funnel and removes stale Bootstrap
Table `tr.detail-view` rows before reloading table data, updating expanded
detail cells in `onPostBody` so a phone detail row opened while queued refreshes
its 5-stage timeline cards, target IPs, and timestamps as waves complete, while
keeping the main `Result` cell focused on status, reason, and next action.
**Review Mapping** focuses the affected MAC in **MAC Mapping**, preserves table
checkbox selections, offers **Return to Batch Report**, and restores the previous
mapping search on exit without triggering a push.
Summary cards in `#batchReportSummary` live inside the **Phones** view and act
as interactive status filters synced with `#batchReportFilter`.

An explicit Identify MAC click can sequence >32 selected unknown IPs into serial
chunks of at most 32. Scan/navigation/selection never starts identification or a
push. Existing scan expiry and identity freshness checks remain authoritative:
if a long sequence outlives its scan or a callback fails, it stops for explicit
operator recovery. This identification sequence is browser-managed, not a durable
server queue; completed individual jobs remain in existing discovery storage.

## Official schema migration

`lib/PushJobSchema.php` widens aggregate verification payloads to MEDIUMTEXT,
preserves LONGTEXT, checks encoding/size, and reserves verification records before
notifications. `lib/RolloutSchema.php` creates queue/owner/control tables, summary
storage and indexes, ensures transactional InnoDB tables on MariaDB, and records
readiness only after migration succeeds. Runtime code fails closed on a missing
readiness marker; it does not perform queue schema repairs. Hooks preserve all
existing mappings/reports/jobs, restore PDO error mode, and propagate failures.
Do not narrow payloads or discard job/ownership records during rollback.

Schema revision 2 uses `wave_cursor` rather than MariaDB's reserved `CURSOR`.
Official hooks preserve the earlier unreleased column's saved position, including
when Module Admin has already added the new XML field with its default zero.
The report API retains its `cursor` field through a quoted SQL alias. Runtime
workers cannot proceed against an older readiness revision.

## Isolated validation and remaining release gates

```
PROVISIONER_DISCOVERY_DIRECTORY=/tmp/opencode/provisioner-rollout-tests-discovery TMPDIR=/tmp/opencode sh tests/run
```

Fixtures use SQLite/migration doubles, temporary config/log files, mocked phone
transport/Asterisk/discovery, and existing loopback mock services. They include a
300-phone real-engine/queue run in twelve bounded waves, duplicate/overlap cases,
offline/late/slow/conflicting peers, pre-dispatch persistence failures, actual
worker-process termination and stale lease recovery, fencing, config/mapping
changes, swap/custom preservation, queued UI and a 300-target identification
sequence. These are correctness fixtures, not production throughput benchmarks.

Latest isolated run: **237 tests passed** (169 PHP, 5 fetch Python, 12 discovery
Python, 5 active-ARP Python, 8 live UI JavaScript, 38 discovery UI JavaScript), plus PHP/JS syntax
checks. Added regressions cover reserved-word SQL, migration without wave replay,
mapping/config races at finalization, swap-unit invalidation and preservation of
newer attempts. SQL compatibility checks are contracts/SQLite fixtures, not an
executed MariaDB integration test.
The additional CLI regression runs registration/MAC and transport inspection
with PATH restricted to `/usr/bin:/bin`, checks executable/argument quoting and
missing-CLI failure, and confirms production ignores PATH-injected executables.
Manual-verification regressions cover queued and settling phases, swap-partner
ownership, standalone verification jobs, unavailable readiness, unchanged state
on rejection, stale diagnostics and eligibility after rollout completion.
The report-recovery tests additionally cover scoped Discovery navigation,
the original-report return path, selection preservation, mixed recovery actions,
stale report state, inline result reasons/next steps, preserved Details and push
controls, MAC Mapping's retained scoped push confirmation, escaped labels and no automatic handset actions.
Additional preflight fixtures cover automatic matching identification, matching
registration/fresh evidence shortcuts, wrong-MAC rejection, no guessed subnet,
range approval/retry, post-lookup mapping edits, local nonblocking ARP failures,
recorded port/transport retention and a hook that never invokes OS setup.
Settings regression coverage checks the plain-language wave/pause/search help,
unchanged defaults/limits and absence of non-actionable ARP notices.
Advanced-settings coverage also checks collapsed markup, retained controls and
automatic expansion through report Set Search Range, without starting a search.
Version 1.4.54 coverage checks Logs report placement, report-link navigation,
retained import/export panels and selected-only config deletion controls.
Version 1.4.55 also checks shared Logs/Bulk Handler tab classes, content gutter
and Activity Logs heading, with no change to report lifecycle or deletion scope.
Version 1.4.56 adds on-demand shared Activity Logs reads and visible retry
coverage. A separate real Chromium test with mocked, delayed HTTP responses
reproduces overlapping-refresh aborts before the fix, confirms no abort/global
error on repeated tab clicks afterward, and verifies that an actual simulated
HTTP 500 still reaches the global AJAX handler and recovers by explicit retry.
No real handset requests or log clearing are performed. Browser check (requires
an already available Playwright/Chromium environment):
`NODE_PATH=/tmp/opencode/provisioner-ui-check/node_modules PLAYWRIGHT_BROWSERS_PATH=/tmp/opencode/provisioner-ui-check/browsers node tests/test_logs_browser.cjs`.
Browser output: `/tmp/opencode/provisioner-1.4.56-browser-tests.log`.
Version 1.4.57 adds explicit-evidence identity tests and report Live Updates
pause/resume/reconnection coverage. `tests/test_mapping_browser.cjs` uses delayed
mock HTTP to verify focused/restored mapping search, retained checkbox selections,
no overlapping-request aborts, report pause and automatic recovery from HTTP 500.
These browser fixtures never contact real handsets or perform save/push actions.
Full output: `/tmp/opencode/provisioner-1.4.59-tests.log` in this workspace
environment. The installed CLI worker and official hooks were not invoked.

### Opt-in real MariaDB checks on this host

```
unshare --net -- python3 tests/run_mariadb.py
```

This separate runner requires Linux network namespaces, installed MariaDB/PHP
executables, PDO MySQL, and this host's FreePBX libraries. It creates a private
MariaDB under a new `/tmp/opencode/provisioner-mariadb-*` directory, ignores host
database defaults, disables TCP networking, checks the connected datadir, and
does not load `/etc/freepbx.conf`. A separate network namespace prevents handset
network traffic. The private database is stopped and removed afterward; logs
remain in its temporary directory. It never connects to the installed PBX DB.

Latest run: **17 real MariaDB integration tests passed** against MariaDB
**10.11.18**, in addition to the isolated suite. Tests exercise:

- The installed FreePBX XML migration implementation with the actual module XML.
- Copies of install/upgrade scripts in a temporary staging directory, including
  access-rule restoration, repeated migration and a memory-only Cron adapter.
- Simulated 300-phone rollouts in twelve 25-phone waves under strict and
  non-strict SQL modes. Transport, registrations and settling remain mocked.
- Legacy TEXT widening and a real >64 KiB payload round-trip without truncation.
- Both old-column migration orders without replaying completed waves.
- Mapping changes during finalization, failed submission rollback, actual
  InnoDB control-row contention and denied ALTER/INDEX privileges.
- Actual worker termination followed by conservative no-replay recovery.
- Legacy MyISAM conversion to InnoDB while preserving mappings and report data.
- Manual verification attempted during settling without superseding the wave,
  diagnostic eligibility, and actual InnoDB lock serialization with submission.

The additional SQL fixtures exercise automatic preflight evidence persistence,
explicit range-required retry links and local ARP-unavailable compatibility.
Version 1.4.58 additionally passes 17 isolated integration tests on the PBX16
test machine (MariaDB 5.5.65, PHP 7.4.16). The private socket-only database runs
in a separate network namespace and never touches PBX tables. Logs on .81:
`/tmp/opencode/provisioner-mariadb-745icuv3/tests.log`.
Twelve discovery/transport tests and five mocked ARP tests pass under its
Python 3.6.7. Only loopback traffic inside an isolated namespace is used.
The five config-fetch tests cannot run there because Python requests is absent;
handset config pulling remains unavailable until that dependency is supplied
separately. No OS packages are installed by these tests or module hooks.
Version 1.4.59 hook fixtures also emulate Module Admin's post-hook fresh INSERT
and upgrade UPDATE, verifying hooks never pre-register modules and consolidate
legacy duplicate rows while preserving provisioning data.
Modern MariaDB log: `/tmp/opencode/provisioner-mariadb-679fb0wh/tests.log`.
The installed Module Admin lifecycle, real Cron installation/execution, PBX
service-user permissions and actual handset behavior are **not** validated by
this harness. It is integration correctness testing, not a throughput benchmark.

Still required before release:

1. Complete Module Admin lifecycle on a supported test PBX: actual hook ordering,
   Cron installation/execution and service-user permissions, interrupted upgrades,
   restart and rollback without losing historical data. Private MariaDB checks
   above validate XML mapping, staged hook execution, engine conversion, SQL modes,
   contention, denied migrations and killed-worker recovery—not a live PBX upgrade.
   Quiesce workers before upgrading.
2. Realistic isolated 300-endpoint timing/load tests (including real transport
   failures and log throughput), resource/DB/Asterisk profiling and PBX call
   responsiveness budgets. The 300-phone fixture mocks transport and settling.
3. Separately authorized physical 50/100/300-phone trials and tuning of wave cap,
   settling/deadline defaults, registration freshness and model-specific reboot
   timing. An available registration after settling is not proof of a reboot.

Deliberately conservative/not implemented: automatic replay/reconciliation of
uncertain dispatches, autonomous retries, adaptive load shedding, durable
multi-job identification orchestration, or wholesale legacy report-history
rewriting. Log cursor recovery across worker death is unnecessary for automatic
replay because dispatched waves always stop for review; rotation before observer
arming can lose old-inode evidence rather than falsely claiming a fetch.
