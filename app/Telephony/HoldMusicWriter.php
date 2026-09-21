<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The one place a client's hold music is written into Asterisk's own tables
 * (inbound-audio slice 3, step 5). Same doctrine as AgentPhoneWriter: one writer,
 * or the rows drift apart.
 *
 * A class is two rows that only work together — the class itself, and the one
 * playlist entry holding the web address. THE ORDER IS NOT OPTIONAL: the entry
 * table carries a foreign key to the class table (`fk_musiconhold_entry_name_
 * musiconhold`, read off staging 2026-09-21), so the class must exist first.
 *
 * Idempotent. A re-upload writes the same two rows again with a new address rather
 * than a second class — the entry's key is (name, position), so position 0 is
 * replaced in place. S165 proved a changed row reaches the NEXT hold with no
 * reload: the voice box looks a class up in the database only when it is not
 * already in memory, and a database class never is.
 *
 * 🔴 Every table name is schema-qualified, for the reason AgentPhoneWriter gives:
 * the app's connection pins `search_path = public` and these tables live in the
 * `asterisk` schema. The write grant was added on staging 2026-09-21
 * (server-build-log.md, Permissions).
 */
class HoldMusicWriter
{
    /**
     * Give this client's music a name the voice box can be asked for. A client with
     * no uploaded music writes nothing at all — the caller then hears the stock
     * `default` class, which is already in memory (AU-13).
     */
    public function writeFor(Tenant $tenant): void
    {
        $class = $tenant->holdMusicClass();
        $url = $tenant->holdMusicUrl();

        if ($class === null || $url === null) {
            return;
        }

        DB::transaction(function () use ($class, $url): void {
            DB::table('asterisk.musiconhold')->upsert([[
                'name' => $class,
                'mode' => 'playlist',
            ]], 'name');

            DB::table('asterisk.musiconhold_entry')->upsert([[
                'name' => $class,
                'position' => 0,
                'entry' => $url,
            ]], ['name', 'position']);
        });
    }
}
