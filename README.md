# Provisioner

## Digium and Alcatel/ALE discovery

In **SIP NOTIFY** deployment mode, open **Device Discovery**, enter the phone subnet (for example, `192.168.188.0/24`), and click **Scan Network**.

- On **directly connected subnets**, scans retain the three-pass **ping/ARP sweep**. Digium/ALE MACs can be discovered even when a phone is unregistered, blocks ping replies, or does not answer SIP OPTIONS, as long as it answers local ARP. SIP replies add model, firmware, and endpoint information where available; stale ARP entries stay marked as not seen and cannot identify an anonymous SIP responder.
- On **routed subnets**, scans send bodyless SIP **OPTIONS**. They work without DHCP leases or a local ARP entry. Phones that do not answer SIP on a probed port may be missed.
- The scan automatically detects directly connected ranges from active ARP-capable PBX interfaces. When a range overlaps local and routed networks, ping/ARP is confined to its directly connected portions. Progress distinguishes the local sweep from the SIP probes. Normal scanning sends no provisioning NOTIFYs.
- UDP/TCP ports 5060 and 5160, the configured SIP port, and TLS port 5061 are checked. Ranges from `/22` to `/32` are supported, with bounded parallel probes and live progress.
- Digium/ALE MAC/IP evidence from local ARP and SIP replies is combined with available PBX registration identities. Model, firmware, source, and last-seen evidence appear in discovery results. Only Digium/ALE phones and unidentified SIP candidates are offered; other recognized vendors are filtered out.
- MAC-less SIP responders remain separate IP candidates. Select the desired candidates and click **Identify MAC**. At most 32 selected IPs may be identified at once.

**Identify MAC** sends a URL-only `ua-profile` NOTIFY to the selected IPs. This asks the handset to attempt a configuration fetch from a token-scoped receiver on the PBX. The receiver always returns **HTTP 404** with `Not Found`; it serves no configuration or firmware and sends no reboot/reset commands. Requests are accepted as identity evidence only for an active job, its selected source IP, its private token, and a matching Digium/ALE MAC/model identity. Identification does not generate configs or update normal push/download/verification state.

The scan can show a generic SIP responder before its brand is known. Identification is therefore a separate explicit action. Scan jobs expire after five minutes; run a new scan if the Identify action reports an expired job.

## Bulk Handler and cross-subnet pushes

1. Discover/identify the phones to establish handset-reported **MAC → current IP** evidence.
2. Import **extension → MAC** assignments through **Bulk Handler**, or add found phones to **MAC Mapping** and assign extensions there. Discovery Add actions save mappings only. New phones without an extension show **New / Unassigned**.
3. Use **Push Config** or **Apply Pending** when ready. The config is generated for the mapped MAC/extension, and the SIP trigger is sent to that handset's resolved address and SIP port/transport.

For every cross-subnet push or retry, the handset must have a saved MAC/extension mapping plus either:

- an **available SIP registration** reporting the same MAC at the target IP; or
- matching **handset discovery evidence observed within 15 minutes**.

A stored IP or a configured static destination alone does not authorize a cross-subnet push. Missing, expired, or conflicting identity evidence blocks the trigger and leaves pending changes pending. Run Scan Network and, if needed, Identify MAC again. **Diagnostics** shows the selected source, evidence timestamps, and blocked-target reason.

Routes must permit SIP from the PBX to the handset and the handset's inbound HTTP fetch to the PBX's configured provisioning port. All outbound discovery traffic is SIP; handset WebUI HTTP/S requests are not used by this discovery workflow. Configuration delivery and final handset registration/IP/MAC verification remain distinct stages.

## Apache identity callback installation (1.4.43)

FreePBX Module Admin may omit dotfiles when copying uploaded modules. Provisioner's official install and upgrade hooks now recreate the module-scoped `.htaccess` from `lib/discovery-receiver.htaccess.dist`. Upload/install the module normally or use `fwconsole ma downloadinstall <package-URL>`; no manual server-file edits or Apache reload are needed. Only `discovery_receiver.php` gains public access; core FreePBX access rules and active-job/source-IP/nonce/MAC validation remain unchanged. An invalid test callback should return HTTP 404 with `Not Found`, not Apache 403. This verifies HTTP access only; live handset identification is a separate test.

