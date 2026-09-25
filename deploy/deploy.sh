#!/usr/bin/env bash
# HCIMS — deploy the checked-out branch to this box, then restart everything that runs code.
#
# Why this exists: twice a deploy left a long-running process on old code. The queue worker
# failed to unserialize a job that named a new enum case (S175), and the call listener kept
# giving callers the old flow (progress ~619, ~747). Every step below runs every time, so
# nothing depends on remembering which ones this change needed.
#
# Run as hcmis, from anywhere:  ~/public_html/deploy/deploy.sh
# Stops at the first failure and names the step. Nothing is restarted until the code,
# packages, database and caches are all in place, so a failure leaves the old processes running.

set -euo pipefail

APP=/home/hcmis/public_html
PHP=/usr/bin/php8.4

step=start
trap 'echo "DEPLOY FAILED at step: $step (line $LINENO)" >&2; exit 1' ERR
say() { step=$1; printf '\n==> %s\n' "$1"; }

# Sets $n to the switchboard's live channel count. Channels, not calls: a caller on hold or
# leaving a message is one channel that Asterisk may not count as a call.
# A dead Asterisk fails the number check, never reads as zero. Called as a plain statement
# only, so that failure stops the script: inside an if/while, set -e is off.
live_channels() {
    n=$(sudo asterisk -rx 'core show channels count' | awk '/active channel/ {print $1}') || true
    [[ $n =~ ^[0-9]+$ ]] || { echo "Could not read the live channel count from Asterisk" >&2; return 1; }
}

# Root would own every file it writes, and the next pull as hcmis would fail.
[ "$(id -un)" = hcmis ] || { echo "Run as hcmis, not $(id -un)" >&2; exit 1; }
cd "$APP"

say "sudo password"
sudo -v

say "live calls"
live_channels
[ "$n" -eq 0 ] || { echo "$n live channels. Nothing changed, deploy later." >&2; exit 1; }

say "pull"
branch=$(git rev-parse --abbrev-ref HEAD)
old=$(git rev-parse HEAD)
git pull --ff-only
new=$(git rev-parse HEAD)

say "composer install"
# Through php8.4 by name: composer's own shebang would find the box's php8.3.
"$PHP" "$(command -v composer)" install --no-interaction

say "npm ci"
npm ci

say "migrate"
"$PHP" artisan migrate --force

say "build"
npm run build

say "optimize"
"$PHP" artisan optimize

say "watchdog"
# Cron runs root's copy, not the repo file. -C leaves it untouched when nothing changed.
sudo install -C -o root -g root -m 755 deploy/telephony-watchdog.sh /usr/local/sbin/hcmis-telephony-watchdog

say "restart php8.4-fpm and hcmis-queue"
# php8.3-fpm is left alone on purpose.
sudo systemctl restart php8.4-fpm hcmis-queue

say "restart hcmis-telephony"
# Restarting the listener drops every live call, so wait out any that began during the build.
while :; do
    live_channels
    [ "$n" -eq 0 ] && break
    echo "  $n live channels, waiting..."
    sleep 5
done
sudo systemctl restart hcmis-telephony

say "check services"
# A unit that dies on boot (a missing class, a bad config value) shows as not active here.
sleep 3
for unit in php8.4-fpm hcmis-queue hcmis-telephony; do
    systemctl is-active --quiet "$unit" || { echo "$unit is not running" >&2; false; }
done

printf '\nDeployed %s %s..%s in %ss\n' "$branch" "${old:0:7}" "${new:0:7}" "$SECONDS"
