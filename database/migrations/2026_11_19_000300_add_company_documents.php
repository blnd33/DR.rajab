<?php

use App\Enums\AttachmentKind;
use App\Enums\DocumentSection;
use App\Services\Permissions\PermissionRegistry;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Company documents: folders shown in the side menu (Catalogues, Official company documents,
 * Supplier company documents, Shipment documents, and two in Finance) holding uploaded files.
 * The folders the client asked for are created here; the super admin can add more.
 */
return new class extends Migration
{
    private const NEW = ['documents.view', 'documents.upload', 'documents.folders'];

    /** section => list of [en, ar, ckb] */
    private const FOLDERS = [
        'catalogues' => [
            ['Lima', 'Lima', 'Lima'],
            ['Mikron', 'Mikron', 'Mikron'],
            ['Covision', 'Covision', 'Covision'],
        ],
        'official' => [
            ['Company registration', 'تسجيل الشركة', 'تۆمارکردنی کۆمپانیا'],
        ],
        'supplier' => [
            ['Contractual', 'العقود', 'گرێبەستەکان'],
            ['Declarations', 'الإقرارات', 'ڕاگەیاندنەکان'],
            ['PL / invoice / Dual use / COO', 'قائمة التعبئة / الفاتورة / الاستخدام المزدوج / شهادة المنشأ', 'لیستی پێچان / پسوولە / بەکارهێنانی دووانە / بڕوانامەی بنەچە'],
            ['Others', 'أخرى', 'هیتر'],
        ],
        'shipment' => [
            ['AWB', 'بوليصة الشحن الجوي (AWB)', 'بارنامەی ئاسمانی (AWB)'],
            ['Customs clearance', 'التخليص الجمركي', 'ڕاپەڕاندنی گومرگی'],
            ['Income tax', 'ضريبة الدخل', 'باجی داهات'],
            ['Internal transport', 'النقل الداخلي', 'گواستنەوەی ناوخۆیی'],
            ['Other', 'أخرى', 'هیتر'],
        ],
        'finance' => [
            ['Legal / Regulatory department', 'القسم القانوني / التنظيمي', 'بەشی یاسایی / ڕێکخستن'],
            ['Internal transport invoices / PL', 'فواتير النقل الداخلي / قوائم التعبئة', 'پسوولەکانی گواستنەوەی ناوخۆیی / لیستی پێچان'],
        ],
    ];

    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            $table->string('section', 24);
            $table->jsonb('name');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['section', 'sort']);
        });

        Pg::enumCheck('document_folders', 'section', DocumentSection::values());

        Schema::create('company_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_folder_id')->constrained('document_folders');
            $table->string('title', 191);
            $table->string('reference', 120)->nullable();
            $table->date('document_date')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('notes')->nullable();
            // Never deleted: archived with a reason.
            $table->timestampTz('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users');
            $table->text('archive_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['document_folder_id', 'document_date']);
        });

        $this->attachmentKinds(AttachmentKind::values());

        $now = now();

        foreach (self::FOLDERS as $section => $folders) {
            foreach ($folders as $sort => [$en, $ar, $ckb]) {
                DB::table('document_folders')->insert([
                    'section' => $section,
                    'name' => json_encode(['en' => $en, 'ar' => $ar, 'ckb' => $ckb], JSON_UNESCAPED_UNICODE),
                    'sort' => $sort + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (! DB::table('permissions')->exists()) {
            return;
        }

        foreach (self::NEW as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (PermissionRegistry::roleTemplates() as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $give = array_values(array_intersect(self::NEW, $permissions));

            if ($role !== null && $give !== []) {
                $role->givePermissionTo($give);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::NEW)->get()->each(fn (Permission $p) => $p->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::table('attachments')->where('kind', AttachmentKind::Document->value)->delete();
        $this->attachmentKinds(array_values(array_diff(AttachmentKind::values(), [AttachmentKind::Document->value])));

        Schema::dropIfExists('company_documents');
        Schema::dropIfExists('document_folders');
    }

    /**
     * @param  list<string>  $kinds
     */
    private function attachmentKinds(array $kinds): void
    {
        if (Pg::isPostgres()) {
            DB::statement('ALTER TABLE attachments DROP CONSTRAINT IF EXISTS attachments_kind_check');
        }

        Pg::enumCheck('attachments', 'kind', $kinds);
    }
};
