#!/bin/sh
# HCIMS — restart the call-event listener if it has frozen (S119 A1).
#
# systemd already restarts the listener if it DIES (Restart=always in
# deploy/systemd/hcmis-telephony.service). Nothing notices it FREEZING while still alive:
# the process is in the process list, systemd is happy, but the loop has stopped turning
# and inbound calls go unanswered with no alarm anywhere.
#
# The listener touches BEAT every few seconds (TelephonyListen::beat). If that mark goes
# stale, the loop is not turning, so restart the service.
#
# Install as ROOT (systemctl restart needs it), one line, once a minute:
#   * * * * * /home/hcmis/public_html/deploy/telephony-watchdog.sh
#
# It writes nothing to stdout on a healthy pass, so cron stays silent unless it acts.

set -eu

BEAT=/home/hcmis/public_html/storage/app/telephony-heartbeat

# 120s, against a listener that beats at most every 5s and at worst every ~30s while it
# waits out a reconnect backoff. Wide on purpose: restarting a HEALTHY listener throws away
# every live call the switchboard is holding, which is worse than a minute of being frozen.
STALE_AFTER=120

# No mark at all means the listener has not run since this was deployed. Starting it is
# systemd's job, not ours — acting here would fight a service somebody stopped deliberately.
[ -f "$BEAT" ] || exit 0

# Only judge a service systemd believes is up. A stopped one is not frozen, it is stopped.
if ! systemctl is-active --quiet hcmis-telephony; then
    exit 0
fi

AGE=$(( $(date +%s) - $(stat -c %Y "$BEAT") ))

if [ "$AGE" -lt "$STALE_AFTER" ]; then
    exit 0
fi

logger -t hcmis-telephony-watchdog "heartbeat is ${AGE}s old (limit ${STALE_AFTER}s) — restarting hcmis-telephony"
systemctl restart hcmis-telephony
