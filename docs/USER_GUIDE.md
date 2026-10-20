# Provisioner User Guide

Provisioner manages supported SIP phones in FreePBX: map a handset MAC address to an extension, generate its configuration, discover phones on the network, and deploy configuration with SIP NOTIFY.

## 1. Install

1. In FreePBX, open **Admin → Module Admin → Upload Modules** and upload the Provisioner package, or copy the module directory to `/var/www/html/admin/modules/provisioner`.
2. Install or upgrade the module:

   ```sh
   fwconsole ma install provisioner
   fwconsole reload
   ```

3. Open **Admin → Provisioner → Settings** and set the PBX address, provisioning directory, deployment mode, SIP port/transport, and any credentials required by the supported phone family.

Provisioner does not require DHCP for discovery or provisioning. DHCP can still be used by phones for their own network address and by the PBX for lease-based discovery.

## 2. Configure a phone manually

1. Open **MAC Mapping** and add the phone MAC address, extension, brand, and optional description.
2. Confirm the extension exists in FreePBX and that the mapping revision is current.
3. Use **Push Config** only after the phone has a reachable, verified target. For a phone on another subnet, run discovery first so Provisioner has fresh handset identity evidence.
4. Monitor **Phone Status** and open **Diagnostics** if the result is not verified.

Mapping a phone does not send a trigger. Saving an import or mapping is safe to do before the handset is reachable.

## 3. Discover phones

Open **Device Discovery**, enter a CIDR range, and choose **Scan Network**.

- Directly connected ranges use ping/ARP evidence and SIP probes.
- Routed ranges use SIP OPTIONS and do not require local ARP or DHCP leases.
- A normal scan is read-only and does not send provisioning NOTIFY requests.
- Generic SIP responders can be selected for **Identify MAC**. Identification is a separate, explicit action and does not deliver configuration.

After discovery, inspect the MAC, IP, model, source, and freshness evidence. Add only the phones you intend to manage.

## 4. Import mappings

Bulk Handler accepts CSV with the extension, MAC, brand, description, and optional static network fields. The safest workflow is:

1. Export current mappings for a starting CSV format.
2. Choose **Import** and upload or paste the CSV.
3. Click **Review Import**.
4. Resolve invalid rows, duplicate MACs/extensions, incomplete swaps, and revision warnings.
5. Click **Save Mappings**.

The complete import is revalidated and saved atomically. An invalid row prevents the whole import from being saved. Saving does not push phones. The completion screen provides separate links to push imported phones, view mappings, or open the batch report.

## 5. Push and verify

Use **Push Config** for selected phones or **Apply Pending** for the intended pending set. Cross-subnet pushes require a saved mapping plus either a current SIP registration with the same MAC or handset discovery evidence observed within 15 minutes.

The push stages are distinct:

1. Generate or reuse the configuration.
2. Send the SIP trigger.
3. Confirm the handset downloaded the configuration.
4. Verify the final registration, IP, and MAC identity.

A SIP `200 OK` confirms acceptance of the NOTIFY request, not that the phone downloaded or applied the file. Use **Diagnostics** and **Batch Reports** for stage-specific evidence.

## 6. DND and call forwarding

At config generation time, Provisioner reads the effective enabled feature codes from FreePBX. Digium and Alcatel/ALE templates receive separate on/off codes for DND, forward all, forward busy, and forward no-answer.

Provisioning these settings enables handset feature-code signaling; it does not turn DND on, set a forwarding destination, or change the current forwarding state. After changing feature codes in FreePBX, regenerate and explicitly push the affected phones.

## 7. Reports and retries

Batch Reports retain the phone/MAC snapshot and each provisioning stage. Search, sorting, filters, page size, and expanded details are preserved during live refresh. CSV export contains the full report, not only the visible page.

**Retry Eligible Phones** rechecks identity and retries the complete eligible scope of the report. Search and filters change what is displayed, not what the retry action includes.

## 8. Troubleshooting

| Symptom | Check |
|---|---|
| Phone is not discovered | Confirm the CIDR range, routing, SIP ports, firewall rules, and that the phone answers SIP OPTIONS. |
| Cross-subnet push is blocked | Run a fresh scan and Identify MAC if needed; inspect Diagnostics for missing or conflicting evidence. |
| NOTIFY succeeds but phone does not change | Confirm the phone can fetch the PBX provisioning URL and inspect download/verification stages. |
| Import will not save | Fix every invalid row and review again; check whether another user changed mappings since the review. |
| DND/forwarding controls do nothing | Confirm the corresponding FreePBX feature code is enabled, regenerate the config, and push it again. |

Protect the provisioning directory and backups because pulled phone configurations may contain SIP credentials. Do not commit generated configs, phone credentials, or release archives to source control.
