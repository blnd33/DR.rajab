<?php

use App\Enums\AttachmentKind;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('kind', 32)->default(AttachmentKind::Other->value);
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        Pg::enumCheck('attachments', 'kind', AttachmentKind::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
