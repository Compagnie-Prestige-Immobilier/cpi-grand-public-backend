<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

/**
 * Notifications applicatives (table `app_notifications`).
 *
 * Les rôles `client`, `agent-cpi` et `super-admin` détiennent tous
 * `view-notifications` : la séparation ne vient donc pas de la permission mais
 * de l'appartenance. Un client ne voit et ne marque lues QUE les notifications
 * qui lui sont adressées (`client_id` de son dossier ou `user_id` de son
 * compte) ; le personnel CPI consulte le flux complet. Seul `send-notifications`
 * (agent-cpi + super-admin, jamais le client) autorise l'émission.
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-notifications');
    }

    public function view(User $user, Notification $notification): bool
    {
        if ($user->hasAnyRole(['agent-cpi', 'super-admin'])) {
            return $user->hasPermissionTo('view-notifications');
        }

        return $user->hasPermissionTo('view-notifications')
            && $this->isAddressedTo($user, $notification);
    }

    /**
     * Marquer une notification comme lue : geste du destinataire, et de lui
     * seul — un client ne touche jamais la boîte d'un autre dossier.
     */
    public function markRead(User $user, Notification $notification): bool
    {
        return $user->hasPermissionTo('view-notifications')
            && $this->isAddressedTo($user, $notification);
    }

    /**
     * Émission d'une notification vers un dossier client.
     */
    public function send(User $user): bool
    {
        return $user->hasPermissionTo('send-notifications');
    }

    /**
     * Destinataire : le compte lui-même, ou le dossier client rattaché au compte.
     */
    private function isAddressedTo(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id
            || ($notification->client_id !== null && $notification->client?->user_id === $user->id);
    }
}
