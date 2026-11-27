<?php

use App\Enums\Role;
use App\Enums\Specialty;
use App\Filament\Admin\Pages\AllCataloguesPage;
use App\Models\DocumentFolder;
use App\Services\Documents\CompanyDocumentService;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
 * Catalogues by category: Catalogues → Arthroplasty / Trauma / Spine / Sport medicine → the
 * companies → their catalogue documents; and All catalogues, every company's documents together.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')]);
    $this->documents = app(CompanyDocumentService::class);
    $this->folder = fn (string $en) => DocumentFolder::query()->get()->first(fn (DocumentFolder $f) => $f->getTranslation('name', 'en') === $en);
});

function cataloguePdf(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
}

it('sorts the companies into categories: Lima under Arthroplasty, Mikron under Trauma', function () {
    expect(($this->folder)('Lima')->specialty)->toBe(Specialty::Arthroplasty)
        ->and(($this->folder)('Mikron')->specialty)->toBe(Specialty::Trauma)
        ->and(($this->folder)('Covision')->specialty)->toBeNull();

    // A category is chosen for catalogues only.
    $covision = $this->documents->saveFolder(($this->folder)('Covision'), ['name' => ['en' => 'Covision'], 'specialty' => 'spine'], $this->admin);
    $awb = $this->documents->saveFolder(($this->folder)('AWB'), ['name' => ['en' => 'AWB'], 'specialty' => 'spine'], $this->admin);

    expect($covision->specialty)->toBe(Specialty::Spine)
        ->and($awb->specialty)->toBeNull()
        // Left out of a later save, the category stays.
        ->and($this->documents->saveFolder($covision, ['name' => ['en' => 'Covision'], 'sort' => 9], $this->admin)->specialty)->toBe(Specialty::Spine);
});

it('opens Catalogues on the categories, a category on its companies, a company on its documents', function () {
    $this->actingAs($this->admin);

    $this->get('/admin/section?section=catalogues')->assertOk()
        ->assertSeeInOrder(['data-section-windows', 'Arthroplasty', 'Trauma', 'Spine', 'Sport medicine', 'All catalogues', 'Covision'], false);

    $this->get('/admin/catalogues?specialty=trauma')->assertOk()
        ->assertSee('Trauma')->assertSee('Mikron')->assertDontSee('Lima')
        ->assertSee('/admin/documents?folder='.($this->folder)('Mikron')->id, false);

    $this->get('/admin/catalogues?specialty=spine')->assertOk()->assertSee('No company has catalogues in this category yet');
    $this->get('/admin/catalogues?specialty=nonsense')->assertNotFound();
});

it('lists every company\'s catalogue documents together, by category or company', function () {
    $lima = $this->documents->upload(($this->folder)('Lima'), ['title' => 'Lima knee catalogue'], [cataloguePdf('lima.pdf')], $this->admin);
    $mikron = $this->documents->upload(($this->folder)('Mikron'), ['title' => 'Mikron plates catalogue'], [cataloguePdf('mikron.pdf')], $this->admin);
    $awb = $this->documents->upload(($this->folder)('AWB'), ['title' => 'Not a catalogue'], [cataloguePdf('awb.pdf')], $this->admin);

    $this->actingAs($this->admin);

    $this->get('/admin/catalogues/all')->assertOk()
        ->assertSee('Lima knee catalogue')->assertSee('Mikron plates catalogue')->assertDontSee('Not a catalogue');

    // Filtered by category (the list remembers its filters for the session).
    Livewire::test(AllCataloguesPage::class)
        ->assertSee($lima->title)->assertSee($mikron->title)->assertDontSee($awb->title)
        ->filterTable('specialty', Specialty::Trauma->value)
        ->assertSee($mikron->title)->assertDontSee($lima->title);
});

it('keeps catalogues for the people who may see documents', function () {
    $this->actingAs(userWithRole(Role::Accountant, [branch('DHK')]));

    $this->get('/admin/catalogues?specialty=trauma')->assertForbidden();
    $this->get('/admin/catalogues/all')->assertForbidden();
});
