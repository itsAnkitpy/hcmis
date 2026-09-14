<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one place a staff member's phone is written (SEC-1, PP-5).
 *
 * A phone is three rows in Asterisk's own tables, and they only work together:
 * the endpoint (the phone), the auth (its password) and the aor (where it can be
 * reached). Writing them from more than one place is how they drift apart, so this
 * mirrors the doctrine that `recordCall()` is the one place a `calls` row is written.
 *
 * Idempotent by design. Called twice for the same person it writes the same three
 * rows again rather than a second phone, and it does NOT re-cut the password — Q7's
 * O6 says an agent's key stays put for as long as they hold the role. The one case
 * where a fresh password IS minted is a re-issue after PP-19 destroyed the old one:
 * an auth row with a blank password reads as "no key", so re-issuing costs one call.
 *
 * 🔴 Every table name here is schema-qualified (RV-3). The app's connection pins
 * `search_path = public` (config/database.php) and these tables live in the
 * `asterisk` schema, so an unqualified name does not resolve. PP-3 granted the
 * rights; the qualifier is what finds the table.
 *
 * 🔴 The password is stored in plain text, and that is not an oversight. Asterisk
 * digest auth is computed FROM the stored secret, so holding a hash is holding the
 * password (F2). Nothing is gained by hashing it and the switch could not read it.
 */
class AgentPhoneWriter
{
    /**
     * Extensions are handed out from here upwards, never reused (Q6 rule 2).
     * 1003, 1004 and 1005 belong to the hand-written file era; starting at 1100
     * keeps the two eras from colliding and makes a number's origin obvious.
     */
    private const POOL_FLOOR = 1100;

