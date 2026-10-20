# Provisioner Operations and Development Notes

## Safe operational sequence

1. Export or back up mappings before a bulk change.
2. Scan and identify phones before cross-subnet actions.
3. Review import changes before saving.
4. Save mappings separately from pushing configuration.
5. Push a deliberately selected scope.
6. Check Phone Status, Diagnostics, and the saved Batch Report.

Normal discovery is read-only. Reboot and push actions are explicit and selection-aware. A stale IP, static destination, or DHCP lease by itself is not sufficient identity evidence for a cross-subnet push.

## Network requirements

- The PBX must be able to send SIP discovery traffic and NOTIFY requests to the phone.
- The phone must be able to fetch its configuration from the configured provisioning URL.
- Firewalls must allow the configured SIP and provisioning ports in both required directions.
- Routed discovery does not depend on DHCP, but phones still need a usable IP address.

## Release checklist

Before releasing a change:

```sh
sh tests/run
php -l Provisioner.class.php
php -l views/main.php
node --check assets/js/provisioner.js
```

Also verify that `templates/*/default.cfg` and `default.cfg.dist` remain synchronized where expected, update `module.xml`, `install.php`, and `upgrade.php` together, and manually inspect generated configs with test credentials only.

Do not include `*.tar.gz`, `__pycache__`, `.pyc`, production configuration files, SIP passwords, or temporary discovery data in commits.

## Repository layout

- `Provisioner.class.php` — FreePBX module actions and config generation.
- `lib/` — discovery, import, report, and live-status workflows.
- `templates/` — brand-specific configuration templates.
- `views/` and `assets/` — FreePBX page markup, CSS, and browser behavior.
- `tests/` — PHP, Python, JavaScript, and live-status regression coverage.
- `README.md` — feature and behavior reference.
- `docs/USER_GUIDE.md` — administrator and operator workflow.

## Security reminders

Provisioning files can contain SIP authentication data. Restrict filesystem permissions, avoid exposing directory listings, use HTTPS where supported, and redact credentials from bug reports and test fixtures. The discovery receiver is intentionally limited to identity-evidence requests and must not be changed into a configuration or firmware download endpoint without a separate security review.
