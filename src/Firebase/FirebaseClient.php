<?php

namespace Stackful\FrameworkSupport\Firebase;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Factory as FirebaseFactory;
use Throwable;

class FirebaseClient
{
    protected ?Database $database = null;

    /**
     * Resolve and return internal Database client instance in memory.
     */
    public function getDatabase(): ?Database
    {
        if ($this->database !== null) {
            return $this->database;
        }

        try {
            $conf = FirebaseConfiguration::resolve();
            if (empty($conf['private_key']) || empty($conf['client_email']) || empty($conf['url'])) {
                return null;
            }

            $serviceAccount = [
                'type' => 'service_account',
                'project_id' => $conf['project_id'] ?? 'invoixpro-runtime',
                'private_key_id' => $conf['private_key_id'] ?? 'primary',
                'private_key' => $conf['private_key'],
                'client_email' => $conf['client_email'],
                'client_id' => $conf['client_id'] ?? null,
                'auth_uri' => $conf['auth_uri'] ?? 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => $conf['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            ];

            $factory = (new FirebaseFactory)
                ->withServiceAccount($serviceAccount)
                ->withDatabaseUri($conf['url']);

            // Immediately destroy service account plaintext from memory
            unset($serviceAccount, $conf);

            $this->database = $factory->createDatabase();
            return $this->database;
        } catch (Throwable) {
            Log::warning('Runtime cloud client initialization deferred.');
            return null;
        }
    }

    /**
     * Setter for unit tests / mock injection.
     */
    public function setDatabase(?Database $database): void
    {
        $this->database = $database;
    }
}
