<?php

namespace App\Filament;

use Filament\Support\Contracts\HasLabel;

/**
 * Sidebar sections of the admin panel (US-12.1). Resources reference these cases in `$navigationGroup`.
 * Groups carry no icon on purpose: Filament forbids icons on both a group and its items.
 */
enum NavigationGroup implements HasLabel
{
    /** Providers, models, agents, tools, connectors. */
    case Ai;

    /** Conversations, tasks, memories, approvals. */
    case Operation;

    /** Users, settings, usage, audit. */
    case System;

    public function getLabel(): string
    {
        return match ($this) {
            self::Ai => 'IA',
            self::Operation => 'Operação',
            self::System => 'Sistema',
        };
    }
}
