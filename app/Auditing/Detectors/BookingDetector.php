<?php

namespace App\Auditing\Detectors;

use App\Auditing\Data\AnalysisContext;
use App\Auditing\HtmlDocument;
use App\Auditing\KeywordMatcher;
use App\Enums\Confidence;
use App\Enums\WebsiteSignal;

/**
 * Whether visitors can book an appointment, table or room online.
 *
 * A booking platform or plugin is strong evidence. Booking buttons, booking
 * pages and date pickers are medium evidence. A word like "booking" in the
 * text alone (often "call us to book") is only a hint.
 */
final class BookingDetector implements SignalDetector
{
    use InspectsUrls;

    /** @var array<string, string> */
    private const PLATFORMS = [
        'calendly.com' => 'Calendly', 'acuityscheduling.com' => 'Acuity Scheduling', 'setmore.com' => 'Setmore',
        'simplybook.me' => 'SimplyBook.me', 'youcanbook.me' => 'YouCanBookMe', 'tidycal.com' => 'TidyCal',
        'picktime.com' => 'Picktime', 'booksy.com' => 'Booksy', 'fresha.com' => 'Fresha', 'vagaro.com' => 'Vagaro',
        'zenoti.com' => 'Zenoti', 'mindbodyonline.com' => 'Mindbody', 'book.squareup.com' => 'Square Appointments',
        'zocdoc.com' => 'Zocdoc', 'vezeeta.com' => 'Vezeeta', 'okadoc.com' => 'Okadoc', 'doctoruna.com' => 'Doctoruna',
        'opentable.com' => 'OpenTable', 'resy.com' => 'Resy', 'sevenrooms.com' => 'SevenRooms', 'eatapp.co' => 'Eat App',
        'tablecheck.com' => 'TableCheck', 'quandoo.com' => 'Quandoo', 'thefork.com' => 'TheFork',
        'cloudbeds.com' => 'Cloudbeds', 'thebookingbutton.com' => 'SiteMinder', 'siteminder.com' => 'SiteMinder',
        'synxis.com' => 'SynXis',
    ];

    private const PLUGINS = '/\b(?:bookly|wpamelia|ameliabooking|woocommerce-bookings|booking-calendar|latepoint|simply-schedule-appointments|wp-booking-system)\b/i';

    private const CALLS_TO_ACTION = [
        'book now', 'book online', 'book an appointment', 'book appointment', 'book a table', 'book your table',
        'book a session', 'book a consultation', 'make a reservation', 'make an appointment', 'reserve a table',
        'reserve now', 'reservations', 'schedule an appointment', 'request an appointment', 'appointments', 'booking',
        'احجز', 'احجز الآن', 'احجز موعد', 'احجز موعدك', 'حجز موعد', 'حجز طاولة', 'حجز أونلاين', 'الحجز', 'حجوزات', 'حجز',
    ];

    private const BOOKING_PATH = '#/(?:book(?:ing|ings)?|appointments?|reserv(?:e|ation|ations)|schedule|حجز)(?:[/?\#.\-_]|$)#iu';

    private const MENTIONS = ['book', 'booking', 'appointment', 'reservation', 'reserve', 'حجز', 'موعد'];

    public function detect(HtmlDocument $document, AnalysisContext $context): array
    {
        $signal = new SignalBuilder(WebsiteSignal::Booking);

        foreach ($this->referencedUrls($document) as $url) {
            $platform = $this->serviceFor($url, self::PLATFORMS);

            if ($platform !== null) {
                $signal->add(Confidence::High, "Booking platform: {$platform}")->collect('platforms', $platform);
            }
        }

        if (preg_match(self::PLUGINS, $document->html, $matches)) {
            $signal->add(Confidence::High, "Booking plugin: {$matches[0]}");
        }

        foreach ($document->links() as $link) {
            if ($this->serviceFor($link['href'], self::PLATFORMS) !== null) {
                continue;
            }

            if (KeywordMatcher::first($link['text'], self::CALLS_TO_ACTION) !== null) {
                $signal->add(Confidence::Medium, 'Booking link '.$this->quote($link['text']));
            } elseif ($this->isSameSite($link['href'], $document) && preg_match(self::BOOKING_PATH, $this->pathOf($link['href']))) {
                $signal->add(Confidence::Medium, 'Link to a booking page: '.$this->pathOf($link['href']));
            }
        }

        foreach ($document->buttons() as $label) {
            if (KeywordMatcher::first($label, self::CALLS_TO_ACTION) !== null) {
                $signal->add(Confidence::Medium, 'Booking button '.$this->quote($label));
            }
        }

        foreach ($document->forms() as $form) {
            if (array_intersect(array_column($form['fields'], 'type'), ['date', 'datetime-local', 'time']) !== []) {
                $signal->add(Confidence::Medium, 'Form with a date or time picker');
            }
        }

        $mention = KeywordMatcher::firstInSearchable($document->searchableText(), self::MENTIONS);

        if ($mention !== null) {
            $signal->hint('Booking is only mentioned in the text ('.$this->quote($mention).')');
        }

        return [$signal->build()];
    }
}
