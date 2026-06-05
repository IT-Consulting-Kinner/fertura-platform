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
            'Ihre Einladung zu Fertura',
            "Hallo $username,\n\n"
            . "für Ihr Konto wurde eine Einladung erstellt. Bitte setzen Sie Ihr Passwort:\n\n"
            . "$link\n\n"
            . "Der Link ist 72 Stunden gültig.\n",
        );
    }

    public function sendPasswordReset(string $to, string $username, string $link): bool
    {
        return $this->send(
            $to,
            'Passwort zurücksetzen',
            "Hallo $username,\n\n"
            . "zum Zurücksetzen Ihres Passworts öffnen Sie bitte folgenden Link:\n\n"
            . "$link\n\n"
            . "Falls Sie das nicht angefordert haben, ignorieren Sie diese E-Mail.\n",
        );
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
