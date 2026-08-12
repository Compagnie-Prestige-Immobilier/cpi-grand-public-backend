<?php

namespace App\Http\Controllers\Api;

use App\Dto\UserData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StaffController extends Controller
{
    /**
     * GET /staff/staff/list — liste des comptes personnel (admin uniquement).
     */
    public function listStaff(): JsonResponse
    {
        $this->authorize('manage-staff');

        $staff = User::role(['agent-cpi', 'super-admin'])->get();

        return response()->json([
            'data' => UserData::collect($staff),
        ]);
    }

    /**
     * POST /staff/staff/create — création d'un compte personnel (admin uniquement).
     */
    public function createStaff(Request $request): UserData
    {
        $this->authorize('manage-staff');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'role' => 'required|in:agent-cpi,super-admin',
        ]);

        // Mot de passe temporaire aligné sur la politique appliquée aux
        // inscriptions (10 caractères minimum, lettres et chiffres) : il n'y
        // avait aucune raison qu'un compte du personnel — qui accède à tous les
        // dossiers — démarre avec une exigence plus faible qu'un compte client.
        $tempPassword = Str::password(16, symbols: false);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($tempPassword),
        ]);

        $user->assignRole($validated['role']);

        // La création d'un compte capable de lire tous les dossiers clients ne
        // laissait aucune trace : le journal d'activité couvrait les gestes
        // métier mais pas les mouvements de comptes du personnel.
        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties(['role' => $validated['role'], 'email' => $user->email])
            ->event('compte-staff-cree')
            ->log("{$request->user()?->name} a créé le compte {$validated['role']} de {$user->name}");

        // Return the temp password so the admin can share it with the new staff member
        return UserData::from($user)->additional(['temporary_password' => $tempPassword]);
    }

    /**
     * DELETE /staff/staff/{user} — suppression d'un compte personnel (admin uniquement).
     */
    public function deleteStaff(Request $request, User $user): JsonResponse
    {
        $this->authorize('manage-staff');

        // Un admin ne peut pas supprimer son propre compte.
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte.'], 422);
        }

        // Cet endpoint ne gère que les comptes du personnel CPI.
        if (! $user->hasAnyRole(['agent-cpi', 'super-admin'])) {
            return response()->json(['message' => "Cet utilisateur n'est pas un membre du personnel."], 422);
        }

        // Journaliser AVANT la suppression : après, le nom et le rôle ne sont
        // plus lisibles.
        activity()
            ->causedBy($request->user())
            ->withProperties([
                'role' => $user->getRoleNames()->first(),
                'email' => $user->email,
                'utilisateur_supprime' => $user->id,
            ])
            ->event('compte-staff-supprime')
            ->log("{$request->user()?->name} a supprimé le compte de {$user->name}");

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Compte supprimé.']);
    }
}
