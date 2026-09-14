<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project members you can invite by EMAIL (docs/features/project-member-invitations.md).
 *
 * A project member used to be a workspace member you picked from a list, so the row only ever
 * needed a `user_id`. Inviting somebody who has no account yet — which is the whole point of an
 * invitation — means the row has to exist BEFORE the person does.
 *
 * So `user_id` becomes nullable and `email` identifies them until they accept. That is exactly
 * the shape `help_center_space_members` already uses for the same problem, deliberately: two
 * tables that agree are easier to reason about than one clever shared one, and the listener
 * that completes them is the same idea in both places.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_members', function (Blueprint $table) {
            // The address the invitation went to. Null for every row that predates this — those
            // were added from the workspace and have always had a user.
            $table->string('email')->nullable()->after('user_id');
            // What they will be when they accept. Copied onto `role` at that point and cleared,
            // so there is never a second answer to "what is this person" free to drift.
            $table->string('invited_role', 20)->nullable()->after('role');
            $table->foreignId('workspace_invitation_id')->nullable()->after('invited_role')
                ->constrained('workspace_invitations')->nullOnDelete();
            $table->timestamp('invited_at')->nullable()->after('workspace_invitation_id');

            // One outstanding invitation per address per project. The existing
            // unique(project_id, user_id) cannot do this job: MySQL allows any number of NULLs
            // in a unique index, so every placeholder would slip straight past it.
            $table->unique(['project_id', 'email'], 'project_members_project_email_unique');
        });

        $this->setUserIdNullable(true);
    }

    public function down(): void
    {
        // Placeholders have no user and cannot survive the column going back to NOT NULL. They
        // are invitations rather than memberships, and the invitation row itself still holds
        // everything about them.
        DB::table('project_members')->whereNull('user_id')->delete();
        $this->setUserIdNullable(false);

        Schema::table('project_members', function (Blueprint $table) {
            $table->dropUnique('project_members_project_email_unique');
            $table->dropConstrainedForeignId('workspace_invitation_id');
            $table->dropColumn(['email', 'invited_role', 'invited_at']);
        });
    }

    /**
     * Relax (or restore) the NOT NULL on `project_members.user_id`.
     *
     * MySQL gets `MODIFY`, which leaves the column's foreign key alone — Laravel's `->change()`
     * drops and rebuilds the key, and rebuilding it is exactly what is not wanted here.
     *
     * Everything else gets the schema builder. This USED to be a bare
     * `DB::statement('ALTER TABLE ... MODIFY ...')`, which is MySQL-only syntax: on SQLite it
     * throws `near "MODIFY": syntax error`, and because the test suite runs on an in-memory
     * SQLite database, that one line failed every migration and therefore every test in the
     * project — 142 of them, all with the same error and none of them about their own subject.
     * CLAUDE.md §6 asks for portable migrations for this reason.
     */
    private function setUserIdNullable(bool $nullable): void
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement(sprintf(
                'ALTER TABLE `project_members` MODIFY `user_id` BIGINT UNSIGNED %s',
                $nullable ? 'NULL' : 'NOT NULL',
            ));

            return;
        }

        Schema::table('project_members', function (Blueprint $table) use ($nullable) {
            $column = $table->foreignId('user_id');

            if ($nullable) {
                $column->nullable();
            }

            $column->change();
        });
    }
};