## MAC-confirmed pushes (1.4.44)

The force-push override introduced in 1.4.42 has been removed. Official install/upgrade hooks delete the retired setting; stale saved values cannot bypass identity checks, and old browser requests to save the option are rejected. Existing batch reports and audit logs remain intact.

Use **Scan Network → Identify MAC → Push Config** for routed phones. Supported handsets report their identity remotely; no physical handset access is needed when the callback works. Unknown scan IPs are not automatically paired with mappings. A mapping needs a current matching MAC/IP identity before any cross-subnet push, apply, or retry. The 1.4.43 Apache callback fix remains included. Downloads alone never replace final handset verification or clear pending changes.

## Identification diagnostics (1.4.45)

In **Device Discovery**, click **Test Callback Access** to check the configured PBX IP and provisioning HTTP port without contacting phones. The test uses a random challenge and requires the receiver's exact HTTP 404 response and matching header, so a generic Apache 404 does not count as success. Redirects are not followed. HTTP 403, HTTP authentication, redirects, unexpected responses, and connectivity failures have distinct guidance. This is a **PBX-to-PBX test only**, not proof of handset-to-PBX network access. Identify MAC also runs this preflight before sending its selected NOTIFY requests. Normal Scan Network does not run the HTTP test and remains read-only.

Identification results distinguish no SIP reply, a non-200 SIP response, an accepted NOTIFY without a validated callback, an unrecognized handset signature, and a missing/invalid/inconsistent MAC. Missing callbacks cannot reveal a remote HTTP status; use the self-test, phone network path, Apache/PHP logs, and shared job-storage permissions to investigate. Callbacks with unrecognized identities produce private diagnostic codes only after the active-job, nonce, and source-IP checks pass; raw headers and token-bearing URLs are not stored in these diagnostics. All existing identity checks still apply.

Completion shows the number of **selected** phones identified and failed, with per-IP next steps and warning logs. An AJAX `success=true` or completed job is not proof that a handset was identified. Previously known phones in the results do not inflate the selected attempt's success count. No self-test or Identify MAC action generates phone configuration, serves firmware, or changes mappings.

## FreePBX 16 compatibility (1.4.59)

Install and upgrade hooks use MariaDB 5.5-compatible constant SELECT syntax.
The SQLite test path remains separate. Python 3.6 is supported for SIP helpers
and optional ARP subprocess handling, including explicitly approved /32 ranges.
The existing interpreter resolver checks `python3.6` when `python3` is absent.
Module Admin owns the module registration, version and enabled state. Hooks
never pre-insert a module row; they consolidate duplicates left by older hooks
while retaining one row for Module Admin to update. This avoids the extra
registration created by FreePBX 16's post-hook fresh-install INSERT.

Handset config pulling still requires Python's **requests** library. A PBX may
have Python 3.6 without that dependency; in that case config pulling is not
available. The module does not install OS/Python packages or alter system Python.
Installation validation is not certification of real handset/trunk behavior.

## Report refinements (1.4.57)

Identity preflight is labeled confirmed only with the saved affirmative
`identity_confirmed` decision. Source labels, lookup messages, ARP attempts and
later generation failures do not imply confirmation. Historical/local attempts
without that decision remain Not recorded; no local push requirement changes.

Mapping-grid reads share an in-flight request, preserving the latest checkbox
selections at response time. Report context restores the real search input, not
Bootstrap Table's sometimes stale `searchText` option.

Batch Reports uses the Phone Status **Live Updates** button style: Live, Paused
and Reconnecting, alongside Last successful update. Its separate preference
controls report refreshes only. Reports still poll saved results rather than
opening the phone-status event stream. Failures retry with 2–30-second backoff;
hidden tabs pause observation. Pausing never pauses the provisioning worker,
retries delivery or changes handset configuration. Finished reports stop polling.

