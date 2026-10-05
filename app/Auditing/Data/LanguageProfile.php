<?php

namespace App\Auditing\Data;

final readonly class LanguageProfile
{
    /**
     * @param  string|null  $declared  Two-letter code from <html lang>
     * @param  string|null  $primary  Two-letter code of the dominant language of the content
     * @param  list<string>  $alternates  Other language versions linked from the page (hreflang, language switcher)
     */
    public function __construct(
        public ?string $declared,
        public ?string $primary,
        public bool $hasArabic,
        public bool $hasEnglish,
        public array $alternates = [],
    ) {}

    public function isMultilingual(): bool
    {
        return ($this->hasArabic && $this->hasEnglish) || $this->alternates !== [];
    }

    /**
     * @return array{declared: ?string, primary: ?string, alternates: list<string>}
     */
    public function toArray(): array
    {
        return [
            'declared' => $this->declared,
            'primary' => $this->primary,
            'alternates' => $this->alternates,
        ];
    }
}
