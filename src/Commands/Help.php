<?php

namespace RPurinton\Skeleton\Commands;

use RPurinton\Skeleton\App;

class Help extends CommandHandler
{
    public function handle(): void
    {
        $locale = $this->interaction->locale ?? 'en-US';
        $help_text = $this->locales[$locale]['help_text'] ?? $this->locales['en-US']['help_text'] ?? 'No help available';
        $this->interaction->respondWithMessage($help_text, true);
    }
}
