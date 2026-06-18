<?php

namespace App\Enums;

/**
 * Which way a call went (B3 D1). Set SERVER-SIDE, never from the browser:
 * Outbound when the agent dialed (the shared originate path), Inbound by default
 * for anything that arrived. One `calls` table carries both so every report is a
 * trivial `WHERE direction = …`.
 */
enum CallDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Inbound',
            self::Outbound => 'Outbound',
        };
    }
}
