<?php

namespace App\Http\Controllers;

use App\Models\DataImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads for an import: the original uploaded file and the error file.
 */
class ImportFileController extends Controller
{
    public function original(Request $request, DataImport $import): StreamedResponse
    {
        abort_unless($request->user()?->can('view', $import), 403);

        return Storage::disk($import->original_disk)->download($import->original_path, $import->original_filename);
    }

    public function errors(Request $request, DataImport $import): StreamedResponse
    {
        abort_unless($request->user()?->can('view', $import), 403);
        abort_if($import->error_file_path === null, 404);

        return Storage::disk($import->original_disk)->download($import->error_file_path, 'import-'.$import->id.'-errors.xlsx');
    }
}
