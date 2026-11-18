<?php

namespace App\Filament\Resources\OutboundInvoiceResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\OutboundInvoiceResource;
use App\Filament\Resources\ProductResource;
use App\Models\Product;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateOutboundInvoice extends CreateRecord
{
    protected static string $resource = OutboundInvoiceResource::class;

    protected ?int $newClientIdToComplete = null;

    protected bool $hasNewProductsToRegister = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $createdNewClient = false;
        $data = OutboundInvoiceResource::resolveClientIdFromFormData($data, $createdNewClient);

        if ($createdNewClient && ! empty($data['client_id'])) {
            $this->newClientIdToComplete = (int) $data['client_id'];
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();
        $record->load('items');

        if ($this->newClientIdToComplete) {
            session()->put('pending_new_client_id', $this->newClientIdToComplete);
        } else {
            session()->forget('pending_new_client_id');
        }

        $newProducts = [];

        foreach ($record->items as $item) {
            $itemName = trim((string) $item->item_name);
            if ($itemName === '') {
                continue;
            }

            $key = strtolower($itemName);
            if (isset($newProducts[$key])) {
                continue;
            }

            $exists = Product::query()
                ->whereRaw('LOWER(name) = ?', [$key])
                ->exists();

            if (! $exists) {
                $newProducts[$key] = [
                    'name' => $itemName,
                    'unit' => $item->unit ?: 'Pcs',
                    'price' => (float) $item->unit_price,
                    'division' => $record->division,
                    'outbound_invoice_id' => $record->id,
                ];
            }
        }

        if (! empty($newProducts)) {
            $pendingList = array_values($newProducts);
            session()->put('pending_new_products', $pendingList);
            $this->hasNewProductsToRegister = true;
        } else {
            session()->forget('pending_new_products');
            $this->hasNewProductsToRegister = false;
        }

        if ($this->newClientIdToComplete && $this->hasNewProductsToRegister) {
            Notification::make()
                ->title('Customer baru & Barang baru terdeteksi')
                ->body('Outbound berhasil dibuat. Silakan lengkapi detail Customer baru terlebih dahulu, lalu lanjutkan pendaftaran '.count($newProducts).' barang baru.')
                ->info()
                ->persistent()
                ->send();
        } elseif ($this->newClientIdToComplete) {
            Notification::make()
                ->title('Customer baru terdeteksi')
                ->body('Outbound berhasil dibuat. Silakan lengkapi detail Customer baru pada daftar Client.')
                ->info()
                ->persistent()
                ->send();
        } elseif ($this->hasNewProductsToRegister) {
            Notification::make()
                ->title('Barang baru terdeteksi ('.count($newProducts).' item)')
                ->body('Outbound berhasil dibuat. Silakan lengkapi pendaftaran barang baru ke daftar Produk.')
                ->info()
                ->persistent()
                ->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        if ($this->newClientIdToComplete) {
            return ClientResource::getUrl('edit', [
                'record' => $this->newClientIdToComplete,
                'from_outbound' => 1,
            ]);
        }

        if ($this->hasNewProductsToRegister && ! empty(session('pending_new_products', []))) {
            return ProductResource::getUrl('create', ['from_outbound' => 1]);
        }

        return parent::getRedirectUrl();
    }
}
