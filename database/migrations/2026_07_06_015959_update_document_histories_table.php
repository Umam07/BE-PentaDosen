<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop foreign key constraint on document_id first
        Schema::table('document_histories', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
        });

        // 2. Make document_id nullable and add penelitian_id
        Schema::table('document_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('document_id')->nullable()->change();
            $table->foreign('document_id')->references('id')->on('documents')->onDelete('cascade');
            
            $table->foreignId('penelitian_id')->nullable()->after('document_id')->constrained('penelitian')->onDelete('cascade');
        });

        // 3. Backfill history for existing documents and penelitian
        $adminFakultas = DB::table('users')->where('role', 'admin fakultas')->first();
        $adminLppm = DB::table('users')->where('role', 'admin penelitian')->first();

        $adminFakultasId = $adminFakultas ? $adminFakultas->id : null;
        $adminLppmId = $adminLppm ? $adminLppm->id : null;

        // Backfill Documents
        $documents = DB::table('documents')->get();
        foreach ($documents as $doc) {
            $hasUploaded = DB::table('document_histories')
                ->where('document_id', $doc->id)
                ->where('action', 'Dokumen Diunggah')
                ->exists();

            if (!$hasUploaded) {
                DB::table('document_histories')->insert([
                    'document_id' => $doc->id,
                    'user_id' => $doc->user_id,
                    'action' => 'Dokumen Diunggah',
                    'notes' => null,
                    'created_at' => $doc->created_at,
                    'updated_at' => $doc->created_at,
                ]);
            }

            if (in_array($doc->status, ['Verified by Fakultas', 'Approved'])) {
                $hasVerified = DB::table('document_histories')
                    ->where('document_id', $doc->id)
                    ->where('action', 'Diverifikasi Fakultas')
                    ->exists();

                if (!$hasVerified) {
                    $verifiedAt = $doc->status === 'Verified by Fakultas' ? $doc->updated_at : date('Y-m-d H:i:s', strtotime($doc->created_at) + 3600);
                    DB::table('document_histories')->insert([
                        'document_id' => $doc->id,
                        'user_id' => $adminFakultasId ?? $doc->user_id,
                        'action' => 'Diverifikasi Fakultas',
                        'notes' => null,
                        'created_at' => $verifiedAt,
                        'updated_at' => $verifiedAt,
                    ]);
                }
            }

            if ($doc->status === 'Approved') {
                $hasApproved = DB::table('document_histories')
                    ->where('document_id', $doc->id)
                    ->where('action', 'Disetujui Admin Penelitian')
                    ->exists();

                if (!$hasApproved) {
                    DB::table('document_histories')->insert([
                        'document_id' => $doc->id,
                        'user_id' => $adminLppmId ?? $doc->user_id,
                        'action' => 'Disetujui Admin Penelitian',
                        'notes' => null,
                        'created_at' => $doc->updated_at,
                        'updated_at' => $doc->updated_at,
                    ]);
                }
            }

            if ($doc->status === 'Rejected') {
                $hasRejected = DB::table('document_histories')
                    ->where('document_id', $doc->id)
                    ->where('action', 'like', 'Ditolak%')
                    ->exists();

                if (!$hasRejected) {
                    DB::table('document_histories')->insert([
                        'document_id' => $doc->id,
                        'user_id' => $adminLppmId ?? ($adminFakultasId ?? $doc->user_id),
                        'action' => 'Ditolak Penelitian',
                        'notes' => $doc->catatan,
                        'created_at' => $doc->updated_at,
                        'updated_at' => $doc->updated_at,
                    ]);
                }
            }
        }

        // Backfill Penelitian
        $penelitian = DB::table('penelitian')->get();
        foreach ($penelitian as $pen) {
            $hasUploaded = DB::table('document_histories')
                ->where('penelitian_id', $pen->id)
                ->where('action', 'Dokumen Diunggah')
                ->exists();

            if (!$hasUploaded) {
                DB::table('document_histories')->insert([
                    'penelitian_id' => $pen->id,
                    'user_id' => $pen->user_id,
                    'action' => 'Dokumen Diunggah',
                    'notes' => null,
                    'created_at' => $pen->created_at,
                    'updated_at' => $pen->created_at,
                ]);
            }

            if (in_array($pen->status, ['Verified by Fakultas', 'Approved'])) {
                $hasVerified = DB::table('document_histories')
                    ->where('penelitian_id', $pen->id)
                    ->where('action', 'Diverifikasi Fakultas')
                    ->exists();

                if (!$hasVerified) {
                    $verifiedAt = $pen->status === 'Verified by Fakultas' ? $pen->updated_at : date('Y-m-d H:i:s', strtotime($pen->created_at) + 3600);
                    DB::table('document_histories')->insert([
                        'penelitian_id' => $pen->id,
                        'user_id' => $adminFakultasId ?? $pen->user_id,
                        'action' => 'Diverifikasi Fakultas',
                        'notes' => null,
                        'created_at' => $verifiedAt,
                        'updated_at' => $verifiedAt,
                    ]);
                }
            }

            if ($pen->status === 'Approved') {
                $hasApproved = DB::table('document_histories')
                    ->where('penelitian_id', $pen->id)
                    ->where('action', 'Disetujui Admin Penelitian')
                    ->exists();

                if (!$hasApproved) {
                    DB::table('document_histories')->insert([
                        'penelitian_id' => $pen->id,
                        'user_id' => $adminLppmId ?? $pen->user_id,
                        'action' => 'Disetujui Admin Penelitian',
                        'notes' => null,
                        'created_at' => $pen->updated_at,
                        'updated_at' => $pen->updated_at,
                    ]);
                }
            }

            if ($pen->status === 'Rejected') {
                $hasRejected = DB::table('document_histories')
                    ->where('penelitian_id', $pen->id)
                    ->where('action', 'like', 'Ditolak%')
                    ->exists();

                if (!$hasRejected) {
                    DB::table('document_histories')->insert([
                        'penelitian_id' => $pen->id,
                        'user_id' => $adminLppmId ?? ($adminFakultasId ?? $pen->user_id),
                        'action' => 'Ditolak Penelitian',
                        'notes' => $pen->catatan,
                        'created_at' => $pen->updated_at,
                        'updated_at' => $pen->updated_at,
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_histories', function (Blueprint $table) {
            $table->dropForeign(['penelitian_id']);
            $table->dropColumn('penelitian_id');

            $table->dropForeign(['document_id']);
        });

        // Delete any histories that don't have document_id
        DB::table('document_histories')->whereNull('document_id')->delete();

        Schema::table('document_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('document_id')->nullable(false)->change();
            $table->foreign('document_id')->references('id')->on('documents')->onDelete('cascade');
        });
    }
};
