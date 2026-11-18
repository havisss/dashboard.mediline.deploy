<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Produk';

    protected static ?string $pluralModelLabel = 'Produk';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Produk')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nama Barang / Produk')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->live(onBlur: true)
                                    ->maxLength(255),

                                Forms\Components\Select::make('division')
                                    ->label('Divisi')
                                    ->options(Product::divisionOptions())
                                    ->placeholder('Pilih Divisi Produk...')
                                    ->native(false),

                                Forms\Components\TextInput::make('brand')
                                    ->label('Merek / Brand')
                                    ->placeholder('Contoh: MERCK, SD Biosensor, dll.')
                                    ->maxLength(255),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('unit')
                                    ->label('Satuan')
                                    ->default('Pcs')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('price')
                                    ->label('Harga Satuan')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('Rp')
                                    ->default(0),
                            ]),

                        Forms\Components\Textarea::make('description')
                            ->label('Keterangan / Deskripsi')
                            ->placeholder('Masukkan keterangan atau spesifikasi produk...')
                            ->rows(4)
                            ->columnSpanFull(),

                        Forms\Components\FileUpload::make('photo')
                            ->label('Foto Produk')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(2048)
                            ->disk('public')
                            ->directory('products')
                            ->getUploadedFileNameForStorageUsing(
                                function (Forms\Get $get, TemporaryUploadedFile $file): string {
                                    $productName = $get('name');
                                    $rawName = ! empty($productName) ? $productName : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                                    $slug = Str::slug($rawName);

                                    return ($slug ?: 'produk').'.'.strtolower($file->getClientOriginalExtension());
                                }
                            )
                            ->imageEditor()
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('is_hidden')
                            ->label('Sembunyikan dari Website Utama (Hide)')
                            ->helperText('Jika diaktifkan, produk ini tidak akan ditampilkan di website utama.')
                            ->default(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('photo_url')
                    ->label('Foto')
                    ->state(fn (Product $record): ?string => $record->photo_url)
                    ->defaultImageUrl(asset('images/placeholder-product.svg'))
                    ->circular()
                    ->size(46)
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Barang')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('division')
                    ->label('Divisi')
                    ->badge()
                    ->color(fn (?string $state): string => Product::divisionColor($state))
                    ->placeholder('Belum diatur')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('brand')
                    ->label('Merek')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan')
                    ->limit(50)
                    ->tooltip(fn (Product $record): ?string => $record->description)
                    ->searchable(),

                Tables\Columns\TextColumn::make('unit')
                    ->label('Satuan')
                    ->badge()
                    ->searchable(),

                Tables\Columns\TextColumn::make('price')
                    ->label('Harga Satuan')
                    ->numeric()
                    ->sortable()
                    ->prefix('Rp '),

                Tables\Columns\TextColumn::make('is_hidden')
                    ->label('Status Website')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Hidden' : 'Tampil')
                    ->color(fn (bool $state): string => $state ? 'gray' : 'success')
                    ->icon(fn (bool $state): string => $state ? 'heroicon-m-eye-slash' : 'heroicon-m-eye')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat Pada')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Diperbarui Pada')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('division')
                    ->label('Divisi')
                    ->options(Product::divisionOptions()),

                Tables\Filters\SelectFilter::make('brand')
                    ->label('Merek')
                    ->options(
                        fn (): array => Product::whereNotNull('brand')
                            ->where('brand', '!=', '')
                            ->distinct()
                            ->pluck('brand', 'brand')
                            ->toArray()
                    )
                    ->searchable(),

                Tables\Filters\TernaryFilter::make('is_hidden')
                    ->label('Status Website')
                    ->placeholder('Semua Produk')
                    ->trueLabel('Disembunyikan (Hidden)')
                    ->falseLabel('Tampil di Website'),
            ])
            ->actions([
                Tables\Actions\Action::make('toggle_hide')
                    ->label(fn (Product $record): string => $record->is_hidden ? 'Unhide' : 'Hide')
                    ->icon(fn (Product $record): string => $record->is_hidden ? 'heroicon-o-eye' : 'heroicon-o-eye-slash')
                    ->color(fn (Product $record): string => $record->is_hidden ? 'success' : 'warning')
                    ->action(function (Product $record): void {
                        $newState = ! $record->is_hidden;
                        $record->update(['is_hidden' => $newState]);

                        Notification::make()
                            ->title($newState ? 'Produk disembunyikan dari website utama' : 'Produk ditampilkan kembali di website utama')
                            ->body($record->name)
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (Tables\Actions\DeleteAction $action, Product $record): void {
                        if ($record->outboundItems()->exists() || $record->inboundItems()->exists()) {
                            Notification::make()
                                ->title('Tidak dapat menghapus produk')
                                ->body('Produk ini sudah tercatat pada histori faktur sehingga tidak dapat dihapus.')
                                ->danger()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('hide_selected')
                        ->label('Hide dari Website')
                        ->icon('heroicon-m-eye-slash')
                        ->color('warning')
                        ->action(function (Collection $records): void {
                            $records->each(fn (Product $product): bool => $product->update(['is_hidden' => true]));

                            Notification::make()
                                ->title('Produk terpilih disembunyikan dari website utama')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('unhide_selected')
                        ->label('Unhide ke Website')
                        ->icon('heroicon-m-eye')
                        ->color('success')
                        ->action(function (Collection $records): void {
                            $records->each(fn (Product $product): bool => $product->update(['is_hidden' => false]));

                            Notification::make()
                                ->title('Produk terpilih ditampilkan kembali di website utama')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('set_division')
                        ->label('Atur Divisi')
                        ->icon('heroicon-m-tag')
                        ->form([
                            Forms\Components\Select::make('division')
                                ->label('Pilih Divisi')
                                ->options(Product::divisionOptions())
                                ->required()
                                ->native(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(fn (Product $product): bool => $product->update([
                                'division' => $data['division'],
                            ]));

                            Notification::make()
                                ->title('Divisi produk berhasil diperbarui')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function (Tables\Actions\DeleteBulkAction $action, Collection $records): void {
                            $hasHistory = $records->contains(
                                fn (Product $product): bool => $product->outboundItems()->exists() || $product->inboundItems()->exists()
                            );

                            if ($hasHistory) {
                                Notification::make()
                                    ->title('Tidak dapat menghapus produk')
                                    ->body('Satu atau lebih produk yang dipilih sudah tercatat pada histori faktur.')
                                    ->danger()
                                    ->send();

                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
