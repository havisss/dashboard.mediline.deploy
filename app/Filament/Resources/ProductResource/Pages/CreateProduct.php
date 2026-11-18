<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\OutboundInvoiceItem;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    public bool $isFromOutbound = false;

    protected bool $continueToNextPendingProduct = false;

    public function mount(): void
    {
        $this->isFromOutbound = request()->boolean('from_outbound') && ! empty(session('pending_new_products', []));

        if (! request()->boolean('from_outbound')) {
            session()->forget('pending_new_products');
        }

        parent::mount();
    }

    public function getSubheading(): ?string
    {
        $pending = session('pending_new_products', []);
        if (! $this->isFromOutbound || empty($pending)) {
            return null;
        }

        $current = $pending[0];
        $remainingCount = count($pending);

        return sprintf(
            'Mendaftarkan barang baru dari Outbound Invoice: "%s" (Sisa antrean: %d barang). Silakan lengkapi kolom yang belum terisi.',
            (string) ($current['name'] ?? ''),
            $remainingCount
        );
    }

    protected function getHeaderActions(): array
    {
        $pending = session('pending_new_products', []);
        if (! $this->isFromOutbound || empty($pending)) {
            return [];
        }

        return [
            Actions\Action::make('skipPendingProduct')
                ->label('Lewati Barang Ini')
                ->color('gray')
                ->icon('heroicon-o-forward')
                ->action(function (): void {
                    $pending = session('pending_new_products', []);
                    array_shift($pending);

                    if (! empty($pending)) {
                        session()->put('pending_new_products', array_values($pending));
                        $this->redirect(ProductResource::getUrl('create', ['from_outbound' => 1]));

                        return;
                    }

                    session()->forget('pending_new_products');
                    $this->redirect(ProductResource::getUrl('index'));
                }),
        ];
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $pending = session('pending_new_products', []);

        if ($this->isFromOutbound && ! empty($pending)) {
            $current = $pending[0];

            $this->form->fill([
                'name' => $current['name'] ?? '',
                'unit' => $current['unit'] ?? 'Pcs',
                'price' => $current['price'] ?? 0,
                'division' => $current['division'] ?? null,
                'is_hidden' => false,
            ]);
        } else {
            $this->form->fill();
        }

        $this->callHook('afterFill');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! empty($data['photo']) && ! empty($data['name'])) {
            $data['photo'] = $this->syncAndCleanPhoto($data['photo'], $data['name']);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $pending = session('pending_new_products', []);
        if (empty($pending)) {
            return;
        }

        $firstItem = $pending[0];
        $originalName = trim((string) ($firstItem['name'] ?? ''));
        $outboundId = $firstItem['outbound_invoice_id'] ?? null;
        $createdName = trim((string) $this->getRecord()->name);

        if ($outboundId && $originalName !== '' && $createdName !== '' && $originalName !== $createdName) {
            OutboundInvoiceItem::query()
                ->where('outbound_invoice_id', $outboundId)
                ->where('item_name', $originalName)
                ->update(['item_name' => $createdName]);
        }

        $createdKey = strtolower($createdName);
        $remaining = array_values(array_filter($pending, function (array $item) use ($createdKey): bool {
            return strtolower(trim((string) ($item['name'] ?? ''))) !== $createdKey;
        }));

        if (count($remaining) === count($pending)) {
            array_shift($remaining);
        }

        if (! empty($remaining)) {
            session()->put('pending_new_products', $remaining);
            $this->continueToNextPendingProduct = true;

            Notification::make()
                ->title('Lanjut ke barang baru berikutnya ('.count($remaining).' tersisa)')
                ->body('Silakan lengkapi data untuk barang: '.($remaining[0]['name'] ?? ''))
                ->info()
                ->send();
        } else {
            session()->forget('pending_new_products');
            $this->continueToNextPendingProduct = false;
        }
    }

    protected function getRedirectUrl(): string
    {
        if ($this->continueToNextPendingProduct && ! empty(session('pending_new_products', []))) {
            return ProductResource::getUrl('create', ['from_outbound' => 1]);
        }

        return $this->getResource()::getUrl('index');
    }

    protected function syncAndCleanPhoto(string $photoPath, string $productName): string
    {
        $extension = pathinfo($photoPath, PATHINFO_EXTENSION) ?: 'png';
        $cleanFilename = (Str::slug($productName) ?: 'produk').'.'.strtolower($extension);

        $disk = Storage::disk('public');
        $disk->makeDirectory('products');

        if ($disk->exists($photoPath)) {
            $disk->copy($photoPath, $cleanFilename);
            $disk->copy($photoPath, 'products/'.$cleanFilename);

            if (! app()->runningUnitTests()) {
                try {
                    $realPath = $disk->path($photoPath);
                    if (file_exists($realPath)) {
                        $publicDir = public_path('images/products');
                        File::ensureDirectoryExists($publicDir);
                        File::copy($realPath, $publicDir.'/'.$cleanFilename);
                    }
                } catch (\Throwable $e) {
                    // Ignore for virtual/fake test disks
                }
            }

            if ($photoPath !== $cleanFilename && $photoPath !== 'products/'.$cleanFilename) {
                $disk->delete($photoPath);
            }
        } elseif ($disk->exists('products/'.$photoPath)) {
            $disk->copy('products/'.$photoPath, $cleanFilename);
            $disk->copy('products/'.$photoPath, 'products/'.$cleanFilename);

            if (! app()->runningUnitTests()) {
                try {
                    $realPath = $disk->path('products/'.$photoPath);
                    if (file_exists($realPath)) {
                        $publicDir = public_path('images/products');
                        File::ensureDirectoryExists($publicDir);
                        File::copy($realPath, $publicDir.'/'.$cleanFilename);
                    }
                } catch (\Throwable $e) {
                    // Ignore for virtual/fake test disks
                }
            }
        }

        return $cleanFilename;
    }
}