The optional read-only browser fixtures can be run using an existing
Playwright/Chromium environment:
`NODE_PATH=/tmp/opencode/provisioner-ui-check/node_modules PLAYWRIGHT_BROWSERS_PATH=/tmp/opencode/provisioner-ui-check/browsers node tests/test_mapping_browser.cjs`
and the same environment with `node tests/test_logs_browser.cjs`.

Report history shows short IDs, while the main phone row keeps result, reason
and next action prominent. Expanded Details contains the five-stage timeline.

## Reports and navigation (1.4.54)

Saved import and push reports are under **Logs → Batch Reports**, alongside
**Activity Logs**. Bulk Handler keeps Import and Export. Report links open Logs.
Both Logs sub-tabs use the same tab styling, content gutter and section-heading
pattern as Bulk Handler as of version 1.4.55.
Version 1.4.56 initializes Activity Logs on demand and shares overlapping log
reads, avoiding Bootstrap Table aborts and misleading FreePBX AJAX errors.
Genuine request errors remain visible and offer **Try Again**; no global error
handler is disabled and no logs are cleared by opening or switching tabs.
Leaving Logs or switching to Activity Logs stops report polling. Returning to a
report resumes observation without resending configuration. The overview and
Phones views retain drill-downs, per-phone details and explicit recovery actions.

Config Files offers **Delete** for selected files, with no Delete All button.
MAC Mapping's selected-row action is labeled **Delete**; it retains its existing
mapping-deletion confirmation and behavior.

## Automatic push preflight (1.4.51)

**Select phones → Push Config → Checking identity → Finding/Identifying if needed
→ Sending → Downloaded → Verified.** Explicit confirmation authorizes the job's
bounded identity probes; opening a page or running an ordinary scan does not
start identification or provisioning.

- Local phones retain their existing lookup, fallback and conflict rules. An
  existing, permitted `arping` executable can add a bounded on-link refresh;
  no response, missing tool or permission failure does not introduce a new push
  requirement. This is not mandatory fresh-MAC confirmation for every local push.
- Routed phones reuse matching available registration or fresh saved identity.
  Otherwise, preflight probes known IPs/recorded SIP ports/transports and may use
  the existing nonce-bound HTTP-404 identification receiver. The returned MAC
  must match the mapped handset. An identity conflict never becomes a force push.
- Wider lookup uses only **PBX Settings → Networks allowed for automatic phone search**:
  at most two explicitly saved private /22-or-smaller ranges. No subnet is guessed
  from an IP, and the general local Scan Subnets setting is not search approval.
  Identification is bounded to 128 anonymous responders per searched range;
  narrow the range if that bound prevents finding a phone.
- A missing IP/range stops before config delivery with **Set Search Range**.
  Save the range, return to the original report and explicitly **Try Finding
  Again**. A linked retry uses the same guarded workflow; old reports remain
  historical. Known-IP failures can also explicitly retry identification.
- Config/mapping/swap membership are rechecked after lookup, before generation
  or dispatch. Worker leases, bounded waves, late identity gates, post-download
  registration/MAC/IP verification and no-replay recovery remain in place.

**Module Admin installs module code, not OS dependencies.** ARP availability is
checked quietly in the hook; the module never runs a package
manager, edits sudoers, assigns capabilities or grants PHP/Python raw-socket
privileges. If an administrator separately supplies a supported ARP tool and its
permissions, the optional path can use it. Without it, local pushes keep their
existing behavior. Routed automatic identification needs no ARP package.

Version 1.4.53 groups wave size, post-download wait and approved automatic search
networks under collapsed **Advanced settings**. Report **Set Search Range** opens
this section directly; saving still requires an explicit retry and never starts
a search. Existing values and defaults are unchanged.

Version 1.4.52 labels rollout settings **Phones per wave** and **Wait after config
downloads (seconds)**, with examples and separate help for each. The default
25-phone group size is not a total-rollout limit; the default 20-second pause is
not the verification timeout. Automatic-search help explains that blank means
known-IP lookup only and does not change handset IP settings. Technical CIDR
limits are expandable. No ARP-tool availability/setup banner is shown.

