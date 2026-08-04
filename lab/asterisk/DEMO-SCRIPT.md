# HCIMS — Demo Recording Guide (plain-language)

This is your step-by-step script for recording one demo video that shows everything
built so far: starting the lab, checking it's healthy, then inbound calls, outbound
calls, transfers, conference, callbacks, and reviewing recorded calls.

Plain language throughout. The first time a technical word shows up, it's explained
in brackets right there. Pairs with `RUNBOOK.md` (how to run the lab) and
`PRD/phase-2/lab-setup-plan.md` (why each piece exists).

---

## 0. The story you're telling (and the cast)

Tell it as a journey: one call's life first, then the team operations around it.
Do the beats **in this order** — each one builds on the last:

**Foundation → Who's free → Inbound call → Routing to a free agent → Callbacks →
Outbound → Transfer → Conference → Call Review.**

**The cast — say these names on camera:**

| Role | Who | Phone number (extension) | Where they show up |
| :-- | :-- | :-- | :-- |
| **Client / company** | **Demo — Acme Outbound** (client id 1) | — | Everything below happens inside Demo — Acme Outbound only |
| **Agent A** | Abhikesh — `abc@gmail.com` (user 6) | **1003** | The browser Agent Console (the agent's screen) |
| **Agent B** | Demo Agent Two — `def@gmail.com` (user 7) | **1004** | A second browser Agent Console |
| **Customer 1 / 2** | the two test phones (baresip) | **1001 / 1002** | Command-line "callers" you drive by typing — see the port warning below |
| **Inbound front door** | the number a customer dials to reach us | **800** | Hands the call into the app |

**⚠️ The two test phones answer on the opposite ports to what you'd guess.** You drive each
one by sending it a typed command on a port number, and the pairing is crossed:

| Type a command on port… | …and it comes out of phone |
| :-- | :-- |
| `5555` | **1002** |
| `5556` | **1001** |

That's how the two `config` files are written (`cons_listen`), so don't "fix" it mid-demo —
just remember `5555` is phone **1002**.

A few words you'll see a lot:
- **Lab** = the fake phone network on your Mac (no real phone line, nothing leaves the laptop).
- **Asterisk** = the switchboard software that actually routes the calls (runs inside Docker).
- **baresip** = a command-line "phone" we use to play the customer; you dial by typing a command.
- **Agent Console** = the agent's screen in the browser; it is *also* the agent's phone.
- **Disposition** = the outcome an agent picks after a call (e.g. "Sale", "No Answer").
- **Wrap-up** = the short after-call step where the agent saves the disposition.

**Not built yet — do NOT promise these on camera:**
- **Supervisor listen / whisper / barge** (a manager silently listening in) — designed, but
  deliberately put off (the ops team said it isn't daily-use).
- **Warm transfer** (talk to the other agent first, *then* hand over) — only **cold transfer**
  (hand over straight away) and **3-way conference** are built.

---

## 1. Start the phone network (the lab)

Run every lab command from inside the lab folder:

```
cd /Users/apple/projects/hcmis/lab/asterisk
```

1. **Start Docker Desktop** (the whale icon in the menu bar must be steady), then start
   the switchboard:
   ```
   docker compose up -d
   ```

2. **⚠️ The #1 thing that breaks demos — your Mac's network address.**
   Your Mac's address on the network (its "LAN IP") is written into **four** lab files.
   When you join a new Wi-Fi it changes, and then calls *connect but you hear no sound* —
   the worst thing to discover while recording. Check and fix it **every time**:
   ```
   ipconfig getifaddr en0          # shows your current address, e.g. 192.168.29.145
   ```
   If that number is different from what's in the files, update it in all four:
   - `etc-asterisk/pjsip.conf` — two lines: `external_media_address` and `external_signaling_address`
   - `etc-asterisk/rtp.conf` — the `[ice_host_candidates]` line (the right-hand side)
   - `baresip-1001/accounts` **and** `baresip-1002/accounts`

   Then tell Asterisk to re-read those settings:
   ```
   docker compose exec asterisk asterisk -rx "module reload res_pjsip.so"
   docker compose exec asterisk asterisk -rx "module reload res_rtp_asterisk.so"
   ```

3. **Check the phones exist and are reachable:**
   ```
   docker compose exec asterisk asterisk -rx "pjsip show endpoints"   # expect 1001, 1002, 1003, 1004
   ```

4. **Start the two customer phones** (these are the people who call in or get called).
   **Always kill leftovers first.** A test phone left running from a previous session keeps
   holding the ports, so the new one dies on startup and every later `/dial` answers
   `could not find UA` — the call never even reaches Asterisk:
   ```
   pkill -x baresip; sleep 2
   baresip -f baresip-1001 > baresip-1001/baresip.log 2>&1 &   # you drive this one on port 5556
   baresip -f baresip-1002 > baresip-1002/baresip.log 2>&1 &   # you drive this one on port 5555
   sleep 5; grep -h "200 OK" baresip-1001/baresip.log baresip-1002/baresip.log   # "200 OK" twice = both signed in
   ```
   **If you don't get "200 OK" twice**, look in the logs for `Address already in use` — that
   means a leftover phone survived the `pkill`:
   ```
   grep -ahE "Address already in use|init failed" baresip-100*/baresip.log
   ```

5. **Sound check before you ever go live** — the echo test plays your own voice back,
   which proves sound travels both ways:
   ```
   echo "/dial 600" | nc -u -w5 127.0.0.1 5555
   docker compose exec asterisk asterisk -rx "pjsip show channelstats"   # both Receive and Transmit go up, 0 lost
   echo "/hangupall" | nc -u -w1 127.0.0.1 5555
   ```
   If the numbers climb with 0 lost, your sound path is healthy. If not, it's the
   address from step 2 — fix that first, before looking at anything else.

---

## 2. Start the app (the brain)

Three things must be running. **`composer run dev` does NOT start the call brain** —
that runs in its own terminal. Don't skip it.

**Terminal 1 — the website + background worker + logs:**
```
cd /Users/apple/projects/hcmis
composer run dev
```
This starts the website (http://127.0.0.1:8000), a **background worker**, live logs, and
the screen builder, all together. The background worker matters: the final recording is
**stitched together after the call by a background job** — if the worker isn't running,
no recording ever appears (a lesson learned the hard way).

**Terminal 2 — the call brain (the switchboard controller):**
```
cd /Users/apple/projects/hcmis
php artisan telephony:listen
```
This holds the line open to the switchboard and runs every call: ring, answer, join the
two people, record, transfer, hang up. **If this isn't running, dialing 800 just ends with
nothing happening** — the call never reaches the app. Keep this terminal on screen during
the demo; its running commentary is your proof of what's happening behind the scenes.

**Confirm the brain is connected:**
```
docker compose exec asterisk asterisk -rx "ari show apps"   # expect 'hcmis-lab' in the list
```

---

## 3. Pre-flight checklist (run this before you hit record)

Tick every box. This is the "check everything before making any call" list:

- [ ] **No leftover test phones from a previous session:** `pgrep -x baresip | wc -l` → expect
      exactly **2**, and both started just now. A phone left running for days is the #2 demo
      killer (right behind the address drift) — it blocks the fresh one from starting
- [ ] Docker running; `pjsip show endpoints` shows **1001, 1002, 1003, 1004**
- [ ] **Mac address matches** `ipconfig getifaddr en0` in all four files (or you fixed and reloaded it)
- [ ] Echo test (600) → numbers climb both ways, **0 lost**
- [ ] `pjsip show contacts` → the test phones are **signed in** (idle phones quietly drop off — check here, don't guess)
- [ ] Terminal 1: `composer run dev` running (website + **background worker** + logs)
- [ ] Terminal 2: `telephony:listen` running; `ari show apps` shows **hcmis-lab**
- [ ] Browser A: signed in as **abc@gmail.com**, Agent Console open, phone shows **signed in / Ready**
- [ ] Browser B (for the transfer and conference beats): signed in as **def@gmail.com**, console **Ready**
- [ ] You're inside client **Demo — Acme Outbound** (shown in the top bar) in both browsers
- [ ] (Nice to have) a Demo — Acme Outbound lead whose phone number matches a test phone, so the inbound call
      shows a **matched customer card** instead of "unknown caller"

**Two agents = two browser sessions.** Use two different browsers, or one normal window
and one private/incognito window, so the two logins don't clash. Each console
automatically becomes its own phone (Agent A → 1003, Agent B → 1004) based on who's
signed in.

---

## 4. The demo, step by step

For each step: **Say** (your line), **Do** (the action), **Show** (point at what's
happening behind the scenes — this is your signature; don't skip it).

### Step 1 — Foundation: one platform, many clients, controlled by role
- **Say:** "Everything runs on one platform shared by many clients — each client's data is
  walled off, and what you can do depends on your role."
- **Do:** Sign in at `http://127.0.0.1:8000/admin`. Show that **Demo — Acme Outbound** is the selected client.
  Show that an agent only sees the Agent Console, while a manager / Team Leader / quality
  checker sees more (leads, campaigns, history, call review).
- **Show:** Switch the top-bar client picker to a different client for a second — the leads
  change completely. That's the data wall between clients, live.

### Step 2 — Who's free (the availability board)
- **Say:** "Before any call is routed, the system already knows who's available — in real time."
- **Do:** In Agent A's console, point at the status (**Ready**). Click **Take a break** →
  the card flips to On-break; click **I'm back** → Ready.
- **Show:** This is a real status saved on the server, not just a screen toggle. Mention the
  "still here" signal: "the screen quietly checks in every ~15 seconds; if the agent closes
  the tab, within about a minute they show as Offline — so a crashed screen can't keep
  pretending it's Ready."

### Step 3 — An inbound call, start to finish
- **Say:** "A customer calls in. Watch the call find an agent and get recorded."
- **Do:** From a customer phone, dial the front door:
  ```
  echo "/dial 800" | nc -u -w1 127.0.0.1 5555   # port 5555 = phone 1002
  ```
  Agent A's tab **rings** → shows the caller (and the matched customer card if you seeded
  one) → click **Answer** → talk both ways → show **Mute**, and **Hold** (note the caller
  hears silence, not music — hold music is a later item). Hang up.
- **Show:** Read the running commentary in the **`telephony:listen`** terminal — caller
  arrives → the agent's phone rings → both are joined → recording starts. Then do the
  **wrap-up**: pick an outcome. Point out the real effect behind the scenes: the **lead's
  status and attempt count actually change**, and a **history entry is written** for
  compliance. "A record really changing — not just a form that looks pretty." Note the call's
  **recording is being stitched together in the background** — you'll come back and **play this
  very inbound call in Call Review at Step 9** (inbound playback now works, not just outbound).

### Step 4 — Routing to a free agent (more than one agent)
- **Say:** "With several agents, a call goes to whoever's free — and skips anyone who's busy
  or on a break."
- **Do:** Have both A and B set to **Ready**. Fire two callers one after the other:
  ```
  echo "/dial 800" | nc -u -w1 127.0.0.1 5555   # phone 1002 → one agent
  echo "/dial 800" | nc -u -w1 127.0.0.1 5556   # phone 1001 → the other agent
  ```
  Each rings a *different* agent, side by side. Now put B **On break** and call again — it
  rings A only. With both busy, a third call **ends cleanly with "all agents busy."**
- **Show:** The `telephony:listen` commentary names which agent it picked for each call, and
  the "all busy" line. Point at the availability board putting everyone back to **Ready**
  afterwards — no agent left stuck showing "on a call."

### Step 5 — Shared callbacks ("call me back")
- **Say:** "If a customer asks for a callback, it drops into a shared pool any free agent can grab."
- **Do:** During a wrap-up, tick **add to the callback pool**. Show it appear in the
  **Pooled callbacks** panel (within ~15 seconds). Click **Grab** — it moves into "My due
  callbacks." From the second agent's screen, show the same item disappear from *their* pool
  on its own.
- **Show:** The grab is written to history (who grabbed which callback). Mention two agents
  can never grab the same one — the system hands it to exactly one.

### Step 6 — Outbound calling from a lead (with Do-Not-Call protection)
- **Say:** "Agents also dial out. The system serves the next lead, checks the Do-Not-Call
  list, dials, records, and makes the agent save an outcome."
- **Do:** In Agent A's console, a **served lead** appears (name, phone, campaign, last
  outcome). Click **Dial** → the customer phone rings → answer it → talk → hang up →
  wrap-up. Also show **Skip** (jump to the next lead without calling) and typing a number
  by hand.
- **Show (the depth — this is the strong part):**
  - **Do-Not-Call block** — try dialing a number you've added to this client's Do-Not-Call
    list: it's **blocked, automatically marked "DNC", and never dialed.**
  - **No Answer** — let a call ring out: it **opens the wrap-up** with a real "No Answer"
    outcome (a genuine agent action, not a dead end).
  - The outbound call produces a finished **recording** (caller on one channel, agent on the
    other) — this is the one you'll play in Step 9.

### Step 7 — Cold transfer (hand the caller to another free agent)
- **Say:** "An agent can hand a live caller straight to another free agent."
- **Do:** On a live call at Agent A, click **Transfer**. Agent B (Ready, 1004) rings while A
  keeps talking; B answers → A drops to wrap-up → **the caller is now with B**, with no break
  in the audio.
- **Show:** The `telephony:listen` commentary shows B added and A dropped, and the
  **recording carries straight through** — one continuous file across the hand-over. Show the
  honest edges too: if B doesn't answer, **A keeps the caller**; if nobody's free, **A keeps
  the caller**.

### Step 8 — 3-way conference (pull a second agent in)
- **Say:** "Or an agent can pull a second agent *into* the call — all three talk together."
- **Do:** On a live call at A, click **Conference**. B rings, answers → **caller + A + B all
  on one call** (the difference from transfer is that A stays). Hang up A → caller and B keep
  talking; the last agent leaves → the call ends cleanly.
- **Show:** One continuous recording covers the whole three-way. Note it's **limited to 3
  people** today (on purpose). B must be **Ready** to be pulled in (on a break = no ring).

### Step 9 — Call Review (find a past call, play or download it)
- **Say:** "A Team Leader or quality checker can find any past call and play or download its
  recording — and every play is logged."
- **Do:** Sign in as a manager / **Team Leader** / **quality checker**, open **Call Review**
  at `/admin/calls`. Filter (by agent, client, date, outcome, or has-recording). Open the
  **inbound** call from Step 3 **or** the **outbound** call from Step 6 → click **Play** (it
  plays in the browser) and **Download**. Playing the **inbound** call is the strong beat —
  it's the newest piece: inbound recordings now attach and play, not just outbound.
- **Show (the depth):**
  - **Client wall** — another client's call **isn't even visible**, and its recording link
    gives a "not found" error.
  - **Role check** — an ordinary **agent** can't open Call Review at all.
  - **History** — every play or download writes an access record (who listened to what,
    because recordings are sensitive).
  - **Inbound playback now works too** — an inbound call's recording attaches to its record at
    ring-time and shows a **Play** button just like outbound. (This was the latest piece built;
    before it, inbound calls showed "no recording.")

---

## 5. Things to keep in mind while recording (the live gotchas)

- **Recordings appear a few seconds *after* you hang up** — the stitching happens in the
  background. Wait, then refresh Call Review. (No background worker = it never appears —
  re-check Terminal 1.)
- **If sound dies in the middle**, it's almost always the **Mac address drift** — stop,
  fix the four files, reload `res_pjsip` and `res_rtp`, and re-start the test phones. Don't
  debug anything else first.
- **Idle test phones quietly sign themselves out.** If a call won't ring, run
  `pjsip show contacts`. No entry = re-start that phone. Don't assume it's a software bug.
- **Start things in this order:** Asterisk → test phones → `telephony:listen` → browser
  consoles. If dialing 800 "just ends," the brain isn't connected (`ari show apps`).
- **Transfer and Conference need Agent B Ready** in a second browser. Set that up *before*
  you start the call.
- **Don't oversell** — no supervisor listening and no warm transfer yet. Frame those as
  "coming next." (Inbound-call playback **is** built now — that one's fair game.)

---

## 6. Between takes / shutting down

Reset to a clean slate without losing the lab settings:
```
echo "/hangupall" | nc -u -w1 127.0.0.1 5555      # drop any stuck call on phone 1002
echo "/hangupall" | nc -u -w1 127.0.0.1 5556      # ...and on phone 1001 — do both, or a call
                                                  # can sit open for days and jam the next session
docker compose exec asterisk asterisk -rx "core show channels"   # expect 0 active
# still not 0? force it:
docker compose exec asterisk asterisk -rx "channel request hangup all"
```
Full stop at the end:
```
pkill -x baresip          # stop the test phones
docker compose down       # stop the switchboard
# press Ctrl-C in the two app terminals (composer dev and telephony:listen)
```

---

## 7. Recording tips

- **Get everything in Section 3 ready first, then start recording at Step 1.** Fixing the
  Mac address on camera is the one thing that looks "broken."
- **Keep the `telephony:listen` terminal in a corner of the screen** — its running
  commentary is the proof of depth that sets your demos apart.
- **Do the "Show" line in every step** — the lead changing, the history entry, the finished
  recording, the other client's "not found." That's what tells viewers this is a real
  platform, not a screen mock-up.
- **Do one full practice run end-to-end before the real take** — it surfaces the idle phones
  signing out and the few-second recording delay, so neither surprises you live.
