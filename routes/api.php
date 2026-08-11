<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\SocialAuthController;
use App\Http\Controllers\Api\BankController;
use App\Http\Controllers\Api\Chantier\ChantierController;
use App\Http\Controllers\Api\Chantier\ChantierEventController;
use App\Http\Controllers\Api\Chantier\ChantierMediaController;
use App\Http\Controllers\Api\Chantier\ChantierPublicationController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CpiDocController;
use App\Http\Controllers\Api\DecaissementController;
use App\Http\Controllers\Api\DemandeController;
use App\Http\Controllers\Api\DocController;
use App\Http\Controllers\Api\HistoriqueController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StatsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES — no auth required
|--------------------------------------------------------------------------
*/

// Unified login — email/password for all users.
// The app reads the user's role from the response and redirects accordingly.
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');

// Google OAuth — fetching the redirect URL is a read → GET;
// the callback receives the code from the frontend → POST
Route::get('/auth/google/redirect', [SocialAuthController::class, 'redirectToGoogle']);
Route::post('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);

// Onboarding: Google users with incomplete profiles must fill this before accessing the app
Route::post('/auth/onboarding', [AuthController::class, 'completeOnboarding'])->middleware('auth:sanctum');
Route::post('/auth/avatar', [AuthController::class, 'updateAvatar'])->middleware('auth:sanctum');
Route::delete('/auth/avatar', [AuthController::class, 'removeAvatar'])->middleware('auth:sanctum');
Route::get('/auth/onboarding-status', [AuthController::class, 'onboardingStatus'])->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| CLIENT ROUTES — auth:sanctum + client middleware
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'client'])->prefix('client')->group(function () {

    // login/logout/me live under /auth — unified for all user types

    // Own profile
    Route::get('/profile', [ClientController::class, 'myProfile']);
    Route::put('/profile', [ClientController::class, 'updateMyProfile']);

    // Own demande
    Route::get('/ma-demande', [DemandeController::class, 'mine']);
    Route::post('/ma-demande', [DemandeController::class, 'saveMine']);
    Route::post('/ma-demande/submit', [DemandeController::class, 'submitMine']);
    // Seule route client qui renvoie un fichier (PDF) et non du JSON.
    Route::get('/ma-demande/recapitulatif', [DemandeController::class, 'recapitulatifMine']);

    // Own documents
    Route::get('/mes-documents', [DocController::class, 'mine']);
    Route::post('/mes-documents/{doc}/deposit', [DocController::class, 'depositMine']);

    // Own CPI docs
    Route::get('/mes-documents-cpi', [CpiDocController::class, 'mine']);
    Route::post('/mes-documents-cpi/{doc}/sign', [CpiDocController::class, 'signByClient']);

    // Own dossier journey
    Route::get('/mon-dossier-journey', [ClientController::class, 'myDossierJourney']);

    // Own chantier (if construction active)
    Route::get('/mon-chantier', [ChantierController::class, 'mine']);

    // Own bank assignments
    Route::get('/mes-banques', [BankController::class, 'myAssignments']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'mine']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
});

