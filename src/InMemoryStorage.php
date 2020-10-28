<?php

namespace Amp\Http\Server\Session;

use Amp\Cache\ArrayCache;
use Amp\Serialization\CompressingSerializer;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;

/**
 * This driver saves all sessions in memory, mainly for local development purposes.
 *
 * Locking happens via LocalMutex, so it won't work correctly with multiple processes.
 */
final class InMemoryStorage implements Storage
{
    public const DEFAULT_SESSION_LIFETIME = 3600;

    private ArrayCache $storage;
    private Serializer $serializer;
    private int $sessionLifetime;

    public function __construct(?Serializer $serializer = null, int $sessionLifetime = self::DEFAULT_SESSION_LIFETIME)
    {
        $this->serializer = $serializer ?? new CompressingSerializer(new NativeSerializer);
        $this->sessionLifetime = $sessionLifetime;
        $this->storage = new ArrayCache;
    }

    public function write(string $id, array $data): void
    {
        if (empty($data)) {
            try {
                $this->storage->delete($id);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't delete session '{$id}''", 0, $error);
            }

            return;
        }

        try {
            $serializedData = $this->serializer->serialize($data);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't serialize data for session '{$id}'", 0, $error);
        }

        try {
            $this->storage->set($id, $serializedData, $this->sessionLifetime);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't persist data for session '{$id}'", 0, $error);
        }
    }

    public function read(string $id): array
    {
        try {
            $result = $this->storage->get($id);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't read data for session '${id}'", 0, $error);
        }

        if ($result === null) {
            return [];
        }

        try {
            $data = $this->serializer->unserialize($result);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't read data for session '${id}'", 0, $error);
        }

        try {
            // Cache::set() can only be used here, because we know the implementation is synchronous,
            // otherwise we'd need locking
            $this->storage->set($id, $result, $this->sessionLifetime);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't renew expiry for session '{$id}'", 0, $error);
        }

        return $data;
    }
}
