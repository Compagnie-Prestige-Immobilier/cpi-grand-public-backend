<?php

namespace App\Mail;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Demande adressée au support depuis l'espace client.
 *
 * Un Mailable plutôt qu'un `Mail::raw()` : la classe est testable
 * (`Mail::assertSent`), et l'objet comme l'adresse de réponse se déclarent au
 * même endroit que le corps du message.
 */
class SupportMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Client $client,
        public readonly string $emailClient,
        public readonly string $sujet,
        public readonly string $texte,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // La référence du dossier en tête d'objet : l'équipe rattache la
            // demande sans avoir à ouvrir le message.
            subject: "[{$this->client->ref}] {$this->sujet}",
            // Répondre écrit AU CLIENT, pas à la boîte d'envoi de la plateforme.
            replyTo: [new Address($this->emailClient, $this->client->name)],
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.support',
            with: [
                'client' => $this->client,
                'emailClient' => $this->emailClient,
                'sujet' => $this->sujet,
                'texte' => $this->texte,
            ],
        );
    }
}
