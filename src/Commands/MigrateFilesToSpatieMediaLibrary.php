<?php

namespace Dashed\DashedFiles\Commands;

use App\Models\User;
use Dashed\DashedCore\Models\Customsetting;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MigrateFilesToSpatieMediaLibrary extends Command
{
    public $signature = 'dashed:migrate-files-to-spatie-media-library';

    public $description = 'Migrate files from dashed to spatie media library';

    public $mediaLibraryItems;

    public function handle(): int
    {
//        MediaLibraryFolder::all()->each(fn($folder) => $folder->delete());
//        MediaLibraryItem::all()->each(fn($item) => $item->delete());
//        Media::all()->each(fn($media) => $media->delete());

        $mediaLibraryItems = MediaLibraryItem::all();
        foreach ($mediaLibraryItems as $mediaLibraryItem) {
            $mediaLibraryItem['file_name_to_match'] = basename($mediaLibraryItem->getItem()->getPath() ?? '');
        }
        $this->mediaLibraryItems = $mediaLibraryItems;

        $folders = Storage::disk('dashed')->allDirectories('/dashed');
        $allFolders = [];
        $user = User::first();

        foreach ($folders as &$folder) {
            if (!str($folder)->contains('__media-cache')) {
                $this->info('Migration started for folder: ' . $folder);

                $folder = str($folder)->replace('dashed/', '');
                $parentId = $this->getParentId($folder);

                $newFolder = new MediaLibraryFolder();
                $newFolder->name = $folder;
                $newFolder->parent_id = $parentId;
                if ($otherFolder = MediaLibraryFolder::where('name', str($folder)->explode('/')->last())->where('parent_id', $parentId)->first()) {
                    $newFolder = $otherFolder;
                }
                $newFolder->save();
                $allFolders[] = [
                    'newFolderId' => $newFolder->id,
                    'folder' => $folder,
                ];
                $this->info('Folder created: ' . $folder);
            }
        }

        foreach (MediaLibraryFolder::all() as $folder) {
            $folder->name = str($folder->name)->explode('/')->last();
            $folder->save();
        }

        foreach ($allFolders as $folder) {
            $this->info('Migrating files for folder: ' . $folder['folder']);
            $this->withProgressBar(Storage::disk('dashed')->files('dashed/' . $folder['folder']), function ($file) use ($user, $folder) {
                try {
                    if (!$this->mediaLibraryItems->where('file_name_to_match', basename($file))->first()) {
                        $filamentMediaLibraryItem = new MediaLibraryItem();
                        $filamentMediaLibraryItem->uploaded_by_user_id = $user->id;
                        $filamentMediaLibraryItem->folder_id = $folder['newFolderId'];
                        $filamentMediaLibraryItem->save();

                        $fileName = basename($file);
                        if (str($fileName)->length() > 200) {
                            $newFileName = str(str($fileName)->explode('/')->last())->substr(50);
                            $newFile = str($file)->replace($fileName, $newFileName);
                            Storage::disk('dashed')->copy($file, $newFile);
                            $file = $newFile;
                        }

                        $filamentMediaLibraryItem
                            ->addMediaFromDisk($file, 'dashed')
                            ->preservingOriginal()
                            ->toMediaCollection($filamentMediaLibraryItem->getMediaLibraryCollectionName());
                        $this->info('File migrated: ' . $file);
                    }
                } catch (\Exception $e) {
                    $this->error('Error migrating file: ' . $file);
                    $this->error($e->getMessage());
                }
            });
        }

        return self::SUCCESS;
    }

    private function getParentId($folder): ?int
    {
        $folders = str($folder)->explode('/')->toArray();
        array_pop($folders);

        return MediaLibraryFolder::where('name', implode('/', $folders))->first()->id ?? null;
    }
}
