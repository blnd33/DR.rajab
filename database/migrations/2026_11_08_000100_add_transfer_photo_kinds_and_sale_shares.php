<?php

use App\Enums\AttachmentKind;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transfer photos (sent / received) and sales split by percentage:
 *  - two attachment kinds for the photo taken when sending and the one taken on arrival;
 *  - a sale is priced per item or as one total split by cost share;
 *  - every sold line keeps its cost share and price share (percent of the invoice).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->attachmentKinds(AttachmentKind::values());

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('pricing', 16)->default('per_item')->after('currency');
        });

        Schema::table('sales_invoice_items', function (Blueprint $table) {
            $table->decimal('cost_share', 9, 4)->nullable()->after('line_total');
            $table->decimal('price_share', 9, 4)->nullable()->after('cost_share');
        });

        Pg::enumCheck('sales_invoices', 'pricing', ['per_item', 'split']);
    }

    public function down(): void
    {
        if (Pg::isPostgres()) {
            DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_pricing_check');
        }

        Schema::table('sales_invoice_items', fn (Blueprint $table) => $table->dropColumn(['cost_share', 'price_share']));
        Schema::table('sales_invoices', fn (Blueprint $table) => $table->dropColumn('pricing'));

        $this->attachmentKinds(array_values(array_diff(AttachmentKind::values(), ['transfer_sent', 'transfer_received'])));
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
