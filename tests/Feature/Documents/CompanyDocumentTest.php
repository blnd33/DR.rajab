<?php

use App\Enums\AttachmentKind;
use App\Enums\DocumentSection;
use App\Enums\Role;
use App\Filament\Admin\Pages\DocumentFolderPage;
use App\Models\CompanyDocument;
use App\Models\DocumentFolder;
use App\Services\Documents\CompanyDocumentService;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Company documents: folders in the side menu (Catalogues, Official company documents, Supplier
 * company documents, Shipment documents, two in Finance) holding uploaded files. Super Admin and
 * Manager; nothing is deleted.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->dhk = branch('DHK');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->documents = app(CompanyDocumentService::class);
    $this->folder = fn (string $en) => DocumentFolder::query()->get()->first(fn (DocumentFolder $f) => $f->getTranslation('name', 'en') === $en);
});

function pdf(string $name = 'scan.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 120, 'application/pdf');
}

it('creates the folders the company asked for, in their menu sections', function () {
    $names = fn (DocumentSection $s) => DocumentFolder::query()->where('section', $s->value)->orderBy('sort')->get()->map(fn ($f) => $f->getTranslation('name', 'en'))->all();

    expect($names(DocumentSection::Catalogues))->toBe(['Lima', 'Mikron', 'Covision'])
        ->and($names(DocumentSection::Official))->toBe(['Company registration'])
        ->and($names(DocumentSection::Supplier))->toBe(['Contractual', 'Declarations', 'PL / invoice / Dual use / COO', 'Others'])
        ->and($names(DocumentSection::Shipment))->toBe(['AWB', 'Customs clearance', 'Income tax', 'Internal transport', 'Other'])
        ->and($names(DocumentSection::Finance))->toBe(['Legal / Regulatory department', 'Internal transport invoices / PL'])
        ->and(($this->folder)('Customs clearance')->getTranslation('name', 'ar'))->toBe('التخليص الجمركي');
});

it('uploads documents with their files, edits them and archives them with a reason', function () {
    $awb = ($this->folder)('AWB');

    $document = $this->documents->upload($awb, ['title' => 'Knee implants from Lima', 'reference' => '176-12345675', 'document_date' => '2026-11-12'],
        [pdf('awb.pdf'), UploadedFile::fake()->image('boxes.jpg')], $this->admin);

    expect($document->files()->pluck('original_name')->sort()->values()->all())->toBe(['awb.pdf', 'boxes.jpg'])
        ->and($document->files()->first()->kind)->toBe(AttachmentKind::Document)
        ->and($document->reference)->toBe('176-12345675');

    $this->documents->update($document, ['title' => 'Knee implants from Lima (corrected)', 'expires_on' => '2027-01-01', 'document_date' => '2026-11-12'], $this->admin, [pdf('awb-page-2.pdf')]);
    expect($document->fresh()->files()->count())->toBe(3)
        ->and($document->fresh()->title)->toBe('Knee implants from Lima (corrected)');

    expect(fn () => $this->documents->archive($document, ' ', $this->admin))->toThrow(ValidationException::class);
    $this->documents->archive($document, 'Duplicate upload', $this->admin);

    expect($document->fresh()->isArchived())->toBeTrue()
        ->and(CompanyDocument::query()->count())->toBe(1)
        ->and(fn () => $this->documents->update($document->fresh(), ['title' => 'x'], $this->admin))->toThrow(AuthorizationException::class);

    $this->documents->restore($document->fresh(), $this->admin);
    expect($document->fresh()->isArchived())->toBeFalse();
});