## Durable verified rollouts (1.4.47)

### Batch-report recovery guidance (1.4.50)

Stopped push reports show a next-step panel and per-phone actions. Missing
MAC/IP evidence leads to **Open Device Discovery** with the affected MACs and
instructions to scan the phones' subnet, identify unknown responders, and start
a new push from Phone Status. Discovery offers **Return to Batch Report** and
**Open Phone Status** without changing the user's selections or automatically
scanning, identifying, or provisioning. The original report remains historical.
Mixed reports keep delivery retries separate; uncertain delivery requires
Diagnostics review, and mapping changes lead to MAC Mapping instead of Discovery.
Search and filters do not silently narrow recovery or full-batch retry scope.
Build and test the module locally before installing through Module Admin; no
manual installed-file, database or crontab edits are needed.
The existing Extension / Phone-MAC / Target IP / Result / Details columns remain.
Result cells show the reason and next step inline (for example **Discovery
required — No confirmed MAC/IP target**), with the appropriate recovery action.
Unresolved discovery targets read **Not confirmed** rather than the ambiguous
**Not recorded**. Details still opens the evidence/timeline; the longer recovery
instructions are collapsible. Existing Phone Status push controls are unchanged.
MAC Mapping retains **Push Config / Push Selected** rather than changing its
button into **Discover Phones**. It opens the normal scoped push confirmation;
the backend still requires matching identity before delivery. Blocked attempts
are explained and guided through Batch Reports, not bypassed.

Version 1.4.49 prevents manual **Verify Last Push** from racing a queued/running
rollout. Diagnostics explains that automatic verification owns the phone and
disables the action until it finishes. The backend rejects stale-browser/API
attempts under the same database lock as queue submission, including swap
partners. Let an active rollout finish; an old stopped attempt is not replayed
automatically.

Version 1.4.48 fixes Cron-environment registration verification by resolving the
Asterisk CLI through supported absolute paths. No manual PATH, crontab, or core
file changes are needed. A rollout stopped by the earlier verification failure
is not automatically replayed: review Diagnostics and verify the already
downloaded configuration before considering an explicit retry.

Pushes are saved to a background queue and processed in complete verified waves
(25 phones by default, configurable from 1–30). Duplicate/overlapping submissions
are protected; failed or uncertain waves stop for operator review rather than
automatically repeating a potentially delivered notification. Install/upgrade
hooks migrate storage and register the FreePBX-managed scheduler. No manual
crontab or database edits are required. See [ROLLOUT-SAFEGUARDS.md](ROLLOUT-SAFEGUARDS.md)
for recovery behavior, test coverage, and remaining physical validation limits.

## Guided push recovery and diagnostic sharing (1.4.46)

When a mapped phone has no usable target or needs fresh MAC/IP identification, its Phone Status action becomes **Discover**. The toolbar becomes **Discover Phones** for affected selections; it does not silently push the remaining selected phones. Push-result dialogs offer **Open Device Discovery** instead of retrying unresolved targets. Mixed results retain a separate **Retry Delivery Failures** action scoped to delivery failures plus required extension-swap partners, and retain discovery guidance for the blocked phones. Navigation never starts a scan, identification, or push automatically. Enter the phones' subnet, scan, identify if needed, then return to the original selection. Known MAC conflicts remain diagnostic problems, not discovery successes.

Device Diagnostics uses readable registration, network, and source labels; shows model/firmware when available; and distinguishes web-interface reachability from SIP health. **Copy Diagnostic Summary** copies an allowlisted troubleshooting snapshot: module/framework/PHP/Asterisk versions, extension/MAC, target evidence, candidate eligibility, config metadata, and push/download/verification stages and timestamps. Configuration contents, descriptions, credentials, raw handset headers, callback tokens, and raw worker error output are excluded. The summary still contains device/network identifiers; review it before sharing. On HTTP or when clipboard access is denied, a selectable text box provides a Ctrl+C/Cmd+C fallback. Copying does not run a scan or change any phone.

