<?php

namespace App\Services\Imports;

use App\Enums\ImportRowAction;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Exports\ArraySheet;
use App\Exports\Workbook;
use App\Imports\RawRowsImport;
use App\Jobs\ApplyImport;
use App\Jobs\ValidateImport;
use App\Models\Branch;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\User;
use App\Services\Imports\Contracts\ReadsWorkbook;
use App\Services\Imports\Contracts\RowHandler;
use App\Services\Imports\Handlers\MinStockRowHandler;
use App\Services\Imports\Handlers\ProductRowHandler;
use App\Services\Imports\Handlers\StockRowHandler;
use App\Services\Imports\Handlers\SupplierCatalogRowHandler;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\LocaleRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Module 23 flow: upload → validate → preview (new / updated / unchanged / errors with
 * old → new per field) → confirm → queued apply with progress → downloadable error file.
 * The original file is kept and every import is in the activity log.
 */
class ImportPipeline
{
    public const DISK = 'local';

    public const MAX_ROWS = 20000;

    private const MEMORY_LIMIT = '512M';

    public function __construct(private readonly BranchScopeResolver $scope) {}

    public function handler(ImportType $type): RowHandler
    {
        return match ($type) {
            ImportType::Products => app(ProductRowHandler::class),
            ImportType::Stock => app(StockRowHandler::class),
            ImportType::MinStock => app(MinStockRowHandler::class),
            ImportType::SupplierCatalog => app(SupplierCatalogRowHandler::class),
        };
    }

    public function canImport(User $user, ImportType $type): bool
    {
        return $this->scope->hasAny($user, $type->permission());
    }

    /**
     * Store the upload and queue the check.
     */
    public function start(ImportType $type, UploadedFile $file, User $user, ?Branch $branch = null): DataImport
    {
        $extension = mb_strtolower($file->getClientOriginalExtension() ?: 'xlsx');

        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw ValidationException::withMessages(['file' => __('Upload an Excel file (.xlsx, .xls) or .csv.')]);
        }

        $path = $file->storeAs('imports/'.now()->format('Y/m'), Str::uuid().'.'.$extension, self::DISK);

