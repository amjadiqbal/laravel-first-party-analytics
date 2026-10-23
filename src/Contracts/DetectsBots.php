<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics\Contracts;

use Illuminate\Http\Request;

/** Bind your own implementation in the container to replace the bot check. */
interface DetectsBots
{
    public function isBot(Request $request): bool;
}