## Import preview and saved batch reports

In **Bulk Handler → Import**, upload/paste CSV and choose **Review Import**. The scrollable review popup shows new/updated/unchanged mappings, field changes, duplicate MACs/extensions, incomplete swaps, invalid network fields, and current push readiness. Extensions must exist on this PBX. CSV supports quoted descriptions, a UTF-8 BOM, and exported headers. Limits: 1 MB / 2,000 rows.

**Save Mappings** revalidates the CSV and mapping revision, and saves the entire import in one transaction. Invalid rows prevent the whole import; fix them and review again. Existing last-known IPs and pending-change history are preserved. Blank static fields select DHCP. A blocked push target does not prevent saving a valid mapping; scan/identify the phone before pushing. Importing does not send a provisioning trigger. The completion panel offers **Push Imported Phones**, **View MAC Mappings**, and **View Batch Report** as separate actions.

**Bulk Handler → Batch Reports** retains import and SIP bulk push attempts in the database, including the extension/MAC snapshot, config generation/reuse, trigger acceptance, download, and background handset verification. Reports survive reloads and later pushes. Older logs are not backfilled. Clear Logs does not remove reports. The history table searches across saved reports and their phone records, with sorting and page sizes of 10, 25, 50, or 100. **Back to Reports** returns to the same history search and page.

**Retry Eligible Phones** creates a linked new push batch, rechecks identity, and includes required extension-swap partners. The action applies to all eligible phones in the report; search, pagination, and result filters do not narrow its scope. Verified phones are not selected independently for retry. Changed mappings and superseded attempts require a new push or the latest report. No SIP secrets or configuration contents are stored in reports.

## Status, discovery, and diagnostics UI

**Phone Status** keeps current SIP registration separate from the last provisioning result. Filters show pending mappings, push problems, or unregistered phones; row actions expose diagnostics, reboot, and—when SIP NOTIFY is active—a target-checked push. Live Updates shows the connection state and last successful snapshot time. The saved DHCP/static badge describes mapping configuration, not a handset-reported network mode.

The import preview uses **Choose CSV → Review changes → Save mappings**. Summary cards and row filters distinguish invalid mappings from phones that simply need discovery. After saving, choose whether to push the imported phones, inspect MAC Mapping, or open the saved import report; saving alone never triggers a handset.

Discovery reports the selected scan method and current results, identified phones, unidentified SIP IPs, mapped devices, older observations, and other unsupported responses. The expandable excluded-responder list gives the reason. Identity source labels show freshness; handset-request identification is described in details rather than being presented as normal configuration delivery.

