<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OutboundInvoiceResource\Pages;
use App\Models\Client;
use App\Models\OutboundInvoice;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;

class OutboundInvoiceResource extends Resource
{
    protected static ?string $model = OutboundInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-up';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Customer')
                    ->schema([
                        Forms\Components\Hidden::make('client_id'),
                        Forms\Components\Select::make('client_name')
                            ->label('Customer')
                            ->placeholder('Pilih atau ketik nama customer...')
                            ->searchable()
                            ->searchPrompt('Start typing to search...')
                            ->searchDebounce(300)
                            ->options(fn (): array => self::clientSelectOptions())
                            ->getSearchResultsUsing(function (string $search): array {
                                $trimmed = trim($search);

                                $results = Client::query()
                                    ->where('name', 'like', "%{$trimmed}%")
                                    ->orderBy('name')
                                    ->limit(50)
                                    ->pluck('name', 'name')
                                    ->all();

                                if ($trimmed !== '') {
                                    $exactExists = Client::query()
                                        ->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])
                                        ->exists();

                                    if (! $exactExists) {
                                        $results[$trimmed] = $trimmed.' (Customer Baru)';
                                    }
                                }

                                return $results;
                            })
                            ->getOptionLabelUsing(fn ($value): ?string => filled($value) ? (string) $value : null)
                            ->required()
                            ->rule('max:255')
                            ->live()
                            ->afterStateHydrated(function (Forms\Components\Select $component, $state, ?OutboundInvoice $record): void {
                                if (blank($state) && $record?->client) {
                                    $component->state($record->client->name);
                                }
                            })
                            ->hint(function (?string $state): ?string {
                                $trimmed = trim((string) $state);
                                if ($trimmed === '') {
                                    return null;
                                }

                                $exists = Client::query()
                                    ->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])
                                    ->exists();

                                return $exists ? 'Terdaftar' : 'Customer baru';
                            })
                            ->hintColor(function (?string $state): ?string {
                                $trimmed = trim((string) $state);
                                if ($trimmed === '') {
                                    return null;
                                }

                                $exists = Client::query()
                                    ->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])
                                    ->exists();

                                return $exists ? 'success' : 'warning';
                            })
                            ->hintIcon(function (?string $state): ?string {
                                $trimmed = trim((string) $state);
                                if ($trimmed === '') {
                                    return null;
                                }

                                $exists = Client::query()
                                    ->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])
                                    ->exists();

                                return $exists ? 'heroicon-m-check-circle' : 'heroicon-m-sparkles';
                            })
                            ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?string $state): void {
                                $trimmed = trim((string) $state);
                                if ($trimmed !== '') {
                                    $client = Client::query()
                                        ->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])
                                        ->first();

                                    if ($client && $state !== $client->name) {
                                        $set('client_name', $client->name);
                                    }

                                    $set('client_id', $client?->id);
                                } else {
                                    $set('client_id', null);
                                }

                                // Auto set dates when customer is filled
                                if (! $get('issue_date')) {
                                    $set('issue_date', now()->format('Y-m-d'));
                                }
                                if (! $get('due_date')) {
                                    // Default due date +30 days
                                    $set('due_date', now()->addDays(30)->format('Y-m-d'));
                                }
                                // Auto generate sequential daily invoice number
                                if (! $get('invoice_number')) {
                                    $set('invoice_number', self::generateNextInvoiceNumber());
                                }
                            }),
                        Forms\Components\TextInput::make('invoice_number')
                            ->label('No Faktur')
                            ->default(fn (): string => self::generateNextInvoiceNumber())
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\DatePicker::make('issue_date')
                            ->label('Tanggal')
                            ->default(fn (): string => now()->format('Y-m-d'))
                            ->required(),
                        Forms\Components\DatePicker::make('due_date')
                            ->label('Jatuh Tempo')
                            ->default(fn (): string => now()->addDays(30)->format('Y-m-d'))
                            ->afterOrEqual('issue_date')
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->options([
                                'Unpaid' => 'Unpaid',
                                'Partial' => 'Partial',
                                'Paid' => 'Paid',
                                'Cancelled' => 'Cancelled',
                            ])
                            ->default('Unpaid')
                            ->live()
                            ->afterStateUpdated(function (?string $state, Forms\Set $set, Forms\Get $get): void {
                                if ($state === 'Paid') {
                                    $total = InboundInvoiceResource::parseIndonesianAmount((string) $get('total_amount'));
                                    $paid = InboundInvoiceResource::parseIndonesianAmount((string) $get('paid_amount'));

                                    if ($total > 0 && $paid <= 0) {
                                        $set('paid_amount', number_format($total, 0, ',', '.'));
                                    }
                                } elseif ($state === 'Unpaid') {
                                    $set('paid_amount', '0');
                                }
                            })
                            ->required(),
                        Forms\Components\TextInput::make('sales')
                            ->label('Sales')
                            ->placeholder('Contoh: Sales Area 1, NTB 2')
                            ->maxLength(255),
                        Forms\Components\Select::make('division')
                            ->label('Divisi')
                            ->placeholder('Otomatis dari barang pertama / pilih divisi...')
                            ->options(function (): array {
                                $existing = OutboundInvoice::query()
                                    ->whereNotNull('division')
                                    ->where('division', '!=', '')
                                    ->distinct()
                                    ->pluck('division', 'division')
                                    ->toArray();

                                return array_merge(Product::divisionOptions(), $existing);
                            })
                            ->searchable(),
                    ])->columns(2),

                Forms\Components\Section::make('Daftar Barang (Items)')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship() // Requires relationship `items` in OutboundInvoice model
                            ->schema([
                                Forms\Components\Select::make('item_name')
                                    ->label('Nama Barang')
                                    ->placeholder('Pilih atau ketik nama barang...')
                                    ->searchable()
                                    ->searchPrompt('Start typing to search...')
                                    ->searchDebounce(300)
                                    ->options(fn (): array => self::groupedProductOptions())
                                    ->getSearchResultsUsing(function (string $search): array {
                                        $trimmed = trim($search);

                                        $results = Product::query()
                                            ->where('name', 'like', "%{$trimmed}%")
                                            ->orderBy('name')
                                            ->limit(50)
                                            ->pluck('name', 'name')
                                            ->all();

                                        if ($trimmed !== '') {
                                            $exactExists = Product::query()
                                                ->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])
                                                ->exists();

                                            if (! $exactExists) {
                                                $results[$trimmed] = $trimmed.' (Barang Baru)';
                                            }
                                        }

                                        return $results;
                                    })
                                    ->getOptionLabelUsing(fn ($value): ?string => filled($value) ? (string) $value : null)
                                    ->required()
                                    ->rule('max:255')
                                    ->live()
                                    ->hint(function (?string $state): ?string {
                                        $trimmed = trim((string) $state);
                                        if ($trimmed === '') {
                                            return null;
                                        }

                                        $exists = Product::query()
                                            ->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])
                                            ->exists();

                                        return $exists ? 'Terdaftar' : 'Barang baru';
                                    })
                                    ->hintColor(function (?string $state): ?string {
                                        $trimmed = trim((string) $state);
                                        if ($trimmed === '') {
                                            return null;
                                        }

                                        $exists = Product::query()
                                            ->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])
                                            ->exists();

                                        return $exists ? 'success' : 'warning';
                                    })
                                    ->hintIcon(function (?string $state): ?string {
                                        $trimmed = trim((string) $state);
                                        if ($trimmed === '') {
                                            return null;
                                        }

                                        $exists = Product::query()
                                            ->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])
                                            ->exists();

                                        return $exists ? 'heroicon-m-check-circle' : 'heroicon-m-sparkles';
                                    })
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?string $state): void {
                                        $trimmed = trim((string) $state);
                                        if ($trimmed === '') {
                                            return;
                                        }

                                        $product = Product::query()
                                            ->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])
                                            ->first();

                                        if ($product) {
                                            if ($state !== $product->name) {
                                                $set('item_name', $product->name);
                                            }
                                            $set('unit', $product->unit);
                                            $set('unit_price', number_format((float) $product->price, 0, ',', '.'));
                                            self::updateItemSubtotal($set, $get);

                                            if (blank($get('../../division')) && filled($product->division)) {
                                                $set('../../division', $product->division);
                                            }
                                        }
                                    })
                                    ->columnSpan(3),
                                Forms\Components\TextInput::make('quantity')
                                    ->label('Qty')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::updateItemSubtotal($set, $get))
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('unit')
                                    ->label('Satuan')
                                    ->default('Pcs')
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('unit_price')
                                    ->label('Harga Satuan')
                                    ->prefix('Rp')
                                    ->placeholder('0')
                                    ->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))
                                    ->stripCharacters('.')
                                    ->formatStateUsing(fn ($state) => filled($state) ? number_format((float) $state, 0, ',', '.') : '')
                                    ->dehydrateStateUsing(fn ($state) => InboundInvoiceResource::parseIndonesianAmount((string) $state))
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::updateItemSubtotal($set, $get))
                                    ->columnSpan(2),
                                Forms\Components\TextInput::make('discount_percentage')
                                    ->label('Diskon 1 (%)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->rule('min:0')
                                    ->default(0)
                                    ->dehydrateStateUsing(fn ($state): float => max(0, (float) ($state ?? 0)))
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state): void {
                                        if ((float) $state < 0) {
                                            $set('discount_percentage', 0);
                                        }
                                        self::updateItemSubtotal($set, $get);
                                    })
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('discount_2_percentage')
                                    ->label('Diskon 2 (%)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->rule('min:0')
                                    ->default(0)
                                    ->dehydrateStateUsing(fn ($state): float => max(0, (float) ($state ?? 0)))
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, $state): void {
                                        if ((float) $state < 0) {
                                            $set('discount_2_percentage', 0);
                                        }
                                    })
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('subtotal')
                                    ->label('Jumlah')
                                    ->numeric()
                                    ->required()
                                    ->dehydrateStateUsing(fn ($state): float => (float) ($state ?? 0))
                                    ->readOnly()
                                    ->columnSpan(2),
                            ])
                            ->columns(11)
                            ->live()
                            ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::updateTotals($set, $get)),
                    ]),

                Forms\Components\Section::make('Total')
                    ->schema([
                        Forms\Components\TextInput::make('subtotal_amount')
                            ->label('Subtotal')
                            ->numeric()
                            ->default(0)
                            ->dehydrateStateUsing(fn ($state): float => (float) ($state ?? 0))
                            ->readOnly(),
                        Forms\Components\TextInput::make('tax_percentage')
                            ->label('PPN (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(11)
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::updateTotals($set, $get)),
                        Forms\Components\Hidden::make('tax_amount')
                            ->default(0)
                            ->dehydrateStateUsing(fn ($state): float => (float) ($state ?? 0)),
                        Forms\Components\TextInput::make('total_amount')
                            ->label('Total Tagihan')
                            ->numeric()
                            ->default(0)
                            ->dehydrateStateUsing(fn ($state): float => (float) ($state ?? 0))
                            ->readOnly(),
                        Forms\Components\TextInput::make('paid_amount')
                            ->label('Sudah Dibayar')
                            ->prefix('Rp')
                            ->placeholder('0')
                            ->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))
                            ->stripCharacters('.')
                            ->formatStateUsing(fn ($state) => filled($state) ? number_format((float) $state, 0, ',', '.') : '')
                            ->dehydrateStateUsing(fn ($state) => InboundInvoiceResource::parseIndonesianAmount((string) $state))
                            ->default(0),
                    ])->columns(2),
            ]);
    }

    public static function generateNextInvoiceNumber(): string
    {
        $datePrefix = 'JL'.now()->format('ymd');
        $existingNumbers = OutboundInvoice::query()
            ->where('invoice_number', 'like', $datePrefix.'%')
            ->pluck('invoice_number');

        $maxSequence = 0;
        foreach ($existingNumbers as $existingNumber) {
            if (preg_match('/^'.preg_quote($datePrefix, '/').'-?(\d+)$/', (string) $existingNumber, $matches)) {
                $maxSequence = max($maxSequence, (int) $matches[1]);
            }
        }

        $nextSequence = $maxSequence + 1;
        do {
            $candidate = $datePrefix.'-'.str_pad((string) $nextSequence, 3, '0', STR_PAD_LEFT);
            $nextSequence++;
        } while (OutboundInvoice::query()->where('invoice_number', $candidate)->exists());

        return $candidate;
    }

    /**
     * Resolve client_id from manual/recommended client_name input.
     * Creates a new Client record if no matching name exists.
     */
    public static function resolveClientIdFromFormData(array $data, ?bool &$createdNewClient = null): array
    {
        $createdNewClient = false;
        $clientName = trim((string) ($data['client_name'] ?? ''));

        if ($clientName !== '') {
            $client = Client::query()
                ->whereRaw('LOWER(name) = ?', [strtolower($clientName)])
                ->first();

            if (! $client) {
                $client = Client::create([
                    'name' => $clientName,
                    'type' => 'Rumah Sakit',
                    'is_active' => true,
                ]);
                $createdNewClient = true;
            }

            $data['client_id'] = $client->id;
        }

        unset($data['client_name']);

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public static function clientSelectOptions(): array
    {
        return once(function (): array {
            return Client::query()
                ->orderBy('name')
                ->pluck('name', 'name')
                ->all();
        });
    }

    /**
     * @return array<string, array<string, string>|string>
     */
    public static function groupedProductOptions(): array
    {
        return once(function (): array {
            $products = Product::query()->orderBy('division')->orderBy('name')->get();
            $hasDivision = $products->contains(fn (Product $product): bool => filled($product->division));

            if (! $hasDivision) {
                return $products->pluck('name', 'name')->toArray();
            }

            $grouped = [];
            foreach ($products as $product) {
                $groupLabel = $product->division ?: 'Belum Ada Divisi';
                $grouped[$groupLabel][$product->name] = $product->name;
            }

            return $grouped;
        });
    }

    public static function updateItemSubtotal(Forms\Set $set, Forms\Get $get): void
    {
        $qty = (float) $get('quantity') ?: 0;
        $price = InboundInvoiceResource::parseIndonesianAmount((string) $get('unit_price'));
        $discount = max(0, (float) $get('discount_percentage') ?: 0);

        $subtotal = $qty * $price * (1 - ($discount / 100));
        $set('subtotal', round($subtotal));

        $items = $get('../../items') ?? [];
        $subtotalAmount = 0;

        foreach ($items as $item) {
            $subtotalAmount += (float) ($item['subtotal'] ?? 0);
        }

        $taxPercentage = (float) ($get('../../tax_percentage') ?? 11);
        $taxAmount = $subtotalAmount * ($taxPercentage / 100);
        $totalAmount = $subtotalAmount + $taxAmount;

        $set('../../subtotal_amount', round($subtotalAmount));
        $set('../../tax_amount', round($taxAmount));
        $set('../../total_amount', round($totalAmount));
    }

    public static function updateTotals(Forms\Set $set, Forms\Get $get): void
    {
        $items = $get('items') ?? [];
        $subtotalAmount = 0;

        foreach ($items as $item) {
            $subtotalAmount += (float) ($item['subtotal'] ?? 0);
        }

        $taxPercentage = (float) ($get('tax_percentage') ?? 11);
        $taxAmount = $subtotalAmount * ($taxPercentage / 100);
        $totalAmount = $subtotalAmount + $taxAmount;

        $set('subtotal_amount', round($subtotalAmount));
        $set('tax_amount', round($taxAmount));
        $set('total_amount', round($totalAmount));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sales')
                    ->label('Sales')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('division')
                    ->label('Divisi')
                    ->badge()
                    ->color(fn (?string $state): string => Product::divisionColor($state))
                    ->placeholder('-')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('invoice_number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('issue_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('due_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'danger' => 'Unpaid',
                        'warning' => 'Partial',
                        'success' => 'Paid',
                        'secondary' => 'Cancelled',
                    ]),
                Tables\Columns\TextColumn::make('subtotal_amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tax_amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('paid_amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('division')
                    ->label('Divisi')
                    ->options(Product::divisionOptions()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('cetak_pdf')
                    ->label('Cetak PDF')
                    ->icon('heroicon-o-printer')
                    ->color('success')
                    ->url(fn (OutboundInvoice $record): string => route('outbound-invoice.pdf', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('surat_jalan')
                    ->label('Surat Jalan')
                    ->icon('heroicon-o-truck')
                    ->color('info')
                    ->url(fn (OutboundInvoice $record): string => route('surat-jalan.pdf', $record))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListOutboundInvoices::route('/'),
            'create' => Pages\CreateOutboundInvoice::route('/create'),
            'edit' => Pages\EditOutboundInvoice::route('/{record}/edit'),
        ];
    }
}
