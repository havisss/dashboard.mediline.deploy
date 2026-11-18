<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action, Product $record): void {
                    if ($record->outboundItems()->exists() || $record->inboundItems()->exists()) {
                        Notification::make()
                            ->title('Tidak dapat menghapus produk')
                            ->body('Produk ini sudah tercatat pada histori faktur sehingga tidak dapat dihapus.')
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! empty($data['photo'])) {
            $filename = basename($data['photo']);
            $disk = Storage::disk('public');

            $publicFile = public_path('images/products/'.$filename);
            if (! file_exists($publicFile) && file_exists(public_path('images/'.$filename))) {
                $publicFile = public_path('images/'.$filename);
            }

            if (file_exists($publicFile)) {
                if (! $disk->exists('products/'.$filename)) {
                    $disk->put('products/'.$filename, file_get_contents($publicFile));
                }
                if (! $disk->exists($filename)) {
                    $disk->put($filename, file_get_contents($publicFile));
                }
            }
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $currentPhoto = $this->record->photo;
        $submittedPhoto = $data['photo'] ?? null;

        if (is_array($submittedPhoto)) {
            $submittedPhoto = reset($submittedPhoto) ?: null;
        }

        if (empty($submittedPhoto) && ! empty($currentPhoto)) {
            $data['photo'] = $currentPhoto;

            return $data;
        }

        $isNewUpload = $this->hasNewPhotoUpload($submittedPhoto, $currentPhoto);

        if (! $isNewUpload) {
            $data['photo'] = $currentPhoto;

            return $data;
        }

        if (! empty($submittedPhoto) && ! empty($data['name'])) {
            $data['photo'] = $this->syncAndCleanPhoto($submittedPhoto, $data['name']);
        }

        return $data;
    }

    protected function hasNewPhotoUpload(?string $submittedPhoto, ?string $currentPhoto): bool
    {
        if (empty($submittedPhoto)) {
            return false;
        }

        if ($submittedPhoto !== $currentPhoto) {
            return true;
        }

        // If the DB already stored a 'products/...' path, detect replacement by comparing file contents
        $disk = Storage::disk('public');
        $filename = basename($submittedPhoto);

        if ($disk->exists($submittedPhoto) && $disk->exists($filename)) {
            return md5($disk->get($submittedPhoto)) !== md5($disk->get($filename));
        }

        return false;
    }

    protected function syncAndCleanPhoto(string $photoPath, string $productName): string
    {
        $extension = pathinfo($photoPath, PATHINFO_EXTENSION) ?: 'png';
        $cleanFilename = (Str::slug($productName) ?: 'produk').'.'.strtolower($extension);

        $disk = Storage::disk('public');
        $disk->makeDirectory('products');

        $sourcePath = null;
        if ($disk->exists($photoPath)) {
            $sourcePath = $photoPath;
        } elseif ($disk->exists('products/'.$photoPath)) {
            $sourcePath = 'products/'.$photoPath;
        }

        if ($sourcePath !== null) {
            if ($sourcePath !== $cleanFilename) {
                $disk->copy($sourcePath, $cleanFilename);
            }

            if ($sourcePath !== 'products/'.$cleanFilename) {
                $disk->copy($sourcePath, 'products/'.$cleanFilename);
            }

            if (! app()->runningUnitTests()) {
                try {
                    $realPath = $disk->path('products/'.$cleanFilename);
                    if (file_exists($realPath)) {
                        $publicDir = public_path('images/products');
                        File::ensureDirectoryExists($publicDir);
                        File::copy($realPath, $publicDir.'/'.$cleanFilename);
                    }
                } catch (\Throwable $e) {
                    // Ignore for virtual/fake test disks
                }
            }

            if ($sourcePath !== $cleanFilename && $sourcePath !== 'products/'.$cleanFilename) {
                $disk->delete($sourcePath);
            }
        }

        return $cleanFilename;
    }
}
