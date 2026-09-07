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

        // Raw rather than `->change()`: the column carries a foreign key and Laravel's change
        // path drops and rebuilds it. MODIFY leaves the key alone and only relaxes the null
        // constraint, which is the whole of what is wanted here.
        DB::statement('ALTER TABLE `project_members` MODIFY `user_id` BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Placeholders have no user and cannot survive the column going back to NOT NULL. They
        // are invitations rather than memberships, and the invitation row itself still holds
        // everything about them.
        DB::table('project_members')->whereNull('user_id')->delete();
        DB::statement('ALTER TABLE `project_members` MODIFY `user_id` BIGINT UNSIGNED NOT NULL');

        Schema::table('project_members', function (Blueprint $table) {
            $table->dropUnique('project_members_project_email_unique');
            $table->dropConstrainedForeignId('workspace_invitation_id');
            $table->dropColumn(['email', 'invited_role', 'invited_at']);
        });
    }
};
