# Asterisk lab — RUNBOOK (run it yourself, every time)

Everything you need to **start, use, and stop** the local phone lab — plus what every
piece actually does. Plain language. This is the operator guide; the *why-it-works*
detail lives in `PRD/phase-2/lab-setup-plan.md` (§6 = the Mac/Docker audio fix).

> All commands below assume you're inside the lab folder:
> ```
> cd /Users/apple/projects/hcmis/lab/asterisk
> ```

---

## 1. The cast — what each thing is and does

### The switchboard side (in Docker)
| Thing | Role (plain) |
| :-- | :-- |
| **Docker Desktop** | The engine that runs the sealed "box". Must be running before anything else. |
| `docker-compose.yml` | The recipe that starts the box: which image, which ports to open, which config folder to mount. |
| **`andrius/asterisk:20`** (image) | A ready-made box containing Asterisk 20 (built for your M1, no emulation). |
| **Asterisk** | The **switchboard operator**. Routes calls, plays menus, holds queues, records. Our voice engine. |
| `etc-asterisk/` → `/etc/asterisk` | The switchboard's whole settings folder (mounted into the box so edits are live). |

### The settings files inside `etc-asterisk/`
| File | Who owns it | Role |
| :-- | :-- | :-- |
| `pjsip.conf` | **us** | Defines the **phones** (1001, 1002: login + password), the network transport, the **Mac/Docker NAT fix**, and the 30s **phone ping** (`qualify_frequency` — keeps registrations alive, see §5.7). |
| `extensions.conf` | **us** | The **dialplan** — "when someone dials X, do Y". Holds 1001/1002, test extensions 600/601, and the **A2 menu** (700 → press 1 → queue). |
| `queues.conf` | **us** | The **call queues** — callers wait here for an agent. One lab queue `[sales]` whose agent is phone 1001. (Without this file, queueing refuses to start.) |
| `rtp.conf` | **us** | The **voice port range** (10000–10019) — kept small so it maps cleanly on Mac. |
| `http.conf` | **us** | Turns on Asterisk's **mini web server** (port 8088) — ARI rides on it. |
| `ari.conf` | **us** | Turns on **ARI** (the "app drives calls" API) + the app's login (`hcmis-lab`). |
| `asterisk.conf` | image | Core paths/options. **Don't edit.** (copied from the image so the dir-mount still boots) |
| `modules.conf` | image | Which Asterisk features load (`autoload=yes`). **Don't edit.** |
| `logger.conf` | image | Logging settings. **Don't edit.** |

### The phone side (on your Mac, not in Docker)
| Thing | Role (plain) |
| :-- | :-- |
| **baresip** | A **command-line softphone**. We run **two**: `baresip-1002` = the **caller**, `baresip-1001` = the **agent** the queue rings (it auto-answers). |
| `baresip-100X/config` | That phone's settings: which features load, headless audio, its local ports. |
| `baresip-100X/accounts` | That phone's **login** — who it signs in as, at your Mac's LAN IP. |
| `baresip-100X/rx.wav` | Where that phone **records the audio it hears** (proof audio flowed). |
| `baresip-100X/baresip.log` | That phone's log — check here for "200 OK" (registered) or errors. |
| **ausine** (baresip module) | A **fake microphone** — a steady tone instead of a real mic. Caller = 440 Hz, agent = 660 Hz, so you can tell who's who in recordings. |
| **aufile** (baresip module) | A **fake speaker** — writes received audio to `rx.wav`. |
| **cons** (baresip module) | A small **remote control** — caller listens on UDP `5555`, agent on `5556`. |
| **`nc`** (netcat) | The tool we use to send those commands to baresip's console. |

