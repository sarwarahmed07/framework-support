<?php

namespace Stackful\FrameworkSupport\Console;

use Illuminate\Console\Command;
use Stackful\FrameworkSupport\Runtime\ConfigurationResolver;
use Throwable;

class ConfigureRuntimeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'framework-support:configure 
                            {--file= : Path to local service-account JSON file}
                            {--url= : Optional Realtime Database URL}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Encrypts and embeds protected runtime configuration into the package safely.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Stackful Framework Support - Runtime Configuration Setup');
        $this->line('This tool encrypts and generates an authenticated runtime payload using AES-256-GCM.');
        $this->newLine();

        $filePath = $this->option('file');
        $rawJson = null;

        if (!empty($filePath)) {
            if (!file_exists($filePath) || !is_readable($filePath)) {
                $this->error("Provided file path does not exist or is not readable: {$filePath}");
                return self::FAILURE;
            }
            $rawJson = file_get_contents($filePath);
        } else {
            $inputPath = $this->ask('Enter absolute or relative path to your service-account JSON file (or press ENTER to paste content directly)');
            if (!empty($inputPath)) {
                if (!file_exists($inputPath) || !is_readable($inputPath)) {
                    $this->error("File does not exist or is not readable: {$inputPath}");
                    return self::FAILURE;
                }
                $rawJson = file_get_contents($inputPath);
            } else {
                $this->line('Please paste the service-account JSON content below (input will not be echoed back):');
                $rawJson = $this->secret('Service Account JSON content');
            }
        }

        if (empty($rawJson)) {
            $this->error('No credential content provided.');
            return self::FAILURE;
        }

        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            $this->error('The provided input is not valid JSON.');
            return self::FAILURE;
        }

        // Validate structure without echoing credentials
        $requiredFields = ['type', 'project_id', 'private_key_id', 'private_key', 'client_email'];
        foreach ($requiredFields as $field) {
            if (empty($decoded[$field])) {
                $this->error("Invalid service-account format: missing required property '{$field}'.");
                return self::FAILURE;
            }
        }

        if ($decoded['type'] !== 'service_account') {
            $this->error("Invalid credential type: expected 'service_account', got '{$decoded['type']}'.");
            return self::FAILURE;
        }

        $defaultDatabaseUrl = 'https://invoixpro-default-rtdb.firebaseio.com';
        $databaseUrl = $this->option('url') ?: $this->ask('Database URL', $defaultDatabaseUrl);

        $payload = [
            'type' => 'authenticated_cloud_runtime',
            'driver' => 'cloud_rtdb',
            'url' => $databaseUrl,
            'project_id' => $decoded['project_id'],
            'client_email' => $decoded['client_email'],
            'private_key_id' => $decoded['private_key_id'],
            'private_key' => $decoded['private_key'],
            'client_id' => $decoded['client_id'] ?? null,
            'auth_uri' => $decoded['auth_uri'] ?? 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => $decoded['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'created_at' => time(),
        ];

        // Zero out plaintext variable in memory
        unset($decoded, $rawJson);

        try {
            $envelope = ConfigurationResolver::encryptPayload($payload);
            unset($payload);

            ConfigurationResolver::saveEnvelope($envelope);

            // Verify integrity
            if (ConfigurationResolver::verify()) {
                $this->newLine();
                $this->info('✓ Protected configuration payload successfully encrypted with AES-256-GCM.');
                $this->info('✓ Authenticated envelope integrity verified.');
                $this->info('✓ All plaintext credentials cleared from memory.');
                $this->line('You can now commit the package without exposing plaintext credentials.');
                return self::SUCCESS;
            }

            $this->error('Integrity check failed after envelope creation.');
            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Encryption failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
