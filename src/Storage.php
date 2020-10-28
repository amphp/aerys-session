<?php

namespace Amp\Http\Server\Session;

interface Storage
{
    /**
     * Reads the session contents.
     *
     * @param string $id The session identifier.
     *
     * @return array Current session data.
     */
    public function read(string $id): array;

    /**
     * Saves a session.
     *
     * @param string $id The session identifier.
     * @param mixed  $data Data to store.
     */
    public function write(string $id, array $data): void;
}
