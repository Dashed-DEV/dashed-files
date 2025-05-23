<?php

namespace Dashed\DashedFiles\Classes;

use RalphJSmit\Filament\MediaLibrary\Media\DataTransferObjects\MediaItemMeta;
use RalphJSmit\Filament\MediaLibrary\FilamentTipTap\Actions\MediaLibraryAction;

class MediaLibraryActionOverride extends MediaLibraryAction
{
    protected function getMediaLibraryItemMeta(MediaLibraryItem|\RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem $mediaLibraryItem): MediaItemMeta
    {
        $mediaItemMeta = parent::getMediaLibraryItemMeta($mediaLibraryItem);

        $mediaItemMeta->url = $mediaItemMeta->full_url;

        return $mediaItemMeta;
    }
}
