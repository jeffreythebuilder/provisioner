# Provisioner

FreePBX endpoint provisioning for Digium, Alcatel/ALE, and Yealink phones.

## Documentation

- [User Guide](docs/USER_GUIDE.md) — installation and day-to-day workflows.
- [Operations and Development Notes](docs/OPERATIONS.md) — network requirements, release checks, and security guidance.

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

## Import preview and saved batch reports

In **Bulk Handler → Import**, upload/paste CSV and choose **Review Import**. The scrollable review popup shows new/updated/unchanged mappings, field changes, duplicate MACs/extensions, incomplete swaps, invalid network fields, and current push readiness. Extensions must exist on this PBX. CSV supports quoted descriptions, a UTF-8 BOM, and exported headers. Limits: 1 MB / 2,000 rows.

**Save Mappings** revalidates the CSV and mapping revision, and saves the entire import in one transaction. Invalid rows prevent the whole import; fix them and review again. Existing last-known IPs and pending-change history are preserved. Blank static fields select DHCP. A blocked push target does not prevent saving a valid mapping; scan/identify the phone before pushing. Importing does not send a provisioning trigger. The completion panel offers **Push Imported Phones**, **View MAC Mappings**, and **View Batch Report** as separate actions.

**Bulk Handler → Batch Reports** retains import and SIP bulk push attempts in the database, including the extension/MAC snapshot, config generation/reuse, trigger acceptance, download, and background handset verification. Reports survive reloads and later pushes. Older logs are not backfilled. Clear Logs does not remove reports. The history table searches across saved reports and their phone records, with sorting and page sizes of 10, 25, 50, or 100. **Back to Reports** returns to the same history search and page.

**Retry Eligible Phones** creates a linked new push batch, rechecks identity, and includes required extension-swap partners. The action applies to all eligible phones in the report; search, pagination, and result filters do not narrow its scope. Verified phones are not selected independently for retry. Changed mappings and superseded attempts require a new push or the latest report. No SIP secrets or configuration contents are stored in reports.

## Status, discovery, and diagnostics UI

**Phone Status** keeps current SIP registration separate from the last provisioning result. Filters show pending mappings, push problems, or unregistered phones; row actions expose diagnostics, reboot, and—when SIP NOTIFY is active—a target-checked push. Live Updates shows the connection state and last successful snapshot time. The saved DHCP/static badge describes mapping configuration, not a handset-reported network mode.

The import preview uses **Choose CSV → Review changes → Save mappings**. Summary cards and row filters distinguish invalid mappings from phones that simply need discovery. After saving, choose whether to push the imported phones, inspect MAC Mapping, or open the saved import report; saving alone never triggers a handset.

Discovery reports the selected scan method and current results, identified phones, unidentified SIP IPs, mapped devices, older observations, and other unsupported responses. The expandable excluded-responder list gives the reason. Identity source labels show freshness; handset-request identification is described in details rather than being presented as normal configuration delivery.

Batch Reports lead with compact completion, active, and attention counts. Phone rows show extension, phone/MAC, target IP, and result. **Details** expands the stage path, attempt timestamps, target evidence, and complete reasons. Search by extension, MAC, IP, or result, and filter to **Needs Attention**, **In Progress**, or **Completed**. Active reports update in place while visible, preserving search, sort, page size, page number, and expanded visible details. On narrow screens, both report tables use stacked cards so fields and details remain readable. Related retry reports are grouped under **Related Reports**. **Download CSV** exports the entire report, including rows outside the current search/filter/page. Diagnostics starts with the actionable conclusion and summarizes identity, selected push target, and trigger/download/verification before expandable config and candidate evidence.

## DND and call-forward feature codes

Digium A-series and Alcatel/ALE Myriad templates provision separate enable/disable codes for DND, forward all, forward busy, and forward no-answer. Codes are read from FreePBX **Admin → Feature Codes** at config generation time, including custom overrides. Disabled or missing feature codes render as empty values. Supported codes contain up to 32 digits, `*`, or `#`.

Digium uses `SIP1 SvcCode Mode :1` and the `DNDOn/Off`, `CFUOn/Off`, `CFBOn/Off`, and `CFNOn/Off SvcCode` fields. ALE uses phone-wide Prefix mode (`FeatureDndMode=0`, `FeatureDNDMethod=0`, `FeatureFwdMode=0`, `FeatureFwdMethod=0`) and the corresponding `Feature...OnCode/OffCode` XML settings. ALE references: [Myriad/Halo Administration Manual](https://www.al-enterprise.com/-/media/assets/internet/documents/am-ale-myriad-and-halo-series-deskphone-8al91477enaa-4-en.pdf), sections 10.13–10.15.

These templates configure how handset controls signal the PBX; they do not set DND/forwarding on or off or assign a forwarding destination. The destination is entered by the user on the handset and appended to the forward-on prefix. Feature-code signaling alone does not provide two-way handset indicator synchronization for changes made elsewhere on the PBX.

After changing PBX feature codes, regenerate the relevant phone configs and push them explicitly. Custom phone configs retain the existing preservation behavior. Custom templates can use `{{DND_ON_CODE}}`, `{{DND_OFF_CODE}}`, `{{CF_ALL_ON_CODE}}`, `{{CF_ALL_OFF_CODE}}`, `{{CF_BUSY_ON_CODE}}`, `{{CF_BUSY_OFF_CODE}}`, `{{CF_NOANSWER_ON_CODE}}`, and `{{CF_NOANSWER_OFF_CODE}}`.

## Checks

Run `sh tests/run` for PHP regressions, local fake-handset SIP/HTTP tests, UI behavior checks, and syntax validation.
