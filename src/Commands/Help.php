<?php

namespace RPurinton\SkeletonMQ\Commands;

use RPurinton\SkeletonMQ\App;

class Help extends CommandHandler
{
    public function handle(): void
    {
        $locale = $this->interaction->locale ?? 'en-US';
        $help_text = $this->locales[$locale]['help_text'] ?? $this->locales['en-US']['help_text'] ?? 'No help available';
        $this->interaction->respondWithMessage($help_text, true);
    }
}