    /**
     * Give this user a working phone, and return the extension it answers on.
     * Their existing extension is kept if they have one — a person's number never
     * changes, because their call history is read back against it (Q6 rule 2).
     */
    public function provisionFor(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            $extension = $user->sip_extension ?? $this->nextExtension();
            $authId = $this->authIdFor($extension);

            DB::table('asterisk.ps_endpoints')->upsert([[
                'id' => $extension,
                'context' => 'internal',
                'disallow' => 'all',
                'allow' => 'ulaw',
                'auth' => $authId,
                'aors' => $extension,
                'webrtc' => 'yes',
            ]], 'id');

            DB::table('asterisk.ps_auths')->upsert([[
                'id' => $authId,
                'auth_type' => 'userpass',
                'username' => $extension,
                'password' => $this->existingPassword($authId) ?? Str::random(32),
            ]], 'id');

            DB::table('asterisk.ps_aors')->upsert([[
                'id' => $extension,
                'max_contacts' => 1,
                'remove_existing' => 'yes',
                // 🔴 F29. Every agent was born with qualify OFF, so Asterisk never checked
                // whether a registered console was still there and kept dead registrations
                // on the books for up to the hour `default_expiration` allows. A desk that
                // had gone away still looked reachable, and the dial against it failed
                // with "Allocation failed" (measured on staging 2026-09-13, extension
                // 1103). Thirty seconds is one OPTIONS ping per agent per half minute —
                // about 1.7 a second on a fifty-seat floor, and nothing at our size.
                //
                // A literal, not a config key: nobody tunes this per deploy, and a knob
                // that never turns is one more thing to read. Being an upsert on `id`,
                // re-provisioning an agent repairs a row written before this line existed.
                //
                // Deliberately NOT paired with `remove_unavailable` (S154, Ankit's call).
                // Nothing in this codebase ever asks the switch whether a phone is alive —
                // the agent board decides who is free — so deleting a contact on a failed
                // ping cannot improve a routing decision; it can only change which error
                // comes back. Against that it takes a live agent off the floor for minutes
                // on one missed ping, because their browser re-registers on its own timer.
                // ringAgent() now survives a refused desk, which is what made the trade a
                // losing one.
                'qualify_frequency' => 30,
            ]], 'id');

            $user->forceFill(['sip_extension' => $extension])->save();

            return $extension;
        });
    }

    /**
     * Destroy this person's key and keep their number (PP-19).
     *
     * The number stays on their own row forever, retired against their name. That is
     * what stops it ever being handed to someone else, and it is why every call
     * record naming it stays readable. Only the key dies.
     *
     * Reversible by design: `provisionFor()` on the same person sees no key and cuts
     * a fresh one on the same extension. That is the entire cost of a mistake here,
     * which is why PP-19 can be a button rather than a rebuild.
     *
     * 🔴 Deletes the auth row rather than blanking its password (RS-2, measured on
     * the box 2026-09-08). Blanking was the first shape and it is not safe: Asterisk
     * loaded `auth1102/1102` with an empty secret and the endpoint still pointed at
     * it, because res_pjsip only refuses when there is NO stored credential at all —
     * an empty string is a credential. Deleting the row makes `ast_sip_retrieve_auths`
     * fail, which rejects the request outright.
     *
     * The endpoint and aor rows stay. They are inert without an auth object, and
     * keeping them means a re-issue is one `provisionFor()` call on the same number.
     */
    public function retireFor(User $user): void
    {
        if ($user->sip_extension === null) {
            return;
        }

        DB::table('asterisk.ps_auths')
            ->where('id', $this->authIdFor($user->sip_extension))
            ->delete();
    }

    /**
     * This person's key, or null when they hold none — no extension at all, or a
     * number whose auth row PP-19 destroyed.
     *
     * Reading a credential out of a second schema belongs here rather than in the
     * caller for the same reason writing it does: the schema qualifier and the
     * `auth1100` naming stay in the one file that knows them, and a bare `ps_auths`
     * anywhere else does not resolve at all (RV-3).
     *
     * 🔴 The one legitimate caller is AgentDirectory, handing an agent's own browser
     * its own registration key (SEC-1 slice 4). It must never reach a screen — S138
     * put the extension on the Users list precisely because the number is safe to
     * show and the key is not.
     */
    public function keyFor(User $user): ?string
    {
        return $user->sip_extension === null
            ? null
            : $this->existingPassword($this->authIdFor($user->sip_extension));
    }

    /**
     * Does this person hold a working key right now? The screen needs it to choose
     * between offering "retire" and offering "re-issue" (PP-19) — the bool, never
     * the key itself.
     */
    public function hasKey(User $user): bool
    {
        return $this->keyFor($user) !== null;
    }

    /**
     * The next number in the pool: one above the highest ever handed out, floored
     * at the pool start. Read from `users` rather than from Asterisk's tables
     * because a retired agent keeps their number on their own row forever (PP-19),
     * which is what stops it being handed to someone else.
     *
     * 🔴 This deliberately does NOT lock or retry. Two admins allocating at the same
     * moment both read the same number and the unique index on `users.sip_extension`
     * fails the second write loudly (PP-1). A loud failure is the specified
     * behaviour — the alternative is two browsers quietly sharing one phone.
     */
    private function nextExtension(): string
    {
        $highest = (int) DB::table('users')
            ->selectRaw('max(sip_extension::int) as highest')
            ->value('highest');

        return (string) max($highest + 1, self::POOL_FLOOR);
    }

    /**
     * The password already on file, or null when there is none to keep — either the
     * phone is new, or PP-19 blanked it and this is a re-issue.
     */
    private function existingPassword(string $authId): ?string
    {
        // A missing row is the retired case (PP-19) and value() returns null for it;
        // the empty-string check stays for any row blanked before RS-2 was settled.
        $password = DB::table('asterisk.ps_auths')->where('id', $authId)->value('password');

        return $password === '' ? null : $password;
    }

    /** The auth row's name, mirroring the hand-written `auth1003` naming (PP-7). */
    private function authIdFor(string $extension): string
    {
        return 'auth'.$extension;
    }
}
