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
     * ponytail: blanks the password rather than deleting the auth row, because a
     * blank is already the "no key" signal `existingPassword()` reads. Whether
     * Asterisk treats an empty secret as "reject everything" or "accept anything" is
     * unverified against the box (RS-2). If it turns out to be the latter, swap
     * `update` for `delete` and nothing else changes — a missing row reads as "no
     * key" too.
     */
    public function retireFor(User $user): void
    {
        if ($user->sip_extension === null) {
            return;
        }

        DB::table('asterisk.ps_auths')
            ->where('id', $this->authIdFor($user->sip_extension))
            ->update(['password' => '']);
    }

    /**
     * Does this person hold a working key right now?
     *
     * The screen needs it to choose between offering "retire" and offering
     * "re-issue" (PP-19). It lives here rather than in the page so that the schema
     * qualifier and the `auth1100` naming stay in the one file that knows them —
     * a bare `ps_auths` anywhere else does not resolve at all (RV-3).
     */
    public function hasKey(User $user): bool
    {
        return $user->sip_extension !== null
            && $this->existingPassword($this->authIdFor($user->sip_extension)) !== null;
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
        $password = DB::table('asterisk.ps_auths')->where('id', $authId)->value('password');

        return $password === '' ? null : $password;
    }

    /** The auth row's name, mirroring the hand-written `auth1003` naming (PP-7). */
    private function authIdFor(string $extension): string
    {
        return 'auth'.$extension;
    }
}
