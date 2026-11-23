# Provisioner

FreePBX provisioning for Digium and Alcatel/ALE phones.

## Install

In **Admin → Module Admin → Upload Modules**, use this package URL:

```text
https://github.com/jeffreythebuilder/provisioner/releases/download/v1.4.59/provisioner-1.4.59.tar.gz
```

The current release is **1.4.59**. After uploading, return to Module Admin and
select **Install** or **Upgrade** for Provisioner. Do not manually edit installed
files, the database, or crontab.

## Basic workflow

1. Configure the PBX address, provisioning directory, SIP transport, and phone
   settings in **Provisioner → Settings**.
2. Add mappings in **MAC Mapping**, or use **Bulk Handler** to import them.
3. Use **Device Discovery** to scan a phone subnet and identify unknown phones.
4. Select phones in **Phone Status** and choose **Push Config**.
5. Monitor progress in **Logs → Batch Reports**.

Provisioner verifies the configuration download and final handset registration.
Reports remain available after the operation and explain failures and recovery
actions.

## Safety

- Discovery scans do not provision or reboot phones.
- Cross-subnet pushes require confirmed handset MAC/IP identity.
- Failed or uncertain waves stop for review; they are not blindly replayed.
- Mappings, pending changes, reports, and audit logs are preserved.
- Live Updates pauses browser observation only; it never pauses the worker.

## Compatibility

Version 1.4.59 is tested on FreePBX 16/PBXact with PHP 7.4 and MariaDB 5.5,
and on FreePBX 17 with newer MariaDB. Python 3.6 is supported for SIP helpers.

Config pulling requires Python 3 and the `requests` package. The module does
not install operating-system or Python packages.

## More information

- [User Guide](docs/USER_GUIDE.md) — setup, provisioning, and troubleshooting.
- [Operations and Development](docs/OPERATIONS.md) — network requirements, tests, and releases.
- [Rollout Safeguards](ROLLOUT-SAFEGUARDS.md) — verification and recovery behavior.
