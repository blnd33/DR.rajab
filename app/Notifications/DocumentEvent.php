<?php

namespace App\Notifications;

/**
 * A document event (transfer sent, request approved, write-off waiting…). Texts are
 * translation keys + parameters, rendered in each recipient's language when sent.
 */
class DocumentEvent extends AppNotification
{
    /**
     * @param  array<string, string|int>  $titleParams
     * @param  array<string, string|int>  $bodyParams
     */
    public function __construct(
        public readonly string $event,
        public readonly string $titleKey,
        public readonly array $titleParams = [],
        public readonly ?string $bodyKey = null,
        public readonly array $bodyParams = [],
        public readonly ?string $link = null,
        public readonly string $iconName = 'heroicon-o-bell',
    ) {}

    public function eventKey(): string
    {
        return $this->event;
    }

    public function title(): string
    {
        return __($this->titleKey, $this->titleParams);
    }

    public function body(): string
    {
        return $this->bodyKey === null ? '' : __($this->bodyKey, $this->bodyParams);
    }

    public function url(): ?string
    {
        return $this->link;
    }

    public function icon(): string
    {
        return $this->iconName;
    }
}
