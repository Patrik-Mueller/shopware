<?php declare(strict_types=1);

namespace Shopware\AreaCountryState\Event;

use Shopware\Api\Write\WrittenEvent;

class AreaCountryStateTranslationWrittenEvent extends WrittenEvent
{
    const NAME = 'area_country_state_translation.written';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getEntityName(): string
    {
        return 'area_country_state_translation';
    }
}