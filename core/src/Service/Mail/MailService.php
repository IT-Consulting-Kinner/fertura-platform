<?php
declare(strict_types=1);

namespace App\Service\Mail;

use App\Service\Settings\SettingsManager;
use Cake\Log\Log;
use Cake\Mailer\Mailer;
use Throwable;

/**
 * Schlanker Core-Mailversand für System-/Identitätsmails (Einladung, Passwort-
 * Reset). Nutzt den konfigurierten Transport (EmailTransport.default, per
 * EMAIL_TRANSPORT_DEFAULT_URL). Fachliche Benachrichtigungen bleiben Modul-Sache.
 *
 * Fehlertolerant: Schlägt der Versand fehl (Transport nicht erreichbar), wird
 * `false` zurückgegeben — der aufrufende Flow (z. B. Einladung) zeigt dann den
 * Link als Fallback an.
 */
class MailService
{
    public function __construct(private ?SettingsManager $settings = null)
    {
        $this->settings ??= new SettingsManager();
    }

    public function enabled(): bool
    {
        return (bool)$this->settings->get('core', 'mail.enabled', true);
    }

    public function sendInvitation(string $to, string $username, string $link): bool
    {
        return $this->send(
            $to,
            __('mail.invite.subject'),
            __('mail.invite.body', [$username, $link]),
        );
    }

    public function sendPasswordReset(string $to, string $username, string $link): bool
    {
        return $this->send(
            $to,
            __('mail.reset.subject'),
            __('mail.reset.body', [$username, $link]),
        );
    }

    /** Generische System-Benachrichtigung (z. B. Backup-Alarm). */
    public function notify(string $to, string $subject, string $body): bool
    {
        return $this->send($to, $subject, $body);
    }

    private function send(string $to, string $subject, string $body): bool
    {
        if (!$this->enabled() || $to === '') {
            return false;
        }
        try {
            $from = (string)$this->settings->get('core', 'mail.from_address', 'no-reply@fertura.local');
            $name = (string)$this->settings->get('core', 'mail.from_name', 'Fertura');
            (new Mailer('default'))
                ->setFrom([$from => $name])
                ->setTo($to)
                ->setSubject($subject)
                ->deliver($body);

            return true;
        } catch (Throwable $e) {
            Log::warning('MailService Versand fehlgeschlagen: ' . $e->getMessage());

            return false;
        }
    }
}
