<?php

namespace App\Enums;

/**
 * The kind of call script (BRD §6.1). Values match the keys the M3 settings DTO
 * stores scripts under (opening | objection | closing), so the M4.E seed can
 * copy a client's template scripts into rows without remapping.
 */
enum ScriptType: string
{
    case Opening = 'opening';
    case Objection = 'objection';
    case Closing = 'closing';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening',
            self::Objection => 'Objection',
            self::Closing => 'Closing',
        };
    }
}
