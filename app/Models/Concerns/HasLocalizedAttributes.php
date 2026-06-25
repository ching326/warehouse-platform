<?php

namespace App\Models\Concerns;

trait HasLocalizedAttributes
{
    /**
     * Map of app locale => column suffix for per-locale override columns.
     * A locale absent from this map (e.g. the source locale "en") always uses
     * the base column.
     *
     * @return array<string, string>
     */
    protected static function localizedColumnSuffixes(): array
    {
        return [
            'ja' => 'ja',
            'zh_TW' => 'zh_tw',
            'zh_CN' => 'zh_cn',
        ];
    }

    /**
     * Resolve a localized attribute for the given (or current) locale, falling
     * back to the base attribute when no locale override is set.
     */
    public function localized(string $attribute, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $suffix = static::localizedColumnSuffixes()[$locale] ?? null;

        if ($suffix !== null) {
            $override = $this->getAttribute($attribute.'_'.$suffix);

            if (filled($override)) {
                return (string) $override;
            }
        }

        return (string) ($this->getAttribute($attribute) ?? '');
    }
}
