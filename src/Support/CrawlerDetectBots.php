<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics\Support;

use AmjadIqbal\FirstPartyAnalytics\Contracts\DetectsBots;
use Illuminate\Http\Request;
use Jaybizzle\CrawlerDetect\CrawlerDetect;

/**
 * Bots, crawlers, monitors and headless browsers (jaybizzle/crawler-detect), plus requests with no
 * user agent. Results are remembered per user agent for the life of the process.
 */
final class CrawlerDetectBots implements DetectsBots
{
    private ?CrawlerDetect $detector = null;

    /** @var array<string, bool> */
    private array $seen = [];

    public function isBot(Request $request): bool
    {
        $ua = trim((string) $request->userAgent());

        if ($ua === '') {
            return true;
        }

        if (count($this->seen) > 500) {
            $this->seen = [];
        }

        return $this->seen[$ua] ??= ($this->detector ??= new CrawlerDetect)->isCrawler($ua);
    }
}