it('refuses a document without files, a wrong file type or an expiry before its date', function (array $data, ?array $files, string $field) {
    try {
        $this->documents->upload(($this->folder)('Contractual'), $data + ['title' => 'Contract'], $files ?? [pdf()], $this->admin);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'no files' => [[], [], 'files'],
    'a program' => [[], [UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload')], 'files'],
    'no title' => [['title' => ' '], null, 'title'],
    'expires before its date' => [['document_date' => '2026-05-01', 'expires_on' => '2026-04-01'], null, 'expires_on'],
]);

it('lets only Super Admin and Manager see and upload, and keeps the files private', function () {
    $manager = userWithRole(Role::Manager, [$this->dhk]);
    $accountant = userWithRole(Role::Accountant, [$this->dhk]);
    $document = $this->documents->upload(($this->folder)('Company registration'), ['title' => 'Registration certificate'], [pdf('registration.pdf')], $this->admin);
    $file = $document->files()->firstOrFail();

    expect($manager->can('documents.view') && $manager->can('documents.upload') && $manager->can('documents.folders'))->toBeTrue()
        ->and($accountant->can('documents.view'))->toBeFalse()
        ->and(fn () => $this->documents->upload(($this->folder)('Others'), ['title' => 'X'], [pdf()], $accountant))->toThrow(AuthorizationException::class);

    $this->actingAs($manager)->get(route('attachments.show', $file))->assertOk();
    $this->actingAs($accountant)->get(route('attachments.show', $file))->assertForbidden();
    $this->actingAs($accountant)->get(DocumentFolderPage::getUrl(['folder' => $document->document_folder_id]))->assertForbidden();
    $this->actingAs($accountant)->get('/admin')->assertOk()->assertDontSee('Covision');
});

it('shows every folder in its menu section, and new or hidden folders at once', function () {
    $this->actingAs($this->admin);

    $this->get('/admin')->assertOk()
        // Catalogues: the four categories, All catalogues, then companies not sorted yet.
        ->assertSeeInOrder(['Catalogues', 'Arthroplasty', 'Trauma', 'Spine', 'Sport medicine', 'All catalogues', 'Covision'])
        ->assertSeeInOrder(['Shipment documents', 'AWB', 'Customs clearance', 'Income tax', 'Internal transport'])
        ->assertSee('Legal / Regulatory department')
        ->assertSee('Internal transport invoices / PL');

    $new = $this->documents->saveFolder(null, ['section' => 'catalogues', 'name' => ['en' => 'Zimmer', 'ar' => 'زيمر']], $this->admin);
    $this->documents->saveFolder(($this->folder)('Covision'), ['name' => ['en' => 'Covision'], 'is_active' => false], $this->admin);

    // The menu is built once per request (a scoped service); a test makes several requests.
    app()->forgetScopedInstances();
    $this->get('/admin')->assertSee('Zimmer')->assertDontSee('Covision');
    expect($new->section)->toBe(DocumentSection::Catalogues);

    expect(fn () => $this->documents->saveFolder(null, ['section' => 'catalogues', 'name' => ['ar' => 'بدون إنجليزي']], $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $this->documents->saveFolder(null, ['section' => 'catalogues', 'name' => ['en' => 'X']], userWithRole(Role::Accountant, [$this->dhk])))->toThrow(AuthorizationException::class);
});

it('uploads from the folder page', function () {
    $this->actingAs($this->admin);
    $lima = ($this->folder)('Lima');

    Livewire::test(DocumentFolderPage::class, ['folder' => $lima->id])
        ->assertSee('Lima')
        // A real PDF: the server checks the content, not the name.
        ->callAction('upload', ['title' => 'Lima catalogue 2026', 'document_date' => '2026-11-01',
            'files' => [UploadedFile::fake()->createWithContent('lima-2026.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")]])
        ->assertHasNoActionErrors()
        ->assertSee('Lima catalogue 2026')
        ->assertSee('lima-2026.pdf');

    expect($lima->documents()->sole()->files()->sole()->original_name)->toBe('lima-2026.pdf');
});

it('opens the document screens in every language', function (string $locale) {
    $this->documents->upload(($this->folder)('AWB'), ['title' => 'AWB 176', 'expires_on' => '2020-01-01'], [pdf()], $this->admin);
    $this->admin->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->admin->fresh());

    foreach (DocumentFolder::query()->get() as $folder) {
        $this->get(DocumentFolderPage::getUrl(['folder' => $folder->id]))->assertOk();
    }

    $this->get('/admin/document-folders')->assertOk();
    $this->get('/admin/documents?folder=999999')->assertNotFound();
})->with(['en', 'ar', 'ckb']);
