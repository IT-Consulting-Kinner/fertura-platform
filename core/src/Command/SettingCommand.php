<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Settings\SettingsCatalog;
use App\Service\Settings\SettingsManager;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use InvalidArgumentException;

/**
 * Reads/writes configuration values (until the admin GUI arrives in Step 10).
 *
 *   bin/cake setting get core password.min_length
 *   bin/cake setting set core password.min_length 16
 */
class SettingCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Konfigurationswerte lesen oder setzen.')
            ->addArgument('operation', ['help' => 'get|set', 'choices' => ['get', 'set'], 'required' => true])
            ->addArgument('namespace', ['help' => 'z. B. core', 'required' => true])
            ->addArgument('key', ['help' => 'z. B. password.min_length', 'required' => true])
            ->addArgument('value', ['help' => 'Wert (nur bei set)']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $manager = new SettingsManager();
        $catalog = new SettingsCatalog();
        $namespace = (string)$args->getArgument('namespace');
        $key = (string)$args->getArgument('key');

        if ($args->getArgument('operation') === 'get') {
            $value = $manager->get($namespace, $key);
            $io->out($this->render($value));

            return static::CODE_SUCCESS;
        }

        // set
        $raw = $args->getArgument('value');
        if ($raw === null) {
            $io->error('Für "set" ist ein Wert erforderlich.');

            return static::CODE_ERROR;
        }

        $def = $catalog->definition($namespace, $key);
        $parsed = $this->parse($raw, $def['type'] ?? 'string', $io);
        if ($parsed === self::PARSE_ERROR) {
            return static::CODE_ERROR;
        }

        try {
            $manager->set($namespace, $key, $parsed);
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return static::CODE_ERROR;
        }

        $io->success("Gesetzt: $namespace.$key");

        return static::CODE_SUCCESS;
    }

    private const PARSE_ERROR = "\0__parse_error__\0";

    private function parse(string $raw, string $type, ConsoleIo $io): mixed
    {
        switch ($type) {
            case 'int':
                if (!preg_match('/^-?\d+$/', $raw)) {
                    $io->error('Wert muss eine Ganzzahl sein.');

                    return self::PARSE_ERROR;
                }

                return (int)$raw;
            case 'bool':
                return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
            case 'json':
                $decoded = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $io->error('Ungültiges JSON.');

                    return self::PARSE_ERROR;
                }

                return $decoded;
            default:
                return $raw;
        }
    }

    private function render(mixed $value): string
    {
        if ($value === null) {
            return '(nicht gesetzt / null)';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }

        return (string)json_encode($value);
    }
}
