<?php

namespace Dashed\DashedFiles\Classes;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use RalphJSmit\Filament\MediaLibrary\Forms\Components\MediaPicker;
use RalphJSmit\Filament\MediaLibrary\Media\DataTransferObjects\MediaItemMeta;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;
use Spatie\MediaLibrary\Conversions\Conversion;

class MediaHelper extends Command
{
    public function field($name = 'image', $label = 'Afbeelding', $required = false, $multiple = false, $isImage = false): MediaPicker
    {
        $mediaPicker = MediaPicker::make($name)
            ->label($label)
            ->required($required)
            ->multiple($multiple)
            ->showFileName()
            ->downloadable()
            ->reorderable();

        if ($isImage) {
            $mediaPicker->acceptedFileTypes(['image/*']);
        }

        return $mediaPicker;
    }

    public function plugin()
    {
        return FilamentMediaLibrary::make()
            ->navigationGroup('Content')
            ->navigationSort(1)
            ->navigationLabel('Media Browser')
            ->navigationIcon('heroicon-o-camera')
            ->activeNavigationIcon('heroicon-s-camera')
            ->pageTitle('Media Browser')
            ->acceptPdf()
            ->acceptVideo()
            ->conversionResponsive(enabled: true, modifyUsing: function (Conversion $conversion) {
                // Apply any modifications you want to the conversion, or omit to use defaults...
                return $conversion->format('webp');
            })
            ->conversionMedium(enabled: false)
            ->conversionSmall(enabled: false)
            ->conversionThumb(enabled: true, width: 600, height: 600, modifyUsing: function (Conversion $conversion) {
                return $conversion->format('webp');
            })
            ->firstAvailableUrlConversions([
                'thumb',
            ])
            ->slug('media-browser');
    }

    public function getSingleImage(int|string|array $mediaId, string $conversion = 'medium'): string|MediaItemMeta
    {
        if (is_string($mediaId) && filter_var($mediaId, FILTER_VALIDATE_INT) === false) {
            return $mediaId;
        }

        if (is_array($mediaId)) {
            $mediaId = $mediaId[0];
        }

        if (! is_int($mediaId)) {
            $mediaId = (int)$mediaId;
        }

        $media = Cache::rememberForever('media-library-media-' . $mediaId . '-' . $conversion, function () use ($mediaId, $conversion) {
            $media = MediaLibraryItem::find($mediaId);
            $mediaItem = $media->getItem();
            if (in_array($mediaItem->mime_type, ['image/svg+xml', 'image/svg', 'video/mp4'])) {
                $conversion = 'original';
            }
            $media = $media->getMeta();
            $media->path = $mediaItem->getPath();
            if ($conversion == 'original') {
                $media->url = $media->full_url;
            } else {
                $media->url = $mediaItem->getAvailableUrl([$conversion]);
            }

            return $media;
        });

        return $media;
    }

    public function getMultipleImages(array $mediaIds, string $conversion = 'medium'): ?Collection
    {
        if (is_string($mediaIds)) {
            return null;
        }

        if (is_int($mediaIds)) {
            return null;
        }

        $medias = [];

        foreach ($mediaIds as $id) {
            $medias[] = $this->getSingleImage($id, $conversion);
        }

        return collect($medias);
    }

    public function getFolderId($folder): int
    {
        $folders = str($folder)->explode('/');
        $parentId = null;

        foreach ($folders as $folder) {
            $mediaFolder = MediaLibraryFolder::where('name', $folder)->where('parent_id', $parentId)->first();
            if (! $mediaFolder) {
                $mediaFolder = new MediaLibraryFolder();
                $mediaFolder->name = $folder;
                $mediaFolder->parent_id = $parentId;
                $mediaFolder->save();
            }
            $parentId = $mediaFolder->id;
        }

        return $mediaFolder->id;
    }

    public function getFileId(string $file, ?int $folderId = null): ?int
    {
        foreach (MediaLibraryItem::where('folder_id', $folderId)->get() as $media) {
            if (str($media->getItem()->getPath())->endsWith($file)) {
                return $media->id;
            }
        }

        return null;
    }

    public function uploadFromPath($path, $folder): ?int
    {
        $folderId = $this->getFolderId($folder);

        if ($existingFile = $this->getFileId($path, $folderId)) {
            return $existingFile;
        }


        try {
            $filamentMediaLibraryItem = new MediaLibraryItem();
            $filamentMediaLibraryItem->uploaded_by_user_id = null;
            $filamentMediaLibraryItem->folder_id = $folderId;
            $filamentMediaLibraryItem->save();

            $fileName = basename($path);
            //            if (str($fileName)->length() > 200) {
            //                $newFileName = str(str($fileName)->explode('/')->last())->substr(50);
            //                $newFile = str($file)->replace($fileName, $newFileName);
            //                Storage::disk('dashed')->copy($file, $newFile);
            //                $file = $newFile;
            //            }

            try {
                $filamentMediaLibraryItem
                    ->addMediaFromDisk($path, 'dashed')
                    ->preservingOriginal()
                    ->toMediaCollection($filamentMediaLibraryItem->getMediaLibraryCollectionName());
            } catch (\Exception $e) {
                $filamentMediaLibraryItem->delete();

                return null;
            }

            return $filamentMediaLibraryItem->id;

        } catch (\Exception $e) {
            return null;
        }

        return null;
    }
}