Batch Reports lead with a sub-view switcher (**Overview** | **Phones**), a header freshness indicator (`Updating…`, `Last checked: HH:MM:SS` updated only after a successful response, `Updates paused`, or `Refresh failed — Retry`), and batch-wide actions (**Refresh**, **Download CSV**, **Retry Eligible Phones**). The history table shows a short batch ID (`#12345678`) beneath each report date for quick reference. The **Overview** tab summarizes the rollout at a glance before drilling into individual phones: a **Rollout progress** section (**1. Target & Identity → 2. Config Generation → 3. SIP Trigger → 4. Config Download → 5. Verification**, or an **Import Summary Funnel** for imports) that classifies each phone into `done`, `active`, `attention`, `not reached`, or `not recorded` (when an older report has no recorded identity evidence) at its actual stopping/current step so stage counts and drill-down counts match 1:1; an **Outcome & Attention Breakdown** grouping phones by outcome and normalized failure/status reason (so per-phone IPs/MACs do not split a group into separate cards, and inline `Extensions:` are shown only for small attention groups of $\le 5$ phones) with one-click **View Phones** drilldown; and a **Rollout Execution & Scope** card showing batch/wave status, completion rate, latest handset activity (kept separate from report polling), recovery scope, and structured breakdowns **By Brand**, **By Target Source**, and **By IP Group (/24 buckets)** (using **Mappings saved** rather than **Verified** for import reports). Clicking any funnel stage, outcome group, brand, target source, or IP group switches directly to the **Phones** tab with an exact structured filter chip (`#batchReportStageFilterChip`). The **Phones** tab leads with 4 interactive summary filter cards (**Phones**, **Verified** / **Mappings saved**, **In progress**, **Needs attention**) synced with `#batchReportFilter`, followed by the phone table where rows show bold extension, human-readable brand (`Digium`, `Alcatel / ALE`) with slate monospace MAC badge, target IP with human-readable target source badge (`PJSIP Contact`, `Local Ping / ARP`, `SIP Lookup`, etc.), and a clean **Result** cell focused on outcome status, reason, and next-step recovery action. **Review Mapping** focuses the affected MAC in **MAC Mapping**, preserves checkbox selections in both **MAC Mapping** and **Phone Status**, offers **Return to Batch Report** (`#mappingReportReturn`), and restores the previous mapping search when leaving that context; saving a mapping never automatically retries a push. **Details** expands a PBXAct 17-styled panel featuring a top conclusion banner, 5-column stage timeline cards matching the Overview sequence (**1. Target & Identity → 2. Config Generation → 3. SIP Trigger → 4. Config Download → 5. Verification**, with recorded identity status or `Not recorded` for older reports), a compact metadata strip, and a direct **Diagnostics** button. Search by extension, MAC, brand, IP, source, or result, and filter to **Needs Attention**, **In Progress**, or **Completed**. Active reports update both **Overview** and **Phones** in place while visible, preserving the selected view, search, sort, page size, page number, and re-rendering expanded visible phone details with fresh stage data (removing stale Bootstrap Table `tr.detail-view` rows before table reload). On narrow screens, both report tables use stacked cards so fields and details remain readable. Related retry reports are grouped under **Related Reports**. **Download CSV** exports the entire report, including rows outside the current search/filter/page. Diagnostics starts with the actionable conclusion and summarizes identity, selected push target, and trigger/download/verification before expandable config and candidate evidence.

## DND and call-forward feature codes

Digium A-series and Alcatel/ALE Myriad templates provision separate enable/disable codes for DND, forward all, forward busy, and forward no-answer. Codes are read from FreePBX **Admin → Feature Codes** at config generation time, including custom overrides. Disabled or missing feature codes render as empty values. Supported codes contain up to 32 digits, `*`, or `#`.

Digium uses `SIP1 SvcCode Mode :1` and the `DNDOn/Off`, `CFUOn/Off`, `CFBOn/Off`, and `CFNOn/Off SvcCode` fields. ALE uses phone-wide Prefix mode (`FeatureDndMode=0`, `FeatureDNDMethod=0`, `FeatureFwdMode=0`, `FeatureFwdMethod=0`) and the corresponding `Feature...OnCode/OffCode` XML settings. ALE references: [Myriad/Halo Administration Manual](https://www.al-enterprise.com/-/media/assets/internet/documents/am-ale-myriad-and-halo-series-deskphone-8al91477enaa-4-en.pdf), sections 10.13–10.15.

These templates configure how handset controls signal the PBX; they do not set DND/forwarding on or off or assign a forwarding destination. The destination is entered by the user on the handset and appended to the forward-on prefix. Feature-code signaling alone does not provide two-way handset indicator synchronization for changes made elsewhere on the PBX.

After changing PBX feature codes, regenerate the relevant phone configs and push them explicitly. Custom phone configs retain the existing preservation behavior. Custom templates can use `{{DND_ON_CODE}}`, `{{DND_OFF_CODE}}`, `{{CF_ALL_ON_CODE}}`, `{{CF_ALL_OFF_CODE}}`, `{{CF_BUSY_ON_CODE}}`, `{{CF_BUSY_OFF_CODE}}`, `{{CF_NOANSWER_ON_CODE}}`, and `{{CF_NOANSWER_OFF_CODE}}`.

## Checks

Run `sh tests/run` for PHP regressions, local fake-handset SIP/HTTP tests, UI behavior checks, and syntax validation.
