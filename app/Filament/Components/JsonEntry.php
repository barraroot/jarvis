<?php

namespace App\Filament\Components;

use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Read-only infolist entry that pretty-prints an array/JSON attribute in a `<pre>` block.
 */
class JsonEntry
{
    public static function make(string $attribute): TextEntry
    {
        return TextEntry::make($attribute)
            ->state(function (Model $record) use ($attribute): ?string {
                $value = $record->getAttribute($attribute);

                return $value === null || $value === []
                    ? null
                    : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            })
            ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(
                '<pre class="text-xs whitespace-pre-wrap font-mono">'.e($state).'</pre>',
            ))
            ->placeholder('—');
    }
}
