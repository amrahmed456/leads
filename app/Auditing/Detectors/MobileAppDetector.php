<?php

namespace App\Auditing\Detectors;

use App\Auditing\Data\AnalysisContext;
use App\Auditing\HtmlDocument;
use App\Enums\Confidence;
use App\Enums\WebsiteSignal;

/**
 * Whether the business promotes its own mobile app (App Store / Google Play
 * links). Links to delivery apps' store pages are not the business's own app.
 */
final class MobileAppDetector implements SignalDetector
{
    use InspectsUrls;

    /** @var array<string, string> */
    private const STORES = [
        'apps.apple.com' => 'the App Store',
        'itunes.apple.com' => 'the App Store',
        'play.google.com/store/apps' => 'Google Play',
        'appgallery.huawei.com' => 'AppGallery',
    ];

    private const THIRD_PARTY_APPS = '/talabat|hungerstation|jahez|mrsool|careem|deliveroo|thechefz|toyou|ubereats|keeta|noon|instashop|nana/i';

    public function detect(HtmlDocument $document, AnalysisContext $context): array
    {
        $signal = new SignalBuilder(WebsiteSignal::MobileApp);

        foreach ($document->links() as $link) {
            $store = $this->serviceFor($link['href'], self::STORES);

            if ($store !== null && ! preg_match(self::THIRD_PARTY_APPS, $link['href'])) {
                $signal->add(Confidence::High, "Links to its app on {$store}")->collect('stores', $store);
            }
        }

        return [$signal->build()];
    }
}