### The recording side
| Thing | Role (plain) |
| :-- | :-- |
| `sounds/` → `/var/lib/asterisk/sounds/en` | Our **menu prompts** (the image ships none). Made with the Mac's `say` command. |
| `recordings/` → `/var/spool/asterisk/monitor` | Where Asterisk's call recordings land on the Mac: per call, a mixed file + `-caller` (what they said) + `-agent` (what they heard). |
| `recordings-ari/` → `/var/spool/asterisk/recording` | Where **ARI-made** recordings land (a *different* folder than MixMonitor's — and the image ships without it; the mount creates it). |
| **sox** (`brew install sox`) | Merges the two single-side files into **one stereo MP3** (caller = left, agent = right — the locked PW5-4 format). |

### The app side (A3 — the script that drives calls)
| Thing | Role (plain) |
| :-- | :-- |
| **ARI** | Asterisk's **remote-control socket** for apps: one event stream ("a call arrived") + plain web requests ("answer it", "record", "hang up"). |
| `ari-lifecycle.php` | A **zero-dependency PHP script** that runs a whole call as "the app": answer → dial agent → bridge → record start/stop → transfer → hang up → download the recording. Its WebSocket reader is hand-rolled on purpose — design input for Track B1. |
| `ari-stereo-experiment.php` | The **B1 recording experiment** (2026-06-12): proved direct channel-record is refused while bridged, and that **snoop channels** give the per-side files for the PW5-4 stereo (spy=in = what they say, spy=out = what they hear). Run like `ari-lifecycle.php` (script first, then dial 800); files land in `recordings-ari/`. |
| ext `800` | The app's **front door** in the dialplan: `Stasis(hcmis-lab)` parks the call with the app; from then on the app decides everything. |

### The browser phone (A4 — the browser tab IS a phone)
| Thing | Role (plain) |
| :-- | :-- |
| `webrtc/index.html` | The **browser-phone page**: registers as ext **1003** over a WebSocket, dials with buttons (600 echo / 1001 agent / 700 menu + an in-call 1/2 keypad). Serve it with `php -S 127.0.0.1:8089 -t webrtc`, open `http://127.0.0.1:8089`. Auto-mode for scripts: `?dial=600&secs=12`. |
| `webrtc/jssip-3.13.8.bundle.min.js` | The SIP library the page uses (JsSIP 3.13.8), bundled to one self-contained file (npm ships no browser build anymore — see lab plan §6e). No CDN at runtime. |
| ext **1003** (`pjsip.conf`) | The browser's endpoint: `webrtc=yes` turns on everything browsers mandate (ICE, DTLS encryption with an auto-made cert). Outbound-only — nothing in the dialplan rings 1003. |
| `[ice_host_candidates]` (`rtp.conf`) | The **WebRTC flavor of the NAT fix**: swaps the container's unreachable IP for the Mac's LAN IP in the voice-path offer. ⚠️ Carries BOTH the container IP and the LAN IP — see §5.1. |
| Why no HTTPS | Browsers treat `127.0.0.1` as already-secure (mic + plain `ws://` allowed). **Lab-only** — production needs `wss://` + a real cert. |

### The ports (who talks on what)
| Port | Used by | For |
| :-- | :-- | :-- |
| `5060/udp` | Asterisk | SIP — the call "setup talk" (ring/answer/hangup). |
| `10000–10019/udp` | Asterisk | RTP — the actual **voice** (incl. the browser's encrypted WebRTC media). |
| `5092/udp` | baresip 1002 | the caller phone's own SIP port (not 5060, to avoid clashing with Docker's forward). |
| `5093/udp` | baresip 1001 | the agent phone's SIP port. |
| `5555/udp` | baresip 1002 | the caller phone's command console. |
| `5556/udp` | baresip 1001 | the agent phone's command console. |
| `8088/tcp` | Asterisk | ARI — events + commands for the app (basic-auth `hcmis-lab`) — **and** the browser phone's SIP WebSocket (path `/ws`). |
| `8089/tcp` | php -S | serves the browser-phone page (only while you run it). |

### The test extensions (what to dial)
| Dial | What happens |
| :-- | :-- |
| `600` | **Echo** — Asterisk sends back whatever it hears (proves audio **both** ways). |
| `601` | Plays our menu prompt (proves audio **one** way: Asterisk → phone). |
| `700` | **The A2 flow** — menu answers; press `1` → recorded call into the `sales` queue → rings agent 1001; press `2` → echo. |
| `800` | **The A3 flow** — hands the call to the ARI app (`ari-lifecycle.php` must be running, else the call just ends). |
| `1001` / `1002` | Rings that phone directly (phone-to-phone — proven 2026-06-12). |
| *(from the browser page)* | Ext **1003** dials any of the above — same dialplan, same context. The A4 proof call was browser → 1001. |

---

## 2. Prerequisites (one-time)
- **Docker Desktop** installed (`docker --version`).
- **baresip** installed (`brew install baresip`).
- That's it — Asterisk comes from the Docker image; nothing is installed into the Laravel app.

---

## 3. Run it — every-time sequence

```
cd /Users/apple/projects/hcmis/lab/asterisk

# 1) Make sure Docker Desktop is running (whale icon steady), then start the switchboard
docker compose up -d

# 2) Confirm Asterisk is alive and the two phones are defined
docker compose exec asterisk asterisk -rx "pjsip show endpoints"      # expect 1001, 1002

# 3) IF your network changed since last time, refresh the LAN IP (see §5) — otherwise skip

# 4) Start BOTH phones — agent (1001, auto-answers) first, then caller (1002)
baresip -f baresip-1001 > baresip-1001/baresip.log 2>&1 &
baresip -f baresip-1002 > baresip-1002/baresip.log 2>&1 &
sleep 5; grep -h "200 OK" baresip-1001/baresip.log baresip-1002/baresip.log   # both registered?

# 5a) Quick audio check: the echo call (both RTP directions)
echo "/dial 600" | nc -u -w5 127.0.0.1 5555
docker compose exec asterisk asterisk -rx "pjsip show channelstats"    # Receive + Transmit climb, 0 lost
echo "/hangupall" | nc -u -w1 127.0.0.1 5555

# 5b) The full A2 flow: menu → press 1 → queue → agent answers → recorded
echo "/dial 700" | nc -u -w1 127.0.0.1 5555
sleep 5; echo "1" | nc -u -w1 127.0.0.1 5555                           # press 1 at the menu
docker compose exec asterisk asterisk -rx "queue show sales"           # agent should show "in call"
sleep 15; echo "/hangupall" | nc -u -w1 127.0.0.1 5555                 # talk a while, then hang up
ls recordings/                                                          # 3 new WAVs per call

# 5c) Make the keepable recording: one stereo MP3 (caller left, agent right)
cd recordings && sox -M <stamp>-caller.wav <stamp>-agent.wav -C 32.2 <stamp>-stereo.mp3
afplay <stamp>-stereo.mp3                                               # listen check

# 5d) The A3 flow: the APP runs the whole call (answer/dial/bridge/record/transfer/hangup)
php ari-lifecycle.php &                                                 # 1st: start the app
sleep 3; echo "/dial 800" | nc -u -w1 127.0.0.1 5555                    # 2nd: customer calls in
# watch its log lines; ends itself with PASS in ~30s; recording → recordings-ari/

# 5e) The A4 flow: the BROWSER as a phone (WebRTC)
php -S 127.0.0.1:8089 -t webrtc &                                       # serve the page
open http://127.0.0.1:8089                                              # opens in your browser
# click "Dial 600" and talk — you hear yourself (echo). Or "Dial 1001" to ring the agent.
# Scripted proof (no clicking; agent 1001 must be running):
#   "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new \
#     --use-fake-ui-for-media-stream --use-fake-device-for-media-stream \
#     --autoplay-policy=no-user-gesture-required --user-data-dir=/tmp/chrome-a4 \
#     "http://127.0.0.1:8089/?dial=1001&secs=20" &
#   then: docker compose exec asterisk asterisk -rx "pjsip show channelstats"
#   expect ~50 packets/s each direction on both legs, ~0% loss; agent's rx.wav gets audio.

# 6) Stop the phones
pkill -x baresip

# 7) Stop the switchboard (or leave it running)
docker compose down
```

---

## 4. Handy commands

**Asterisk** (`docker compose exec asterisk asterisk -rx "<command>"`):
| Command | Shows |
| :-- | :-- |
| `pjsip show endpoints` | The phones and whether they're in use |
| `pjsip show contacts` | Who is registered right now (and from what address) |
| `pjsip show channelstats` | Live call packet counts per direction |
| `core show channels` | Active calls |
| `queue show sales` | The queue: waiting callers + whether the agent is reachable |
| `dialplan show internal` | What each dialed number does (also: `dialplan show lab-ivr` for the menu) |
| `module reload res_pjsip.so` | Re-read `pjsip.conf` after an edit (phones/NAT) |
| `dialplan reload` | Re-read `extensions.conf` after an edit |
| `module reload app_queue.so` | Re-read `queues.conf` after an edit |
| `ari show apps` | Which ARI apps are connected right now (the script when running) |
| `ari show users` | The ARI logins Asterisk accepts |

**ARI from the Mac** (is the app door open?):
```
curl -s -u hcmis-lab:lab-pass-ari http://127.0.0.1:8088/ari/asterisk/info   # JSON = yes
```

**baresip** (`echo "<cmd>" | nc -u -w1 127.0.0.1 5555` — caller; use port `5556` for the agent):
| Command | Does |
| :-- | :-- |
| `/dial 700` | Call the menu (or `600` for echo) |
| `1` (no slash) | Press a key — sends the touch-tone digit during a call |
| `/hangupall` | Hang up all calls |

---

## 5. Must-remember (the things that bite)

1. **The LAN IP is hardcoded in FOUR files and changes with your network.**
   `pjsip.conf` (two `external_*` lines), **both** `baresip-1001/accounts` +
   `baresip-1002/accounts`, **and** `rtp.conf` (`[ice_host_candidates]`, the browser
   phone's voice path — added in A4). On a new Wi-Fi/network, calls connect but
   **audio dies** (hit on 2026-06-12 — twice: .124→.135 and .135→.210). Fix:
   ```
   ipconfig getifaddr en0                       # get the new IP
   # put it in etc-asterisk/pjsip.conf (2 lines), etc-asterisk/rtp.conf (1 line),
   # AND baresip-100*/accounts, then:
   docker compose exec asterisk asterisk -rx "module reload res_pjsip.so"
   docker compose exec asterisk asterisk -rx "module reload res_rtp_asterisk.so"
   # restart the baresips if they were running
   ```
   ⚠️ `rtp.conf`'s line also carries the **container's** IP on the left side
   (`172.19.0.2 => LAN-IP`) — that one only changes if the compose network is
   recreated; check with `docker inspect hcmis-lab-asterisk` (Networks → IPAddress).
2. **Config is a folder mount, so edits are live** — after editing a file in
   `etc-asterisk/`, just `reload` (don't rebuild). *Exception:* changing the
   **transport** sometimes needs a full `docker compose restart asterisk`.
3. **Point phones at the LAN IP, not `127.0.0.1`** — baresip can't source calls from loopback.
4. **Don't restart baresip too fast** — after `pkill`, wait for its ports to free
   (`lsof -nP -iUDP:5092 -iUDP:5555`) or the restart fails with "address in use".
5. **Register first, then dial** — dialing before registration finishes fails with "could not find UA".
6. **The lab is a separate sandbox** — never add its tools as dependencies of the Laravel app;
   it lives in `lab/` (git-excluded) with its own Docker stack. The 219-green Phase-1 baseline stays safe.
7. **Phones quietly "fall off" when idle.** Left alone, the baresips stop renewing their
   registration (no error in their logs!) — Asterisk then drops them, and the **queue
   refuses to ring an agent it can't reach** ("Unavailable"). The config now defends this
   twice: Asterisk pings every phone each 30s (`qualify_frequency=30` in `pjsip.conf`) and
   the phones re-register every minute (`regint=60` in `accounts`). If a queue ever says
   the agent is Unavailable, check `pjsip show contacts` first — no contact = not reachable.
8. **The recording is 3 files + 1 made later.** Per recorded call Asterisk writes a mixed
   WAV plus `-caller` (what they said) and `-agent` (what they heard). The keepable artifact —
   stereo MP3, caller left / agent right (locked format PW5-4) — is made **after** the call
   with `sox` on the Mac. ~0.24 MB/min at 32 kbps vs ~1.9 MB/min raw.
9. **ARI recordings need their own folder — and the image doesn't have it.** ARI writes to
   `/var/spool/asterisk/recording` (NOT MixMonitor's `monitor`); the lean image ships without
   that dir, so a record request fails with a bare **HTTP 500** and nothing useful in the log
   (hit 2026-06-12). The `recordings-ari/` mount in compose creates it. Also: adding the 8088
   port was a **container recreate** (`docker compose up -d`), not a reload — registrations
   drop for up to ~60s after (qualify/regint bring them back; check `pjsip show contacts`).
10. **Run the ARI script BEFORE dialing 800.** Connecting its event stream is what registers
   the app — if nobody's connected, `Stasis(hcmis-lab)` falls through and the call just ends.

---

## 6. Reset / clean slate
```
pkill -x baresip                       # stop both phones
docker compose down                    # stop + remove the container
rm -f baresip-100*/rx.wav              # clear the phones' own ear-recordings (optional)
rm -f recordings/*                     # clear Asterisk's call recordings (optional)
```
Config files in `etc-asterisk/`, `baresip-1001/`, `baresip-1002/`, and the prompts in
`sounds/` persist — that's intended; they're the lab.