/*
|--------------------------------------------------------------------------
| STAFF ROUTES — auth:sanctum + staff middleware
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'staff'])->prefix('staff')->group(function () {

    // login/logout/me live under /auth — unified for all user types

    // Staff management (admin only — enforced via the 'manage-staff' permission)
    Route::get('/staff/list', [StaffController::class, 'listStaff']);
    Route::post('/staff/create', [StaffController::class, 'createStaff']);
    Route::delete('/staff/{user}', [StaffController::class, 'deleteStaff']);

    // Clients
    Route::get('/clients', [ClientController::class, 'index']);
    Route::get('/clients/{client}', [ClientController::class, 'show']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::put('/clients/{client}', [ClientController::class, 'update']);
    Route::delete('/clients/{client}', [ClientController::class, 'destroy']);
    Route::get('/clients/{client}/summary', [ClientController::class, 'summary']);
    Route::post('/clients/{client}/dossier-etape', [ClientController::class, 'setDossierEtape']);
    Route::get('/clients/{client}/dossier-journey', [ClientController::class, 'dossierJourney']);

    // Documents management
    Route::get('/clients/{client}/docs', [DocController::class, 'index']);
    Route::post('/clients/{client}/docs/{doc}/accept', [DocController::class, 'accept']);
    Route::post('/clients/{client}/docs/{doc}/refuse', [DocController::class, 'refuse']);
    Route::post('/clients/{client}/docs/{doc}/replace', [DocController::class, 'requestReplacement']);
    Route::post('/clients/{client}/docs/{doc}/verify', [DocController::class, 'remettreVerification']);

    // CPI Docs management
    Route::get('/cpi-docs', [CpiDocController::class, 'index']);
    Route::post('/cpi-docs', [CpiDocController::class, 'store']);
    Route::put('/cpi-docs/{cpiDoc}', [CpiDocController::class, 'update']);
    Route::delete('/cpi-docs/{cpiDoc}', [CpiDocController::class, 'destroy']);
    Route::post('/cpi-docs/{cpiDoc}/upload', [CpiDocController::class, 'upload']);
    Route::post('/cpi-docs/{cpiDoc}/publish', [CpiDocController::class, 'publish']);
    Route::post('/cpi-docs/{cpiDoc}/archive', [CpiDocController::class, 'archive']);
    Route::post('/cpi-docs/{cpiDoc}/sign', [CpiDocController::class, 'sign']);

    // Banks
    Route::get('/banks', [BankController::class, 'index']);
    Route::post('/banks', [BankController::class, 'store']);
    Route::put('/banks/{bank}', [BankController::class, 'update']);
    Route::delete('/banks/{bank}', [BankController::class, 'destroy']);
    Route::post('/clients/{client}/banks/{bank}/assign', [BankController::class, 'assign']);
    Route::post('/clients/{client}/banks/{bank}/status', [BankController::class, 'setStatus']);
    Route::delete('/clients/{client}/banks/{bank}', [BankController::class, 'removeAssignment']);

    // Decaissements
    Route::get('/decaissements/{client}', [DecaissementController::class, 'show']);
    Route::put('/decaissements/{client}', [DecaissementController::class, 'update']);
    Route::post('/decaissements/{client}/validate-terrain', [DecaissementController::class, 'validateTerrain']);
    Route::post('/decaissements/{client}/validate-foncier/{step}', [DecaissementController::class, 'validateFoncierStep']);
    Route::post('/decaissements/{client}/validate-tranche/{num}', [DecaissementController::class, 'validateTranche']);

    // Chantier
    Route::get('/chantiers/{client}', [ChantierController::class, 'show']);
    Route::put('/chantiers/{client}', [ChantierController::class, 'update']);
    Route::post('/chantiers/{client}/progression', [ChantierController::class, 'updateProgression']);
    Route::post('/chantiers/{client}/etape', [ChantierController::class, 'updateEtape']);
    Route::post('/chantiers/{client}/statut', [ChantierController::class, 'updateStatut']);
    Route::post('/chantiers/{client}/tranche/{num}/validate', [ChantierController::class, 'validateTranche']);
    // Explicit nested routes — do NOT use apiResource with a {placeholder} in the URI
    // (it produces broken route names and parameter bindings)
    Route::prefix('/chantiers/{client}')->group(function () {
        Route::get('/publications', [ChantierPublicationController::class, 'index']);
        Route::post('/publications', [ChantierPublicationController::class, 'store']);
        Route::put('/publications/{publication}', [ChantierPublicationController::class, 'update']);
        Route::delete('/publications/{publication}', [ChantierPublicationController::class, 'destroy']);

        Route::get('/medias', [ChantierMediaController::class, 'index']);
        Route::post('/medias', [ChantierMediaController::class, 'store']);
        Route::put('/medias/{media}', [ChantierMediaController::class, 'update']);
        Route::delete('/medias/{media}', [ChantierMediaController::class, 'destroy']);

        Route::get('/events', [ChantierEventController::class, 'index']);
        Route::post('/events', [ChantierEventController::class, 'store']);
        Route::put('/events/{event}', [ChantierEventController::class, 'update']);
        Route::delete('/events/{event}', [ChantierEventController::class, 'destroy']);
    });

    // Notifications (send to clients)
    Route::post('/notifications/send', [NotificationController::class, 'send']);
    Route::get('/notifications', [NotificationController::class, 'index']);

    // Activity log
    Route::get('/historique', [HistoriqueController::class, 'index']);
    Route::get('/historique/{client}', [HistoriqueController::class, 'forClient']);

    // Stats
    Route::get('/stats/dashboard', [StatsController::class, 'dashboard']);
    Route::get('/stats/agent', [StatsController::class, 'agent']);
    Route::get('/stats/admin', [StatsController::class, 'admin']);

    // Demo data management (admin only — checked via policy)
    Route::post('/demo/seed', [ClientController::class, 'seedDemo']);
    Route::delete('/demo/clear', [ClientController::class, 'clearDemo']);
});
