<?php

namespace App\Intelligence\Outreach;

use App\Enums\OpportunityType;

/**
 * A suggested first WhatsApp message for a business.
 */
final readonly class OutreachMessage
{
    /**
     * @param  string  $text  The message, in Arabic
     * @param  OpportunityType  $opportunity  The opportunity the message leads with
     * @param  string|null  $whatsappNumber  International number without "+", as wa.me expects it
     * @param  string|null  $numberSource  Where the number comes from, for display
     * @param  bool  $numberIsWhatsapp  True when the number is listed as WhatsApp; false for a mobile number that probably has it
     */
    public function __construct(
        public string $text,
        public OpportunityType $opportunity,
        public ?string $whatsappNumber = null,
        public ?string $numberSource = null,
        public bool $numberIsWhatsapp = false,
    ) {}

    /**
     * A wa.me link that opens WhatsApp with the message ready to send.
     */
    public function whatsappUrl(): ?string
    {
        return $this->whatsappNumber === null
            ? null
            : 'https://wa.me/'.$this->whatsappNumber.'?text='.rawurlencode($this->text);
    }
}
