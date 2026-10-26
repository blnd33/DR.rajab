<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private attachments (write-off photos, invoices…): shown only to people who may view
 * the document they belong to.
 */
class AttachmentController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): StreamedResponse
    {
        $owner = $attachment->attachable;
        abort_if($owner === null, 404);
        abort_unless($request->user()?->can('view', $owner), 403);

        $disposition = str_starts_with((string) $attachment->mime, 'image/') || $attachment->mime === 'application/pdf' ? 'inline' : 'attachment';

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [], $disposition);
    }
}
