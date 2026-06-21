<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        if (Schema::hasTable('exception_cases') && ! Schema::hasTable('issues')) {
            Schema::rename('exception_cases', 'issues');
        }

        if (Schema::hasTable('exception_case_lines') && ! Schema::hasTable('issue_lines')) {
            Schema::rename('exception_case_lines', 'issue_lines');
        }

        if (Schema::hasTable('issues')) {
            if (Schema::hasColumn('issues', 'case_no') && ! Schema::hasColumn('issues', 'issue_no')) {
                Schema::table('issues', fn ($table) => $table->renameColumn('case_no', 'issue_no'));
            }

            if (Schema::hasColumn('issues', 'case_type') && ! Schema::hasColumn('issues', 'issue_type')) {
                Schema::table('issues', fn ($table) => $table->renameColumn('case_type', 'issue_type'));
            }

            DB::table('issues')
                ->where('issue_no', 'like', 'EC-%')
                ->update(['issue_no' => DB::raw("replace(issue_no, 'EC-', 'ISS-')")]);
        }

        if (
            Schema::hasTable('issue_lines')
            && Schema::hasColumn('issue_lines', 'exception_case_id')
            && ! Schema::hasColumn('issue_lines', 'issue_id')
        ) {
            Schema::table('issue_lines', fn ($table) => $table->renameColumn('exception_case_id', 'issue_id'));
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        if (
            Schema::hasTable('issue_lines')
            && Schema::hasColumn('issue_lines', 'issue_id')
            && ! Schema::hasColumn('issue_lines', 'exception_case_id')
        ) {
            Schema::table('issue_lines', fn ($table) => $table->renameColumn('issue_id', 'exception_case_id'));
        }

        if (Schema::hasTable('issues')) {
            DB::table('issues')
                ->where('issue_no', 'like', 'ISS-%')
                ->update(['issue_no' => DB::raw("replace(issue_no, 'ISS-', 'EC-')")]);

            if (Schema::hasColumn('issues', 'issue_type') && ! Schema::hasColumn('issues', 'case_type')) {
                Schema::table('issues', fn ($table) => $table->renameColumn('issue_type', 'case_type'));
            }

            if (Schema::hasColumn('issues', 'issue_no') && ! Schema::hasColumn('issues', 'case_no')) {
                Schema::table('issues', fn ($table) => $table->renameColumn('issue_no', 'case_no'));
            }
        }

        if (Schema::hasTable('issue_lines') && ! Schema::hasTable('exception_case_lines')) {
            Schema::rename('issue_lines', 'exception_case_lines');
        }

        if (Schema::hasTable('issues') && ! Schema::hasTable('exception_cases')) {
            Schema::rename('issues', 'exception_cases');
        }

        Schema::enableForeignKeyConstraints();
    }
};
