#!/usr/bin/python3
import socket, sys, time

# Usage:
#   send_sip_notify.py <phone_ip> <pbx_ip> <mac> <uri> [phone_port] [event] [to_user]
#   - uri may be '-' or empty for a body-less NOTIFY (e.g. reboot events).
#   - event defaults to 'ua-profile' (config fetch). For a remote reboot use
#     'check-sync;reboot=true'.
#   - event ACTION-URI sends the ALE action body (normally key=Autop).
if len(sys.argv) < 5:
    print("Usage: send_sip_notify.py <phone_ip> <pbx_ip> <mac> <uri> [phone_port] [event]", file=sys.stderr)
    sys.exit(1)

phone_ip = sys.argv[1]
pbx_ip = sys.argv[2]
mac = sys.argv[3].lower()
uri = sys.argv[4]
phone_port = int(sys.argv[5]) if len(sys.argv) > 5 else 5060
event = sys.argv[6] if len(sys.argv) > 6 else 'ua-profile'
to_user = sys.argv[7] if len(sys.argv) > 7 and sys.argv[7] else mac.upper()

branch = mac[:8] if len(mac) >= 8 else mac
call_id = "pnp-%s-%d" % (mac, int(time.time()))

# A reboot NOTIFY is sent with an empty body. ALE phones reject/ignore this
# event otherwise, so the body-less variant uses the proven-working dialog
# (To/From sip:test@..., CSeq 10). Fetch NOTIFYs (with a URL body) use the
# provisioning-style headers below.
is_reboot = (not uri or uri == '-') or 'reboot' in event
is_action_uri = event.upper() == 'ACTION-URI'

if is_reboot:
    cseq = 10
    headers = (
        "NOTIFY sip:%s:%d SIP/2.0" % (phone_ip, phone_port),
        "Via: SIP/2.0/UDP %s:{src_port};branch=z9hG4bK-%s" % (pbx_ip, branch),
        "Max-Forwards: 20",
        "Contact: <sip:provisioner@%s>" % pbx_ip,
        "To: <sip:test@%s>" % phone_ip,
        "From: <sip:test@%s>" % pbx_ip,
        "Call-ID: %s" % call_id,
        "CSeq: %d NOTIFY" % cseq,
        "Event: %s" % event,
        "Content-Length: 0",
        "",
        "",
    )
elif is_action_uri:
    cseq = 1
    body = uri if uri and uri != '-' else 'key=Autop'
    headers = (
        "NOTIFY sip:%s@%s:%d SIP/2.0" % (to_user, phone_ip, phone_port),
        "Via: SIP/2.0/UDP %s:{src_port};branch=z9hG4bK-%s" % (pbx_ip, branch),
        "From: <sip:provisioner@%s>;tag=%s" % (pbx_ip, branch),
        "To: <sip:%s@%s>" % (to_user, phone_ip),
        "Call-ID: %s" % call_id,
        "CSeq: %d NOTIFY" % cseq,
        "Allow-Events: message-summary, refer, dialog, line-seize, presence, call-info, as-feature-event, calling-name, ua-profile",
        "Max-Forwards: 70",
        "Contact: <sip:provisioner@%s:{src_port}>" % pbx_ip,
        "Event: ACTION-URI",
        "Content-Type: message/sipfrag",
        "Content-Length: %d" % len(body.encode()),
        "",
        body,
    )
else:
    cseq = 1
    headers = (
        "NOTIFY sip:%s:%d SIP/2.0" % (phone_ip, phone_port),
        "Via: SIP/2.0/UDP %s:{src_port};branch=z9hG4bK-%s" % (pbx_ip, branch),
        "Max-Forwards: 20",
        "Contact: <sip:provisioner@%s>" % pbx_ip,
        "To: <sip:%s@%s>" % (mac.upper(), pbx_ip),
        "From: <sip:provisioner@%s>" % pbx_ip,
        "Call-ID: %s" % call_id,
        "CSeq: 1 NOTIFY",
        "Content-Type: application/url",
        "Event: %s" % event,
        "Content-Length: %d" % len(uri),
        "",
        uri,
    )

notify = "\r\n".join(headers)

s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
s.bind(('0.0.0.0', 0))
src_port = s.getsockname()[1]
packet = notify.format(src_port=src_port).encode()

cseq_match = ("CSeq: %d NOTIFY" % cseq).encode()
for i in range(10):
    s.sendto(packet, (phone_ip, phone_port))
    s.settimeout(0.5)
    try:
        data, addr = s.recvfrom(4096)
        if b"200 OK" in data and cseq_match in data:
            s.close()
            print("OK")
            sys.exit(0)
    except socket.timeout:
        pass

s.close()
sys.exit(1)
