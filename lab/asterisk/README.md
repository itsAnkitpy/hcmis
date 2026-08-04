# HCIMS Phase-2 — Asterisk practice lab (sandbox)

A local-only fake phone network. **NOT part of the Laravel app** — separate Docker stack,
zero shared dependencies, so the 219-green Phase-1 baseline can't break.

- The "why": `PRD/phase-2/voice-concepts.md`
- The full plan: `PRD/phase-2/lab-setup-plan.md`

## Run / stop
```
docker compose up        # foreground — watch the boot logs
docker compose down      # stop + remove the container
```

## Check it's alive (second terminal)
```
docker compose exec asterisk asterisk -rx "pjsip show endpoints"   # are the two phones defined?
docker compose exec asterisk asterisk -rx "pjsip show contacts"    # who is registered right now?
```

## The phones (A1 ✅ · A2 ✅ · A3 Stage 1 ✅)

Two **baresip** CLI softphones, driven from the terminal (Linphone's GUI installer
wouldn't download from this network): `baresip-1002/` = the **caller**, `baresip-1001/` =
the **agent** the queue rings (auto-answers). Endpoints defined in `pjsip.conf`.

A2 added the call-center basics: a **menu** (ext 700, prompts in `sounds/`), the
**`sales` queue** (`queues.conf`), and **recording** (lands in `recordings/`; merged to
stereo MP3 with `sox` afterwards — caller left, agent right).

A3 Stage 1 added **the app driving calls**: ARI turned on (`ari.conf` + `http.conf`,
port 8088) and `ari-lifecycle.php` — a zero-dependency PHP script that answers an
inbound call (ext 800), dials the agent, bridges, records (→ `recordings-ari/`),
transfers, and hangs up. Run it per RUNBOOK §3 step 5d.

**👉 Full run-it-yourself steps + what every piece does + gotchas → see [`RUNBOOK.md`](./RUNBOOK.md).**

Quick reminder — test extensions: **600** = echo (both directions), **601** = one-way prompt,
**700** = menu → press 1 → recorded queue call to the agent, **800** = hand the call to
the ARI app (only does something while `ari-lifecycle.php` is running).
⚠️ This Mac's LAN IP is hardcoded in **3 files** — `pjsip.conf` (2 lines) and both
`baresip-100*/accounts`. On a new network: `ipconfig getifaddr en0`, update all three,
`module reload res_pjsip.so`. (Why: `lab-setup-plan.md` §6; how: RUNBOOK §5.)

## Endpoint credentials
| Ext  | Username | Password        |
| :--- | :------- | :-------------- |
| 1001 | `1001`   | `lab-pass-1001` |
| 1002 | `1002`   | `lab-pass-1002` |

ARI (the app's login, in `ari.conf`): `hcmis-lab` / `lab-pass-ari`.
All lab-only throwaways — never reuse anywhere real.