        return $this->startStored($type, (string) $path, $file->getClientOriginalName(), $user, $branch);
    }

    /**
     * Same as start(), for a file already saved on the imports disk (Filament upload field).
     */
    public function startStored(ImportType $type, string $path, string $originalName, User $user, ?Branch $branch = null): DataImport
    {
        if (! $this->canImport($user, $type)) {
            throw new AuthorizationException(__('You are not allowed to run this import.'));
        }

        $import = DataImport::query()->create([
            'type' => $type,
            'user_id' => $user->id,
            'branch_id' => $branch?->id,
            'original_disk' => self::DISK,
            'original_path' => $path,
            'original_filename' => mb_substr($originalName, 0, 191),
            'status' => ImportStatus::Uploaded,
        ]);

        activity('imports')->performedOn($import)->causedBy($user)->event('uploaded')
            ->withProperties(['type' => $type->value, 'file' => $import->original_filename])
            ->log('Excel import uploaded');

        ValidateImport::dispatch($import->id);

        return $import;
    }

    /**
     * Read every row, check it and store the preview.
     */
    public function validate(DataImport $import): void
    {
        $this->actAs($import);
        $this->raiseMemoryLimit();
        $import->forceFill(['status' => ImportStatus::Validating, 'failure_message' => null])->save();

        try {
            $handler = $this->handler($import->type);
            $rows = $handler instanceof ReadsWorkbook
                ? $handler->readWorkbook($this->originalPath($import))
                : $this->readRows($import, $handler);

            $handler->begin($import, $import->user);
            $import->rows()->delete();

            $counts = ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => 0, 'warning' => 0];
            $buffer = [];

            foreach ($rows as $rowNumber => $row) {
                $result = $handler->check($row, $rowNumber);
                $action = $result->action();

                $counts[match ($action) {
                    ImportRowAction::Create => 'new',
                    ImportRowAction::Update => 'updated',
                    ImportRowAction::Unchanged => 'unchanged',
                    ImportRowAction::Error => 'error',
                }]++;

                if ($result->warnings !== []) {
                    $counts['warning']++;
                }

                $buffer[] = [
                    'data_import_id' => $import->id,
                    'row_number' => $rowNumber,
                    'action' => $action->value,
                    'raw' => json_encode($row, JSON_UNESCAPED_UNICODE),
                    'normalized' => $result->normalized === [] ? null : json_encode($result->normalized, JSON_UNESCAPED_UNICODE),
                    'diff' => $result->diff === [] ? null : json_encode($result->diff, JSON_UNESCAPED_UNICODE),
                    'errors' => $result->errors === [] ? null : json_encode($result->errors, JSON_UNESCAPED_UNICODE),
                    'warnings' => $result->warnings === [] ? null : json_encode($result->warnings, JSON_UNESCAPED_UNICODE),
                    'target_type' => $result->targetType,
                    'target_id' => $result->targetId,
                ];

                if (count($buffer) >= 500) {
                    DataImportRow::query()->insert($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                DataImportRow::query()->insert($buffer);
            }

            $import->forceFill([
                'status' => ImportStatus::Previewed,
                'total_rows' => count($rows),
                'new_count' => $counts['new'],
                'updated_count' => $counts['updated'],
                'unchanged_count' => $counts['unchanged'],
                'error_count' => $counts['error'],
                'warning_count' => $counts['warning'],
                'processed_rows' => 0,
            ])->save();
        } catch (ImportFileInvalid $e) {
            $import->forceFill(['status' => ImportStatus::Failed, 'failure_message' => $e->getMessage(), 'finished_at' => now()])->save();
        } catch (Throwable $e) {
            report($e);
            $import->forceFill(['status' => ImportStatus::Failed, 'failure_message' => __('The file could not be read. Save it as .xlsx and try again.'), 'finished_at' => now()])->save();
        }
    }

    /**
     * The user checked the preview and wants the valid rows written.
     */
    public function confirm(DataImport $import, User $user): void
    {
        if ($import->status !== ImportStatus::Previewed) {
            throw ValidationException::withMessages(['import' => __('This import is not waiting for confirmation.')]);
        }

        if ($import->user_id !== $user->id && ! $user->isSuperAdmin()) {
            throw new AuthorizationException(__('Only the person who uploaded the file can confirm it.'));
        }

        if ($import->applicableCount() === 0) {
            throw ValidationException::withMessages(['import' => __('There is nothing new or changed to import.')]);
        }

        $import->forceFill(['status' => ImportStatus::Confirmed])->save();

        activity('imports')->performedOn($import)->causedBy($user)->event('confirmed')
            ->withProperties(['new' => $import->new_count, 'updated' => $import->updated_count, 'errors' => $import->error_count])
            ->log('Excel import confirmed');

        ApplyImport::dispatch($import->id);
    }

    public function cancel(DataImport $import, User $user): void
    {
        if (! in_array($import->status, [ImportStatus::Uploaded, ImportStatus::Previewed, ImportStatus::Failed], true)) {
            throw ValidationException::withMessages(['import' => __('This import can no longer be cancelled.')]);
        }

        $import->forceFill(['status' => ImportStatus::Cancelled, 'finished_at' => now()])->save();

        activity('imports')->performedOn($import)->causedBy($user)->event('cancelled')->log('Excel import cancelled');
    }

    /**
     * Write the confirmed rows (queued). Each row is its own transaction: one bad row never
     * blocks the others; it goes to the error file instead.
     */
    public function apply(DataImport $import): void
    {
        if ($import->status !== ImportStatus::Confirmed) {
            return;
        }

        $this->actAs($import);
        $this->raiseMemoryLimit();
        $import->forceFill(['status' => ImportStatus::Processing, 'started_at' => now(), 'processed_rows' => 0])->save();

        $handler = $this->handler($import->type);
        $handler->begin($import, $import->user);
        $failed = 0;
        $processed = 0;

        try {
            $import->rows()
                ->whereIn('action', [ImportRowAction::Create->value, ImportRowAction::Update->value])
                ->whereNull('applied_at')
                ->orderBy('row_number')
                ->chunkById((int) config('orthotrack.import_chunk_size', 500), function ($rows) use ($handler, $import, &$failed, &$processed) {
                    foreach ($rows as $row) {
                        try {
                            DB::transaction(function () use ($handler, $row, $import) {
                                $handler->apply($row, $import, $import->user);
                                $row->applied_at = now();
                                $row->save();
                            });
                        } catch (RowFailed $e) {
                            $row->forceFill(['action' => ImportRowAction::Error, 'errors' => [$e->getMessage()]])->save();
                            $failed++;
                        }

                        $processed++;
                    }

                    $import->forceFill(['processed_rows' => $processed])->save();
                });

            $import->forceFill([
                'status' => ImportStatus::Completed,
                'finished_at' => now(),
                'error_count' => $import->error_count + $failed,
                'new_count' => $import->rows()->where('action', ImportRowAction::Create)->whereNotNull('applied_at')->count(),
                'updated_count' => $import->rows()->where('action', ImportRowAction::Update)->whereNotNull('applied_at')->count(),
            ])->save();
        } catch (Throwable $e) {
            report($e);
            $import->forceFill(['status' => ImportStatus::Failed, 'failure_message' => __('The import stopped: :message', ['message' => Str::limit($e->getMessage(), 300)]), 'finished_at' => now()])->save();
        }

        if ($import->error_count > 0) {
            $this->writeErrorFile($import);
        }

        activity('imports')->performedOn($import)->causedBy($import->user)->event('completed')
            ->withProperties(['new' => $import->new_count, 'updated' => $import->updated_count, 'errors' => $import->error_count, 'status' => $import->status->value])
            ->log('Excel import finished');
    }

    /**
     * Template with an example row and an instructions sheet in every language.
     */
    public function template(ImportType $type): Workbook
    {
        $handler = $this->handler($type);
        $columns = $handler->columns();
        $example = $handler->example();

        $instructions = [];
        $current = App::getLocale();

        foreach (app(LocaleRegistry::class)->all() as $code => $locale) {
            App::setLocale($code);
            $instructions[] = [$locale['native']];

            foreach ($handler->instructions() as $line) {
                $instructions[] = ['• '.$line];
            }

            $instructions[] = ['• '.__('Dates: YYYY-MM-DD. Numbers and codes: Western digits (0-9).')];
            $instructions[] = [''];
        }

        App::setLocale($current);

        return new Workbook([
            new ArraySheet('Data', array_values($columns), [array_values(array_merge(array_fill_keys(array_keys($columns), ''), $example))], range(1, count($columns))),
            new ArraySheet('Instructions', [__('How to fill this file')], $instructions),
        ]);
    }

    /**
     * Rows that failed (preview errors + rows that failed while applying), with their reasons.
     */
    public function writeErrorFile(DataImport $import): string
    {
        $columns = $this->handler($import->type)->columns();
        $rows = [];

        $import->rows()->where('action', ImportRowAction::Error)->orderBy('row_number')->each(function (DataImportRow $row) use ($columns, &$rows) {
            $values = [$row->row_number];

            foreach (array_keys($columns) as $key) {
                $values[] = $row->raw[$key] ?? '';
            }

            $values[] = implode(' · ', $row->errors ?? []);
            $values[] = implode(' · ', $row->warnings ?? []);
            $rows[] = $values;
        });

        $path = 'imports/errors/import-'.$import->id.'-errors.xlsx';
        $headings = array_merge([__('Row')], array_values($columns), [__('Errors'), __('Warnings')]);

        Excel::store(new ArraySheet(__('Errors'), $headings, $rows, range(2, count($columns) + 1)), $path, self::DISK);

        $import->forceFill(['error_file_path' => $path])->save();

        return $path;
    }

    public function originalPath(DataImport $import): string
    {
        return Storage::disk($import->original_disk)->path($import->original_path);
    }

    /**
     * @return array<int, array<string, mixed>> spreadsheet row number → column key → raw cell
     */
    private function readRows(DataImport $import, RowHandler $handler): array
    {
        $sheets = Excel::toArray(new RawRowsImport, $import->original_path, $import->original_disk);
        $sheet = $sheets[0] ?? [];

        if ($sheet === []) {
            throw new ImportFileInvalid(__('The file is empty.'));
        }

        $header = array_shift($sheet);
        $map = $this->mapHeader((array) $header, $handler);

        $missing = array_diff($handler->requiredColumns(), array_values($map));

        if ($missing !== []) {
            $names = array_map(fn (string $key) => $handler->columns()[$key], $missing);

            throw new ImportFileInvalid(__('Missing columns: :columns. Download the template to see the expected columns.', ['columns' => implode(', ', $names)]));
        }

        $rows = [];

        foreach ($sheet as $index => $cells) {
            $row = [];

            foreach ($map as $position => $key) {
                $row[$key] = $cells[$position] ?? null;
            }

            if (array_filter($row, fn ($v) => $v !== null && trim((string) $v) !== '') === []) {
                continue;
            }

            $rows[$index + 2] = $row;
        }

        if ($rows === []) {
            throw new ImportFileInvalid(__('The file has a header row but no data rows.'));
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new ImportFileInvalid(__('The file has more than :max rows. Split it into smaller files.', ['max' => self::MAX_ROWS]));
        }

        return $rows;
    }

    /**
     * Header cell → column key. Accepts the English header, the key itself, or the header
     * translated into any supported language.
     *
     * @param  list<mixed>  $header
     * @return array<int, string> position → key
     */
    private function mapHeader(array $header, RowHandler $handler): array
    {
        $lookup = [];

        foreach ($handler->columns() as $key => $label) {
            $lookup[CellParser::key($key)] = $key;
            $lookup[CellParser::key($label)] = $key;

            foreach (array_keys(app(LocaleRegistry::class)->all()) as $locale) {
                $lookup[CellParser::key(__($label, [], $locale))] = $key;
            }
        }

        $map = [];

        foreach ($header as $position => $cell) {
            $key = $lookup[CellParser::key(CellParser::text($cell))] ?? null;

            if ($key !== null && ! in_array($key, $map, true)) {
                $map[$position] = $key;
            }
        }

        return $map;
    }

    /**
     * A supplier catalog is thousands of rows (each kept in the activity log): give the job
     * room and time instead of failing half-way on small default limits.
     */
    private function raiseMemoryLimit(): void
    {
        $limit = ini_get('memory_limit');

        if ($limit !== false && $limit !== '-1' && $this->bytes($limit) < $this->bytes(self::MEMORY_LIMIT)) {
            ini_set('memory_limit', self::MEMORY_LIMIT);
        }

        // Without a queue worker (QUEUE_CONNECTION=sync) the import runs inside the request.
        set_time_limit(0);
    }

    private function bytes(string $value): int
    {
        $number = (int) $value;

        return match (mb_strtolower(mb_substr(trim($value), -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Queued jobs run without a session: write as the uploader (created_by, activity causer).
     */
    private function actAs(DataImport $import): void
    {
        if (Auth::id() !== $import->user_id) {
            Auth::setUser($import->user);
        }
    }
}
