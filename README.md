FreePBX Provisioner
Endpoint provisioning module for FreePBX 17. It manages phone-to-extension mappings, generates configuration files, discovers supported phones, and deploys configurations using DHCP Option 66 or SIP NOTIFY.
Supported Phones
- Digium
- Alcatel
- Yealink
Support depends on the available templates and phone firmware.
Features
- MAC-to-extension mapping
- Brand-specific config generation
- DHCP and network discovery
- PJSIP extension matching
- SIP NOTIFY deployment
- Automatic addition of supported discovered phones
- Safe extension swaps
- Pulled custom-config backup and restoration
- Config extension and assignment comparison
- Atomic config activation and rollback
- Phone status, diagnostics, logs, and bulk actions
Requirements
- FreePBX 17
- PHP 8.2+
- Python 3 with requests
- Node.js for JavaScript syntax tests
- Network access between the PBX and phones
Installation
Upload and install the module package through FreePBX Module Admin, or extract it into:
/var/www/html/admin/modules/provisioner
Then run:
fwconsole ma install provisioner
fwconsole reload
Configure the PBX IP, provisioning directory, deployment mode, phone credentials, and network scan settings under Settings > Provisioner.
Tests
Run the dependency-free regression suite with:
/var/www/html/admin/modules/provisioner/tests/run
The suite uses temporary fixtures and does not modify production phone configs or mappings.
Notes
Pulled phone configurations may contain SIP credentials. Protect the provisioning directory and module backups appropriately.
SIP 200 OK confirms acceptance of a NOTIFY request, not necessarily that the phone downloaded or applied its configuration.
License
GPLv3
