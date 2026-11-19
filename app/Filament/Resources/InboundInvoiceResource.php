<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InboundInvoiceResource\Pages;
use App\Models\InboundInvoice;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Smalot\PdfParser\Parser;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Throwable;

class InboundInvoiceResource extends Resource
{
    protected static ?string $model = InboundInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?string $navigationLabel = 'Inbound Invoices';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('OCR Scanner (Opsional)')
                    ->description('Pilih Supplier dan upload PDF atau gambar faktur untuk mengisi Data Faktur dan Daftar Barang secara otomatis.')
                    ->schema([
                        Forms\Components\Select::make('supplier_id')
                            ->label('Supplier')
                            ->relationship('supplier', 'name')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get): void {
                                if ($state && filled($get('pdf_file'))) {
                                    self::processOcrScan($get('pdf_file'), (int) $state, $set, $get);
                                }
                            }),

                        Forms\Components\FileUpload::make('pdf_file')
                            ->label('Upload Faktur (PDF atau Gambar)')
                            ->disk('local')
                            ->directory('inbound-invoices')
                            ->visibility('private')
                            ->maxSize(10240)
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                            ->live()
                            ->hintAction(
                                Forms\Components\Actions\Action::make('rescanOcr')
                                    ->label('Scan Ulang OCR')
                                    ->icon('heroicon-m-document-magnifying-glass')
                                    ->visible(fn (Forms\Get $get): bool => filled($get('pdf_file')) && filled($get('supplier_id')))
                                    ->action(function (Forms\Set $set, Forms\Get $get): void {
                                        if (filled($get('pdf_file')) && filled($get('supplier_id'))) {
                                            self::processOcrScan($get('pdf_file'), (int) $get('supplier_id'), $set, $get);
                                        }
                                    })
                            )
                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get): void {
                                if (! $state) {
                                    return;
                                }

                                if (! $get('supplier_id')) {
                                    Notification::make()
                                        ->title('Pilih Supplier terlebih dahulu')
                                        ->body('Faktur berhasil diunggah. Silakan pilih Supplier agar OCR memproses faktur secara otomatis.')
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                self::processOcrScan($state, (int) $get('supplier_id'), $set, $get);
                            }),
                    ])->columns(2),

                Forms\Components\Section::make('Daftar Barang Pembelian (Items)')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship('items')
                            ->schema([
                                Forms\Components\TextInput::make('item_name')
                                    ->label('Nama Barang')
                                    ->placeholder('Ketik nama barang pembelian...')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(4),
                                Forms\Components\TextInput::make('quantity')
                                    ->label('Qty')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::updateItemSubtotal($set, $get))
                                    ->columnSpan(2),
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
                                Forms\Components\TextInput::make('subtotal')
                                    ->label('Jumlah')
                                    ->numeric()
                                    ->required()
                                    ->readOnly()
                                    ->columnSpan(3),
                            ])
                            ->columns(12)
                            ->live()
                            ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::updateTotals($set, $get)),
                    ]),

                Forms\Components\Section::make('Data Faktur')
                    ->schema([
                        Forms\Components\TextInput::make('invoice_number')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\DatePicker::make('issue_date')
                            ->required(),
                        Forms\Components\DatePicker::make('due_date')
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
                                    $total = self::parseIndonesianAmount((string) $get('total_amount'));
                                    $paid = self::parseIndonesianAmount((string) $get('paid_amount'));

                                    if ($total > 0 && $paid <= 0) {
                                        $set('paid_amount', number_format($total, 0, ',', '.'));
                                    }
                                } elseif ($state === 'Unpaid') {
                                    $set('paid_amount', '0');
                                }
                            })
                            ->required(),
                        Forms\Components\TextInput::make('subtotal_amount')
                            ->label('Subtotal (Rp)')
                            ->prefix('Rp')
                            ->placeholder('0')
                            ->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))
                            ->stripCharacters('.')
                            ->formatStateUsing(fn ($state) => filled($state) ? number_format((float) $state, 0, ',', '.') : '')
                            ->dehydrateStateUsing(fn ($state) => InboundInvoiceResource::parseIndonesianAmount((string) $state))
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state): void {
                                $subtotal = InboundInvoiceResource::parseIndonesianAmount((string) $state);
                                $tax = round($subtotal * ((float) ($get('tax_percentage') ?? 11) / 100));
                                $set('tax_amount', $tax);
                                $set('total_amount', round($subtotal + $tax));
                            })
                            ->default(0.00),

                        Forms\Components\TextInput::make('tax_percentage')
                            ->label('PPN (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->live()
                            ->dehydrated(false) // Tidak disimpan langsung ke DB
                            ->default(11)
                            ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state): void {
                                $subtotal = InboundInvoiceResource::parseIndonesianAmount((string) $get('subtotal_amount'));
                                $tax = round($subtotal * ((float) $state / 100));
                                $set('tax_amount', $tax);
                                $set('total_amount', round($subtotal + $tax));
                            }),

                        Forms\Components\Hidden::make('tax_amount')
                            ->default(0)
                            ->dehydrateStateUsing(fn ($state): float => (float) ($state ?? 0)),

                        Forms\Components\TextInput::make('total_amount')
                            ->label('Total Tagihan (Rp)')
                            ->prefix('Rp')
                            ->placeholder('0')
                            ->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))
                            ->stripCharacters('.')
                            ->formatStateUsing(fn ($state) => filled($state) ? number_format((float) $state, 0, ',', '.') : '')
                            ->dehydrateStateUsing(fn ($state) => InboundInvoiceResource::parseIndonesianAmount((string) $state))
                            ->required()
                            ->default(0.00),
                        Forms\Components\TextInput::make('paid_amount')
                            ->label('Sudah Dibayar (Rp)')
                            ->prefix('Rp')
                            ->placeholder('0')
                            ->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))
                            ->stripCharacters('.')
                            ->formatStateUsing(fn ($state) => filled($state) ? number_format((float) $state, 0, ',', '.') : '')
                            ->dehydrateStateUsing(fn ($state) => InboundInvoiceResource::parseIndonesianAmount((string) $state))
                            ->required()
                            ->default(0.00),
                        Forms\Components\Textarea::make('notes')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function processOcrScan(mixed $fileState, int $supplierId, Forms\Set $set, Forms\Get $get): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $supplier = Supplier::find($supplierId);
        if (! $supplier) {
            return;
        }

        $filePath = self::resolveUploadedFilePath($fileState);
        if (! $filePath || ! is_file($filePath)) {
            return;
        }

        $originalFilename = self::resolveUploadedOriginalFilename($fileState);

        try {
            $mimeType = self::detectFileMimeType($filePath, $originalFilename);
            $driver = strtolower(trim((string) config('services.ocr.driver', 'auto')));
            $geminiKey = trim((string) config('services.ocr.gemini_api_key', ''));
            $parsed = null;
            $text = '';

            // 1. Untuk PDF digital, coba baca teks lokal dulu via Smalot PDFParser (gratis, cepat)
            if ($mimeType === 'application/pdf') {
                try {
                    $pdfParser = new Parser;
                    $pdf = $pdfParser->parseFile($filePath);
                    $pdfText = trim($pdf->getText());

                    if ($pdfText !== '') {
                        $text = $pdfText;
                        $candidateParsed = self::parseOcrInvoiceData($text, $supplier);
                        $hasMatchedPdfData = $candidateParsed['invoice_number'] !== null
                            || $candidateParsed['issue_date'] !== null
                            || $candidateParsed['subtotal_amount'] !== null
                            || $candidateParsed['total_amount'] !== null
                            || ! empty($candidateParsed['items']);

                        if ($hasMatchedPdfData || $geminiKey === '' || $driver === 'tesseract') {
                            $parsed = $candidateParsed;
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning('PDF text extraction fallback to OCR: '.$e->getMessage());
                }
            }

            // 2. Untuk gambar (JPG/PNG) atau PDF scan tanpa lapisan teks: utamakan Gemini Flash API jika aktif
            $usedGemini = false;
            if ($parsed === null && in_array($driver, ['auto', 'gemini'], true) && $geminiKey !== '' && in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
                try {
                    $geminiResult = self::extractInvoiceViaGemini($filePath, $mimeType, $supplier, $geminiKey);
                    $parsed = $geminiResult['parsed'];
                    $text = $geminiResult['raw_text'];
                    $usedGemini = true;
                } catch (Throwable $geminiException) {
                    Log::warning('Gemini OCR error: '.$geminiException->getMessage());

                    if ($driver === 'gemini' || trim((string) config('services.ocr.ocrspace_api_key', '')) === '') {
                        if (! self::canRunLocalTesseract() || $driver === 'gemini' || $mimeType === 'application/pdf') {
                            throw new RuntimeException('Gemini OCR gagal: '.$geminiException->getMessage(), previous: $geminiException);
                        }
                    }
                }
            }

            // 3. Fallback ke OCR.space / Tesseract lokal bila Gemini tidak aktif
            if ($parsed === null) {
                $usedLocalTesseract = false;
                $text = self::extractOcrText($filePath, $mimeType, 4, $originalFilename, $usedLocalTesseract);

                if (blank($text)) {
                    Log::info("OCR Extracted empty text for {$filePath}");

                    Notification::make()
                        ->title('Teks faktur tidak terbaca')
                        ->body('OCR tidak menemukan teks pada file yang diunggah.')
                        ->warning()
                        ->send();

                    return;
                }

                $parsed = self::parseOcrInvoiceData($text, $supplier);

                $needsFallbackPass = $usedLocalTesseract
                    && in_array($mimeType, ['image/jpeg', 'image/png'], true)
                    && (($supplier->ocr_invoice_keyword && $parsed['invoice_number'] === null)
                        || ($supplier->ocr_date_keyword && $parsed['issue_date'] === null)
                        || ($supplier->ocr_total_keyword && $parsed['total_amount'] === null));

                if ($needsFallbackPass) {
                    $fallbackText = self::extractOcrText($filePath, $mimeType, 3, $originalFilename);
                    if (filled($fallbackText)) {
                        $fallbackParsed = self::parseOcrInvoiceData($fallbackText, $supplier);
                        $parsed['invoice_number'] ??= $fallbackParsed['invoice_number'];
                        $parsed['issue_date'] ??= $fallbackParsed['issue_date'];
                        $parsed['due_date'] ??= $fallbackParsed['due_date'];
                        $parsed['subtotal_amount'] ??= $fallbackParsed['subtotal_amount'];
                        $parsed['total_amount'] ??= $fallbackParsed['total_amount'];

                        if (empty($parsed['items']) && ! empty($fallbackParsed['items'])) {
                            $parsed['items'] = $fallbackParsed['items'];
                        }
                    }
                }
            }

            Log::info('OCR Extracted Text ('.($usedGemini ? 'Gemini' : 'Parser')."):\n".$text);

            $matchedFields = [];

            if (! empty($parsed['items'])) {
                $repeaterItems = [];
                foreach ($parsed['items'] as $itemData) {
                    $repeaterItems[(string) Str::uuid()] = [
                        'item_name' => $itemData['item_name'],
                        'quantity' => $itemData['quantity'],
                        'unit' => $itemData['unit'],
                        'unit_price' => number_format(round((float) $itemData['unit_price']), 0, ',', '.'),
                        'subtotal' => round((float) $itemData['subtotal']),
                    ];
                }

                $set('items', $repeaterItems);
                $matchedFields[] = count($repeaterItems).' item barang';
            } else {
                $existingItems = $get('items') ?? [];
                if (count($existingItems) === 1) {
                    $firstRow = Arr::first($existingItems);
                    if (blank($firstRow['item_name'] ?? null) && blank($firstRow['unit_price'] ?? null)) {
                        $set('items', []);
                    }
                }
            }

            if ($parsed['invoice_number'] !== null) {
                $set('invoice_number', $parsed['invoice_number']);
                $matchedFields[] = 'No. Faktur';
            }

            if ($parsed['issue_date'] !== null) {
                $set('issue_date', $parsed['issue_date']);
                $matchedFields[] = 'Tanggal';

                if ($parsed['due_date'] !== null) {
                    $set('due_date', $parsed['due_date']);
                } elseif (blank($get('due_date'))) {
                    $set('due_date', $parsed['issue_date']);
                }
            }

            $itemsSubtotal = array_sum(array_column($parsed['items'], 'subtotal'));
            $subtotalAmount = $parsed['subtotal_amount'] ?? ($itemsSubtotal > 0 ? $itemsSubtotal : null);
            $taxPercentage = (float) ($get('tax_percentage') ?? 11);

            if ($subtotalAmount !== null) {
                $set('subtotal_amount', number_format(round($subtotalAmount), 0, ',', '.'));
                $taxAmount = round($subtotalAmount * ($taxPercentage / 100), 2);
                $set('tax_amount', $taxAmount);
                $matchedFields[] = 'Subtotal';
            }

            if ($parsed['total_amount'] !== null) {
                $set('total_amount', number_format(round($parsed['total_amount']), 0, ',', '.'));
                $matchedFields[] = 'Total Tagihan';
            } elseif ($subtotalAmount !== null) {
                $taxAmount = round($subtotalAmount * ($taxPercentage / 100), 2);
                $set('total_amount', number_format(round($subtotalAmount + $taxAmount), 0, ',', '.'));
            }

            if (! empty($matchedFields)) {
                Notification::make()
                    ->title('OCR berhasil memproses faktur')
                    ->body('Data terisi otomatis: '.implode(', ', $matchedFields).'.')
                    ->success()
                    ->send();
            } else {
                $snippet = Str::limit(preg_replace('/\s+/u', ' ', trim($text)) ?? '', 180);

                Notification::make()
                    ->title('OCR selesai, namun kata kunci tidak cocok')
                    ->body('Periksa Kata Kunci OCR di Master Supplier. Cuplikan teks terbaca: '.$snippet)
                    ->warning()
                    ->persistent()
                    ->send();
            }
        } catch (Throwable $exception) {
            Log::error('OCR Scan failed: '.$exception->getMessage(), [
                'file_path' => $filePath,
                'supplier_id' => $supplierId,
                'exception' => $exception,
            ]);

            Notification::make()
                ->title('Gagal menjalankan OCR Scanner')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public static function resolveUploadedOriginalFilename(mixed $fileState): ?string
    {
        if ($fileState instanceof TemporaryUploadedFile) {
            return $fileState->getClientOriginalName() ?: null;
        }

        if (is_array($fileState)) {
            $firstFile = Arr::first($fileState);

            return $firstFile ? self::resolveUploadedOriginalFilename($firstFile) : null;
        }

        if (is_string($fileState) && $fileState !== '') {
            return basename($fileState);
        }

        return null;
    }

    public static function detectFileMimeType(string $filePath, ?string $originalFilename = null): string
    {
        $mimeType = function_exists('mime_content_type') ? (mime_content_type($filePath) ?: '') : '';

        if ($mimeType !== '' && $mimeType !== 'application/octet-stream') {
            return $mimeType;
        }

        $extension = strtolower(pathinfo($originalFilename ?: $filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => $mimeType,
        };
    }

    public static function resolveUploadedFilePath(mixed $fileState): ?string
    {
        if ($fileState instanceof TemporaryUploadedFile) {
            return $fileState->getRealPath() ?: null;
        }

        if (is_array($fileState)) {
            $firstFile = Arr::first($fileState);

            return $firstFile ? self::resolveUploadedFilePath($firstFile) : null;
        }

        if (is_string($fileState) && $fileState !== '') {
            if (is_file($fileState)) {
                return $fileState;
            }

            foreach (['local', 'public'] as $disk) {
                if (Storage::disk($disk)->exists($fileState)) {
                    return Storage::disk($disk)->path($fileState);
                }
            }
        }

        return null;
    }

    public static function resolveTesseractExecutable(): ?string
    {
        $configured = trim((string) config('services.ocr.tesseract_path', ''));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $candidates = [
            '/opt/homebrew/bin/tesseract',
            '/usr/local/bin/tesseract',
            '/usr/bin/tesseract',
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
        ];

        foreach ($candidates as $candidatePath) {
            if (is_file($candidatePath) && (DIRECTORY_SEPARATOR === '\\' || is_executable($candidatePath))) {
                return $candidatePath;
            }
        }

        return null;
    }

    public static function canRunLocalTesseract(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        foreach (['proc_open', 'exec', 'system'] as $requiredFunction) {
            if (! function_exists($requiredFunction) || in_array($requiredFunction, $disabled, true)) {
                return false;
            }
        }

        return self::resolveTesseractExecutable() !== null;
    }

    public static function extractOcrText(
        string $filePath,
        ?string $mimeType = null,
        int $psm = 4,
        ?string $originalFilename = null,
        ?bool &$usedLocalTesseract = null,
    ): string {
        $usedLocalTesseract = false;
        $resolvedMimeType = $mimeType ?: self::detectFileMimeType($filePath, $originalFilename);

        // 1. PDF teks digital via Smalot PDFParser (pure PHP, cepat, langsung jalan di cPanel)
        if ($resolvedMimeType === 'application/pdf') {
            try {
                $parser = new Parser;
                $pdf = $parser->parseFile($filePath);
                $pdfText = trim($pdf->getText());

                if ($pdfText !== '') {
                    return $pdfText;
                }
            } catch (Throwable $e) {
                Log::warning('PDF text extraction fallback to OCR: '.$e->getMessage());
            }
        }

        $driver = strtolower(trim((string) config('services.ocr.driver', 'auto')));
        $geminiKey = trim((string) config('services.ocr.gemini_api_key', ''));
        $ocrSpaceKey = trim((string) config('services.ocr.ocrspace_api_key', ''));
        $cloudError = null;

        // 2. Cloud OCR via Gemini Flash API (mendukung JPG, PNG, dan PDF scan)
        if (in_array($driver, ['auto', 'gemini'], true) && $geminiKey !== '' && in_array($resolvedMimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            try {
                return self::extractTextViaGemini($filePath, $resolvedMimeType, $geminiKey);
            } catch (Throwable $e) {
                $cloudError = $e->getMessage();
                Log::warning('Gemini API OCR error: '.$cloudError);

                if ($driver === 'gemini') {
                    throw new RuntimeException('Gemini OCR gagal: '.$cloudError, previous: $e);
                }
            }
        }

        // 3. Cloud OCR via OCR.space API (opsional cadangan jika key tersedia)
        if (in_array($driver, ['auto', 'ocrspace'], true) && $ocrSpaceKey !== '') {
            try {
                return self::extractTextViaOcrSpace($filePath, $resolvedMimeType, $originalFilename, $ocrSpaceKey);
            } catch (Throwable $e) {
                $cloudError = $e->getMessage();
                Log::warning('OCR.space API error: '.$cloudError);

                if ($driver === 'ocrspace') {
                    throw new RuntimeException('OCR.space gagal: '.$cloudError, previous: $e);
                }
            }
        }

        // 4. Fallback ke Tesseract lokal hanya jika fungsi shell aktif & binary tersedia
        if (in_array($driver, ['auto', 'tesseract'], true) && in_array($resolvedMimeType, ['image/jpeg', 'image/png'], true)) {
            if (self::canRunLocalTesseract()) {
                $usedLocalTesseract = true;

                return (new TesseractOCR($filePath))
                    ->executable((string) self::resolveTesseractExecutable())
                    ->psm($psm)
                    ->run();
            }
        }

        if ($cloudError !== null) {
            throw new RuntimeException('Gagal memproses OCR: '.$cloudError);
        }

        if ($resolvedMimeType === 'application/pdf') {
            throw new RuntimeException(
                'PDF ini berupa hasil scan gambar (tidak berisi teks digital). Tambahkan GEMINI_API_KEY di file .env agar PDF scan bisa dibaca otomatis.'
            );
        }

        if (in_array($resolvedMimeType, ['image/jpeg', 'image/png'], true)) {
            throw new RuntimeException(
                'Engine OCR gambar belum aktif. Tambahkan GEMINI_API_KEY di file .env (rekomendasi untuk cPanel) agar foto faktur dapat diproses.'
            );
        }

        return '';
    }

    /**
     * @return array{
     *     parsed: array{
     *         invoice_number: ?string,
     *         issue_date: ?string,
     *         due_date: ?string,
     *         subtotal_amount: ?float,
     *         total_amount: ?float,
     *         items: list<array{item_name: string, quantity: int, unit: string, unit_price: float, subtotal: float}>
     *     },
     *     raw_text: string
     * }
     */
    public static function extractInvoiceViaGemini(
        string $filePath,
        string $mimeType,
        Supplier $supplier,
        string $apiKey,
    ): array {
        $fileContents = file_get_contents($filePath);
        if ($fileContents === false) {
            throw new RuntimeException('Gagal membaca file faktur yang diunggah.');
        }

        $keywordHints = array_filter([
            $supplier->ocr_invoice_keyword ? 'No. Faktur: "'.$supplier->ocr_invoice_keyword.'"' : null,
            $supplier->ocr_date_keyword ? 'Tanggal: "'.$supplier->ocr_date_keyword.'"' : null,
            $supplier->ocr_subtotal_keyword ? 'Subtotal: "'.$supplier->ocr_subtotal_keyword.'"' : null,
            $supplier->ocr_total_keyword ? 'Total: "'.$supplier->ocr_total_keyword.'"' : null,
        ]);

        $hintSection = ! empty($keywordHints)
            ? "\nPetunjuk label dari supplier ({$supplier->name}): ".implode(', ', $keywordHints).'.'
            : '';

        $prompt = <<<PROMPT
Ekstrak data dari faktur pembelian Indonesia ini menjadi JSON murni tanpa penjelasan atau markdown.
Perhatikan format angka Indonesia: titik (.) adalah pemisah ribuan, koma (,) sebelum 2 digit di akhir adalah desimal. Kembalikan semua nominal harga sebagai angka murni (contoh: 135440 atau 5866501).
Jika nilai tidak ditemukan atau tidak terbaca jelas, isi null (atau array kosong [] untuk items).{$hintSection}

Skema JSON wajib:
{
  "invoice_number": "string atau null",
  "issue_date": "YYYY-MM-DD atau null",
  "due_date": "YYYY-MM-DD atau null",
  "subtotal_amount": 0,
  "total_amount": 0,
  "items": [
    {
      "item_name": "nama barang/produk",
      "quantity": 1,
      "unit": "Pcs/Kit/TST/Box/dll (default Pcs)",
      "unit_price": 0,
      "subtotal": 0
    }
  ],
  "raw_text": "baris ringkas nomor faktur, tanggal, subtotal, dan total saja"
}
PROMPT;

        $responseText = self::callGeminiApi([
            [
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data' => base64_encode($fileContents),
                        ],
                    ],
                ],
            ],
        ], $apiKey, expectJson: true);

        $cleanedJson = trim($responseText);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $cleanedJson, $fenceMatches)) {
            $cleanedJson = trim($fenceMatches[1]);
        }

        $decoded = json_decode($cleanedJson, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Format respons JSON dari Gemini tidak valid.');
        }

        $rawText = trim((string) ($decoded['raw_text'] ?? ''));

        $invoiceNumberRaw = isset($decoded['invoice_number']) ? trim((string) $decoded['invoice_number']) : '';
        $invoiceNumber = ($invoiceNumberRaw !== '' && ! in_array(strtolower($invoiceNumberRaw), ['null', '-'], true))
            ? $invoiceNumberRaw
            : null;

        $issueDate = isset($decoded['issue_date']) && trim((string) $decoded['issue_date']) !== ''
            ? self::parseIndonesianDate((string) $decoded['issue_date'])
            : null;

        $dueDate = null;
        if ($issueDate !== null && $supplier->top_days > 0) {
            $dueDate = date('Y-m-d', strtotime($issueDate.' + '.$supplier->top_days.' days'));
        } elseif (isset($decoded['due_date']) && trim((string) $decoded['due_date']) !== '') {
            $dueDate = self::parseIndonesianDate((string) $decoded['due_date']);
        }

        $subtotalAmount = self::normalizeGeminiAmount($decoded['subtotal_amount'] ?? null);
        $totalAmount = self::normalizeGeminiAmount($decoded['total_amount'] ?? null);

        $items = [];
        foreach ($decoded['items'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $itemName = trim((string) ($row['item_name'] ?? ''));
            $quantity = (int) round((float) ($row['quantity'] ?? 0));
            $unit = trim((string) ($row['unit'] ?? '')) ?: 'Pcs';
            $unitPrice = self::normalizeGeminiAmount($row['unit_price'] ?? null) ?? 0.0;
            $subtotal = self::normalizeGeminiAmount($row['subtotal'] ?? null) ?? 0.0;

            if ($subtotal <= 0 && $quantity > 0 && $unitPrice > 0) {
                $subtotal = round($quantity * $unitPrice);
            } elseif ($unitPrice <= 0 && $quantity > 0 && $subtotal > 0) {
                $unitPrice = round($subtotal / $quantity);
            }

            if ($itemName === '' || $quantity <= 0 || $unitPrice <= 0 || $subtotal <= 0) {
                continue;
            }

            $items[] = [
                'item_name' => $itemName,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
            ];
        }

        $parsed = [
            'invoice_number' => $invoiceNumber,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'subtotal_amount' => $subtotalAmount,
            'total_amount' => $totalAmount,
            'items' => $items,
        ];

        if ($rawText !== '') {
            $regexParsed = self::parseOcrInvoiceData($rawText, $supplier);
            $parsed['invoice_number'] ??= $regexParsed['invoice_number'];
            $parsed['issue_date'] ??= $regexParsed['issue_date'];
            $parsed['due_date'] ??= $regexParsed['due_date'];
            $parsed['subtotal_amount'] ??= $regexParsed['subtotal_amount'];
            $parsed['total_amount'] ??= $regexParsed['total_amount'];

            if (empty($parsed['items']) && ! empty($regexParsed['items'])) {
                $parsed['items'] = $regexParsed['items'];
            }
        }

        if ($parsed['due_date'] === null && $parsed['issue_date'] !== null && $supplier->top_days > 0) {
            $parsed['due_date'] = date('Y-m-d', strtotime($parsed['issue_date'].' + '.$supplier->top_days.' days'));
        }

        return [
            'parsed' => $parsed,
            'raw_text' => $rawText !== '' ? $rawText : (json_encode($parsed, JSON_UNESCAPED_UNICODE) ?: ''),
        ];
    }

    public static function extractTextViaGemini(string $filePath, string $mimeType, string $apiKey): string
    {
        $fileContents = file_get_contents($filePath);
        if ($fileContents === false) {
            throw new RuntimeException('Gagal membaca file faktur yang diunggah.');
        }

        return self::callGeminiApi([
            [
                'parts' => [
                    ['text' => 'Transkripsikan seluruh teks dan tabel pada faktur ini secara akurat dalam teks polos tanpa penjelasan tambahan.'],
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data' => base64_encode($fileContents),
                        ],
                    ],
                ],
            ],
        ], $apiKey, expectJson: false);
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     */
    public static function callGeminiApi(array $contents, string $apiKey, bool $expectJson = true): string
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $customEndpoint = trim((string) config('services.ocr.gemini_endpoint', ''));
        $primaryModel = trim((string) config('services.ocr.gemini_model', 'gemini-3.5-flash-lite')) ?: 'gemini-3.5-flash-lite';

        $candidateModels = $customEndpoint !== ''
            ? [$primaryModel]
            : array_values(array_unique([
                $primaryModel,
                'gemini-3.5-flash-lite',
                'gemini-3.1-flash-lite',
                'gemini-flash-lite-latest',
                'gemini-3.6-flash',
                'gemini-3.5-flash',
            ]));

        $payload = [
            'contents' => $contents,
        ];

        if ($expectJson) {
            $payload['generationConfig'] = [
                'responseMimeType' => 'application/json',
                'temperature' => 0.1,
            ];
        }

        $lastError = 'Tidak dapat terhubung ke layanan Gemini API.';

        foreach ($candidateModels as $index => $model) {
            $endpoint = $customEndpoint !== ''
                ? str_replace('{model}', $model, $customEndpoint)
                : "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

            try {
                $response = Http::timeout(18)
                    ->connectTimeout(5)
                    ->withOptions(['force_ip_resolve' => 'v4'])
                    ->withHeaders(['x-goog-api-key' => $apiKey])
                    ->post($endpoint, $payload);
            } catch (Throwable $e) {
                $lastError = 'Koneksi ke Gemini API gagal: '.$e->getMessage();
                Log::warning("Gemini connection error ({$model}): ".$e->getMessage());

                if ($index < count($candidateModels) - 1) {
                    continue;
                }

                throw new RuntimeException($lastError, previous: $e);
            }

            if ($response->successful()) {
                $body = $response->json();
                $parts = $body['candidates'][0]['content']['parts'] ?? [];
                $textParts = [];

                foreach ($parts as $part) {
                    if (! is_array($part) || ! empty($part['thought'])) {
                        continue;
                    }

                    $partText = trim((string) ($part['text'] ?? ''));
                    if ($partText !== '') {
                        $textParts[] = $partText;
                    }
                }

                $combinedText = trim(implode("\n", $textParts));
                if ($combinedText === '') {
                    throw new RuntimeException('Gemini API tidak mengembalikan hasil pembacaan.');
                }

                return $combinedText;
            }

            $status = $response->status();
            $apiMessage = (string) ($response->json('error.message') ?? '');

            if ($status === 429) {
                throw new RuntimeException('Kuota / rate limit Gemini API tercapai (HTTP 429). Tunggu 1 menit lalu klik Scan Ulang OCR atau isi manual.');
            }

            if (in_array($status, [401, 403], true)) {
                throw new RuntimeException('GEMINI_API_KEY tidak valid atau tidak memiliki izin akses Gemini API (HTTP '.$status.').');
            }

            $lastError = 'HTTP '.$status.' dari Gemini API'.($apiMessage !== '' ? ': '.$apiMessage : '.');

            // Retry with fallback model if model unavailable / high demand (404 / 503)
            if (in_array($status, [404, 503], true) && $index < count($candidateModels) - 1) {
                Log::warning("Gemini fallback from {$model} (HTTP {$status}): {$apiMessage}");

                continue;
            }

            throw new RuntimeException($lastError);
        }

        throw new RuntimeException($lastError);
    }

    public static function normalizeGeminiAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;

            return $number > 0 ? $number : null;
        }

        if (is_string($value) && trim($value) !== '') {
            $parsed = self::parseIndonesianAmount($value);

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    public static function extractTextViaOcrSpace(
        string $filePath,
        string $mimeType,
        ?string $originalFilename,
        string $apiKey,
    ): string {
        $endpoint = (string) config('services.ocr.ocrspace_endpoint', 'https://api.ocr.space/parse/image');
        $engine = (int) config('services.ocr.ocrspace_engine', 2);
        $language = (string) config('services.ocr.ocrspace_language', 'eng');

        $fileType = match ($mimeType) {
            'application/pdf' => 'PDF',
            'image/png' => 'PNG',
            default => 'JPG',
        };

        $uploadName = $originalFilename ?: ('invoice.'.strtolower($fileType));
        $fileContents = file_get_contents($filePath);

        if ($fileContents === false) {
            throw new RuntimeException('Gagal membaca file faktur yang diunggah.');
        }

        $response = Http::timeout(45)
            ->withHeaders(['apikey' => $apiKey])
            ->attach('file', $fileContents, $uploadName)
            ->post($endpoint, [
                'language' => $language,
                'OCREngine' => (string) $engine,
                'isTable' => 'true',
                'scale' => 'true',
                'detectOrientation' => 'true',
                'filetype' => $fileType,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status().' dari layanan OCR.space.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Respons tidak valid dari layanan OCR.space.');
        }

        if (! empty($payload['IsErroredOnProcessing'])) {
            $errors = $payload['ErrorMessage'] ?? ['Gagal memproses file di OCR.space.'];
            $message = is_array($errors) ? implode(' ', $errors) : (string) $errors;

            throw new RuntimeException(trim($message) ?: 'Gagal memproses file di OCR.space.');
        }

        $parsedTexts = [];
        foreach ($payload['ParsedResults'] ?? [] as $result) {
            $pageText = trim((string) ($result['ParsedText'] ?? ''));
            if ($pageText !== '') {
                $parsedTexts[] = $pageText;
            }
        }

        return implode("\n", $parsedTexts);
    }

    /**
     * @return array{
     *     invoice_number: ?string,
     *     issue_date: ?string,
     *     due_date: ?string,
     *     subtotal_amount: ?float,
     *     total_amount: ?float,
     *     items: list<array{item_name: string, quantity: int, unit: string, unit_price: float, subtotal: float}>
     * }
     */
    public static function parseOcrInvoiceData(string $text, Supplier $supplier): array
    {
        $invoiceNumber = null;
        $issueDate = null;
        $dueDate = null;
        $subtotalAmount = null;
        $totalAmount = null;

        if ($supplier->ocr_invoice_keyword) {
            $pattern = '/'.self::buildFlexibleKeywordPattern($supplier->ocr_invoice_keyword).'[\s\W]*([A-Za-z0-9\-\/]+)/iu';
            if (preg_match($pattern, $text, $matches)) {
                $invoiceNumber = trim($matches[1]);
            }
        }

        if ($supplier->ocr_date_keyword) {
            $pattern = '/'.self::buildFlexibleKeywordPattern($supplier->ocr_date_keyword).'[\s\W]*([0-9]{1,2}\s+[a-zA-Z]+\s+[0-9]{4}|\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/iu';
            if (preg_match($pattern, $text, $matches)) {
                $issueDate = self::parseIndonesianDate(trim($matches[1]));
                if ($issueDate !== null && $supplier->top_days > 0) {
                    $dueDate = date('Y-m-d', strtotime($issueDate.' + '.$supplier->top_days.' days'));
                }
            }
        }

        if ($supplier->ocr_subtotal_keyword) {
            $pattern = '/'.self::buildFlexibleKeywordPattern($supplier->ocr_subtotal_keyword).'[\s:\|\-\=\(\[\{\}]*(?:Rp\.?\s*)?(\d[\d\.,]*)/iu';
            if (preg_match($pattern, $text, $matches)) {
                $parsedSubtotal = self::parseIndonesianAmount($matches[1]);
                if ($parsedSubtotal > 0) {
                    $subtotalAmount = $parsedSubtotal;
                }
            }
        }

        if ($supplier->ocr_total_keyword) {
            $pattern = '/'.self::buildFlexibleKeywordPattern($supplier->ocr_total_keyword).'[\s:\|\-\=\(\[\{\}]*(?:Rp\.?\s*)?(\d[\d\.,]*)/iu';
            if (preg_match($pattern, $text, $matches)) {
                $parsedTotal = self::parseIndonesianAmount($matches[1]);
                if ($parsedTotal > 0) {
                    $totalAmount = $parsedTotal;
                }
            }
        }

        return [
            'invoice_number' => $invoiceNumber,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'subtotal_amount' => $subtotalAmount,
            'total_amount' => $totalAmount,
            'items' => self::parseOcrLineItems($text),
        ];
    }

    /**
     * @return list<array{item_name: string, quantity: int, unit: string, unit_price: float, subtotal: float}>
     */
    public static function parseOcrLineItems(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $items = [];
        $skipKeywords = '/\b(sub\s*total|subtotal|total\s*faktur|total\s*harga|grand\s*total|total\s*keseluruhan|ppn|diskon|biaya\s*kirim|terbilang|npwp|jatuh\s*tempo|tgl\s*faktur|no\.?\s*faktur|kode\s*barang|deskripsi\s*barang|nama\s*barang|harga\s*satuan|account|bank\s*central)\b/iu';

        $pattern = '/^'
            .'(?:\d{1,3}\s*[\|\.\)]\s*|\d{1,3}\s+)?'
            .'(?:[A-Za-z0-9_]{2,}-[A-Za-z0-9\-_]+\s*\|?\s*|[A-Z0-9_]{3,}\s*\|\s*)?'
            .'(.+?)'
            .'[\s\|]+'
            .'(\d{1,6})'
            .'(?:[\s\|]+(?!Rp\b)([A-Za-z]{2,10}))?'
            .'[\s\|]+'
            .'(?:Rp\.?\s*)?(\d[\d\.,]*)'
            .'(?:[\s\|]+\]?(?:\d{1,2}(?:[\.,]\d{1,2})?%?|[A-Za-z])\]?)?'
            .'[\s\|\]\[]+'
            .'(?:Rp\.?\s*)?(\d[\d\.,]*)'
            .'[\s\|\]]*$/iu';

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine === '' || preg_match($skipKeywords, $trimmedLine)) {
                continue;
            }

            $normalizedLine = preg_replace('/(\d)\s+([,\.]\d{2}\b)/u', '$1$2', $trimmedLine) ?? $trimmedLine;

            if (! preg_match($pattern, $normalizedLine, $matches)) {
                continue;
            }

            $itemName = trim($matches[1], " \t|.-_[]");
            $quantity = (int) $matches[2];
            $unit = ! empty($matches[3]) ? $matches[3] : 'Pcs';
            $unitPrice = self::parseIndonesianAmount($matches[4]);
            $subtotal = self::parseIndonesianAmount($matches[5]);

            if ($itemName === '' || ! preg_match('/[A-Za-z]/u', $itemName) || $quantity <= 0 || $unitPrice <= 0 || $subtotal <= 0) {
                continue;
            }

            $items[] = [
                'item_name' => $itemName,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
            ];
        }

        return $items;
    }

    public static function buildFlexibleKeywordPattern(string $keyword): string
    {
        $cleaned = rtrim(trim($keyword), " \t:|");
        $quoted = preg_quote($cleaned, '/');
        $quoted = preg_replace('/\\\\\s+/u', '\\s*', $quoted) ?? $quoted;

        return str_replace('\\.', '\\.?', $quoted);
    }

    public static function parseIndonesianDate(string $raw): ?string
    {
        $cleaned = trim($raw);
        if ($cleaned === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $cleaned, $matches)) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];

            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        $monthReplacements = [
            'Januari' => 'Jan',
            'Februari' => 'Feb',
            'Pebruari' => 'Feb',
            'Peb' => 'Feb',
            'Maret' => 'Mar',
            'April' => 'Apr',
            'Mei' => 'May',
            'Juni' => 'Jun',
            'Juli' => 'Jul',
            'Agustus' => 'Aug',
            'Ags' => 'Aug',
            'Agu' => 'Aug',
            'September' => 'Sep',
            'Oktober' => 'Oct',
            'Okt' => 'Oct',
            'November' => 'Nov',
            'Nopember' => 'Nov',
            'Nop' => 'Nov',
            'Desember' => 'Dec',
            'Des' => 'Dec',
        ];

        $normalized = str_ireplace(array_keys($monthReplacements), array_values($monthReplacements), $cleaned);
        $timestamp = strtotime($normalized);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    public static function parseIndonesianAmount(string $raw): float
    {
        $cleaned = preg_replace('/(\d)\s+([,\.]\d{2}\b)/u', '$1$2', trim($raw)) ?? trim($raw);
        $cleaned = preg_replace('/[^0-9,\.]/', '', $cleaned) ?? '';
        $cleaned = trim($cleaned, '.,');

        if ($cleaned === '') {
            return 0.0;
        }

        if (str_contains($cleaned, '.') && str_contains($cleaned, ',')) {
            if (strrpos($cleaned, ',') > strrpos($cleaned, '.')) {
                $cleaned = str_replace('.', '', $cleaned);
                $cleaned = str_replace(',', '.', $cleaned);
            } else {
                $cleaned = str_replace(',', '', $cleaned);
            }

            return (float) $cleaned;
        }

        if (str_contains($cleaned, ',')) {
            if (preg_match('/,\d{1,2}$/', $cleaned) && substr_count($cleaned, ',') === 1) {
                return (float) str_replace(',', '.', $cleaned);
            }

            return (float) str_replace(',', '', $cleaned);
        }

        if (str_contains($cleaned, '.')) {
            if (substr_count($cleaned, '.') > 1 || preg_match('/\.\d{3}$/', $cleaned)) {
                return (float) str_replace('.', '', $cleaned);
            }

            return (float) $cleaned;
        }

        return (float) $cleaned;
    }

    public static function updateItemSubtotal(Forms\Set $set, Forms\Get $get): void
    {
        $qty = (float) $get('quantity') ?: 0;
        $price = self::parseIndonesianAmount((string) $get('unit_price'));
        $subtotal = $qty * $price;
        $set('subtotal', round($subtotal));

        $items = $get('../../items') ?? [];
        if (! empty($items)) {
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
    }

    public static function updateTotals(Forms\Set $set, Forms\Get $get): void
    {
        $items = $get('items') ?? [];
        if (! empty($items)) {
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
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->sortable(),
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
                Tables\Columns\TextColumn::make('total_amount')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListInboundInvoices::route('/'),
            'create' => Pages\CreateInboundInvoice::route('/create'),
            'edit' => Pages\EditInboundInvoice::route('/{record}/edit'),
        ];
    }
}
