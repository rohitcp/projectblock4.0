<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The Back Office password-reset link (docs/features/backoffice-auth.md, §6).
 *
 * Its own notification rather than Laravel's default, for one load-bearing reason: the default
 * builds its URL from the `password.reset` route, which is the CUSTOMER application's. A Back
 * Office administrator following that link would land on the wrong reset form, and the token
 * would not resolve.
 */
class BackofficePasswordReset extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('backoffice.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $minutes = (int) config('auth.passwords.backoffice_users.expire', 30);

        /*
         * The shared Project Block shell rather than Laravel's markdown mail
         * (docs/features/transactional-emails.md §20). Laravel's default renders in ITS house
         * style — a different frame, a different button, no Project Block footer — so a
         * password reset was the one email that did not look like the product it came from,
         * which is exactly the email where that matters most.
         *
         * The reassurance and the expiry are in the template, said the same way for every
         * reset, instead of being assembled from `->line()` calls here.
         */
        return (new MailMessage)
            ->subject('Reset your Back Office password')
            ->view('emails.password-reset', [
                'resetUrl' => $url,
                'ttlMinutes' => $minutes,
                'recipientName' => method_exists($notifiable, 'displayName')
                    ? $notifiable->displayName()
                    : '',
            ]);
    }
}
