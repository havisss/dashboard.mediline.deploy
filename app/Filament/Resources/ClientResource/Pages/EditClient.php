<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ProductResource;
use App\Models\Client;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    public bool $isFromOutbound = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->isFromOutbound = request()->boolean('from_outbound')
            && (int) session('pending_new_client_id') === (int) $this->getRecord()->getKey();

        if (! request()->boolean('from_outbound')) {
            session()->forget('pending_new_client_id');
        }
    }

    public function getSubheading(): ?string
    {
        if (! $this->isFromOutbound) {
            return null;
        }

        $pendingProducts = session('pending_new_products', []);
        $extraNote = ! empty($pendingProducts)
            ? sprintf(' Setelah selesai, sistem akan lanjut mengarahkan pendaftaran %d barang baru.', count($pendingProducts))
            : '';

        return sprintf(
            'Melengkapi data Customer baru dari Outbound Invoice: "%s". Silakan lengkapi kolom yang belum terisi.%s',
            (string) $this->getRecord()->name,
            $extraNote
        );
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        if ($this->isFromOutbound) {
            $actions[] = Actions\Action::make('skipPendingClient')
                ->label('Lewati Customer Ini')
                ->color('gray')
                ->icon('heroicon-o-forward')
                ->action(function (): void {
                    session()->forget('pending_new_client_id');

                    if (! empty(session('pending_new_products', []))) {
                        $this->redirect(ProductResource::getUrl('create', ['from_outbound' => 1]));

                        return;
                    }

                    $this->redirect(ClientResource::getUrl('index'));
                });
        }

        $actions[] = Actions\DeleteAction::make()
            ->before(function (Actions\DeleteAction $action, Client $record): void {
                if ($record->outboundInvoices()->exists()) {
                    Notification::make()
                        ->title('Tidak dapat menghapus customer')
                        ->body('Customer ini masih memiliki histori faktur penjualan. Nonaktifkan status aktif sebagai gantinya.')
                        ->danger()
                        ->send();

                    $action->cancel();
                }
            });

        return $actions;
    }

    protected function afterSave(): void
    {
        if (! $this->isFromOutbound) {
            return;
        }

        session()->forget('pending_new_client_id');

        $pendingProducts = session('pending_new_products', []);
        if (! empty($pendingProducts)) {
            Notification::make()
                ->title('Data customer disimpan — lanjut ke pendaftaran barang baru')
                ->body('Silakan lengkapi data untuk '.count($pendingProducts).' barang baru.')
                ->info()
                ->send();
        }
    }

    protected function getRedirectUrl(): ?string
    {
        if ($this->isFromOutbound) {
            if (! empty(session('pending_new_products', []))) {
                return ProductResource::getUrl('create', ['from_outbound' => 1]);
            }

            return $this->getResource()::getUrl('index');
        }

        return parent::getRedirectUrl();
    }
}
