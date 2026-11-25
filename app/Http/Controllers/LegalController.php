<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class LegalController extends Controller
{
    public function privacyPolicy(): View
    {
        return view('legal.privacy-policy', [
            'appName' => (string) config('app.name', 'SewaKasir'),
            'contactEmail' => (string) env('LEGAL_CONTACT_EMAIL', 'support@sewakasir.id'),
            'lastUpdated' => '21 November 2026',
        ]);
    }

    public function showAccountDeletion(): View
    {
        return view('legal.account-deletion', [
            'appName' => (string) config('app.name', 'SewaKasir'),
            'contactEmail' => (string) env('LEGAL_CONTACT_EMAIL', 'support@sewakasir.id'),
        ]);
    }

    public function submitAccountDeletion(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'email.required' => 'Alamat email wajib diisi.',
            'email.email' => 'Format alamat email tidak valid.',
            'password.required' => 'Kata sandi wajib diisi untuk verifikasi.',
        ]);

        $email = strtolower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->is_active) {
            return back()
                ->withInput($request->only('email', 'reason'))
                ->withErrors([
                    'email' => 'Akun dengan email tersebut tidak ditemukan atau sudah dinonaktifkan.',
                ]);
        }

        if (empty($user->password)) {
            return back()
                ->withInput($request->only('email', 'reason'))
                ->withErrors([
                    'password' => 'Akun ini masuk menggunakan Google tanpa kata sandi. Silakan buat kata sandi terlebih dahulu di menu Profil Saya dalam aplikasi atau hubungi dukungan kami.',
                ]);
        }

        if (! Hash::check($validated['password'], $user->password)) {
            return back()
                ->withInput($request->only('email', 'reason'))
                ->withErrors([
                    'password' => 'Kata sandi yang Anda masukkan tidak sesuai.',
                ]);
        }

        // Prevent leaving any active business orphaned without an active owner
        $ownedBusinesses = Business::query()
            ->where('is_active', true)
            ->where(function ($query) use ($user) {
                $query->where('owner_id', $user->id)
                    ->orWhereHas('members', function ($memberQuery) use ($user) {
                        $memberQuery->where('user_id', $user->id)
                            ->where('role', 'owner')
                            ->where('is_active', true);
                    });
            })
            ->get();

        $orphanedBusinesses = [];
        foreach ($ownedBusinesses as $business) {
            $hasAnotherActiveOwner = BusinessMember::query()
                ->where('business_id', $business->id)
                ->where('user_id', '!=', $user->id)
                ->where('role', 'owner')
                ->where('is_active', true)
                ->exists();

            if (! $hasAnotherActiveOwner) {
                $orphanedBusinesses[] = $business->name;
            }
        }

        if (! empty($orphanedBusinesses)) {
            $list = implode(', ', $orphanedBusinesses);

            return back()
                ->withInput($request->only('email', 'reason'))
                ->withErrors([
                    'ownership' => "Penghapusan ditahan: akun Anda masih menjadi pemilik tunggal aktif pada bisnis ({$list}). Harap transfer kepemilikan ke anggota lain atau tutup cabang/bisnis aktif terlebih dahulu sebelum menghapus akun.",
                ]);
        }

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();

            BusinessMember::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'left_at' => now(),
                ]);

            $shortId = substr((string) $user->id, 0, 8);
            $user->forceFill([
                'is_active' => false,
                'name' => 'Deleted User #' . $shortId,
                'email' => 'deleted_' . $user->id . '@deleted.sewakasir.local',
                'phone' => null,
                'avatar_url' => null,
                'google_id' => null,
                'manager_override_pin' => null,
                'remember_token' => null,
            ])->save();
        });

        Log::info('Account deactivated and personal PII anonymized via web request.', [
            'user_id' => $user->id,
            'has_reason' => ! empty(trim((string) ($validated['reason'] ?? ''))),
        ]);

        return redirect()
            ->route('legal.account-deletion')
            ->with(
                'deletion_success',
                'Permintaan penghapusan akun berhasil diproses. Seluruh sesi login telah dicabut, data pribadi Anda telah dianonimkan, dan pembersihan sisa salinan cadangan diselesaikan maksimal dalam 7 hari.'
            );
    }
}
