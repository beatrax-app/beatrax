<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Console\Concerns;

use Symfony\Component\Process\Process;

// The single-threaded select/accept/relay pump tunnelling the TLS front to
// the plain-HTTP backend.
trait RunsTlsProxyLoop
{
    private const READ_CHUNK = 65536;

    /**
     * @param  resource  $server
     */
    private function runProxyLoop($server, int $backendPort, ?Process $backend): int
    {
        // Keyed by (int) resource id. Each entry points at its peer's id and
        // carries the bytes waiting to be written *to this socket*.
        /** @var array<int, array{sock: resource, peer: int, wbuf: string}> $conns */
        $conns = [];

        while ($this->running) {
            if ($backend !== null && ! $backend->isRunning()) {
                $this->newLine();
                $this->error('Backend `artisan serve` stopped unexpectedly — shutting down the tunnel.');

                return self::FAILURE;
            }

            [$read, $write] = $this->selectSockets($server, $conns);
            if ($read === null) {
                continue;
            }

            $this->pumpWritable($conns, $write);
            $this->pumpReadable($server, $backendPort, $conns, $read);
        }

        $this->closeAll($conns);

        return self::SUCCESS;
    }

    /**
     * @param  resource  $server
     * @param  array<int, array{sock: resource, peer: int, wbuf: string}>  $conns
     * @return array{0: list<resource>|null, 1: list<resource>} post-select
     *                                                          readable/writable sets, or [null, []] when nothing is ready this tick
     */
    private function selectSockets($server, array $conns): array
    {
        $read = [$server];
        $write = [];
        foreach ($conns as $conn) {
            $read[] = $conn['sock'];
            if ($conn['wbuf'] !== '') {
                $write[] = $conn['sock'];
            }
        }

        $except = null;
        $ready = @stream_select($read, $write, $except, 1);
        if ($ready === false || $ready === 0) {
            return [null, []];
        }

        return [$read, $write];
    }

    /**
     * @param  array<int, array{sock: resource, peer: int, wbuf: string}>  $conns
     * @param  list<resource>  $write
     */
    private function pumpWritable(array &$conns, array $write): void
    {
        foreach ($write as $sock) {
            $id = $this->sockId($sock);
            if (isset($conns[$id])) {
                $this->flush($conns, $id);
            }
        }
    }

    /**
     * @param  resource  $server
     * @param  array<int, array{sock: resource, peer: int, wbuf: string}>  $conns
     * @param  list<resource>  $read
     */
    private function pumpReadable($server, int $backendPort, array &$conns, array $read): void
    {
        foreach ($read as $sock) {
            if ($sock === $server) {
                $this->accept($server, $backendPort, $conns);

                continue;
            }
            $this->relay($conns, $sock);
        }
    }

    /**
     * @param  array<int, array{sock: resource, peer: int, wbuf: string}>  $conns
     * @param  resource  $sock
     */
    private function relay(array &$conns, $sock): void
    {
        $id = $this->sockId($sock);
        if (! isset($conns[$id])) {
            return;
        }

        $data = @fread($sock, self::READ_CHUNK);
        if ($data === false || ($data === '' && feof($sock))) {
            $this->closePair($conns, $id);

            return;
        }
        if ($data === '') {
            return;
        }

        $peerId = $conns[$id]['peer'];
        if (isset($conns[$peerId])) {
            $conns[$peerId]['wbuf'] .= $data;
            $this->flush($conns, $peerId);
        }
    }

    /**
     * @param  array<int, array{sock: resource, peer: int, wbuf: string}>  $conns
     */
    private function closeAll(array &$conns): void
    {
        foreach (array_keys($conns) as $id) {
            $this->closePair($conns, $id);
        }
    }

    /**
     * @param  resource  $server
     * @param  array<int, array{sock: resource, peer: int, wbuf: string}>  $conns
     */
    private function accept($server, int $backendPort, array &$conns): void
    {
        // A short handshake timeout keeps a stalled client from blocking the
        // single-threaded loop; local UAT traffic completes far inside it.
        $client = @stream_socket_accept($server, 5);
        if ($client === false) {
            return;
        }
        stream_set_blocking($client, false);

        $errno = 0;
        $errstr = '';
        $upstream = @stream_socket_client(
            'tcp://127.0.0.1:'.$backendPort,
            $errno,
            $errstr,
            5,
        );
        if ($upstream === false) {
            fclose($client);

            return;
        }
        stream_set_blocking($upstream, false);

        $clientId = $this->sockId($client);
        $upstreamId = $this->sockId($upstream);

        $conns[$clientId] = ['sock' => $client, 'peer' => $upstreamId, 'wbuf' => ''];
        $conns[$upstreamId] = ['sock' => $upstream, 'peer' => $clientId, 'wbuf' => ''];
    }

    /**
     * @param  array<int, array{sock: resource, peer: int, wbuf: string}>  $conns
     */
    private function flush(array &$conns, int $id): void
    {
        if (! isset($conns[$id]) || $conns[$id]['wbuf'] === '') {
            return;
        }

        $written = @fwrite($conns[$id]['sock'], $conns[$id]['wbuf']);
        if ($written === false) {
            $this->closePair($conns, $id);

            return;
        }

        $conns[$id]['wbuf'] = substr($conns[$id]['wbuf'], $written);
    }

    /**
     * @param  array<int, array{sock: resource, peer: int, wbuf: string}>  $conns
     */
    private function closePair(array &$conns, int $id): void
    {
        if (! isset($conns[$id])) {
            return;
        }

        $peerId = $conns[$id]['peer'];

        if (isset($conns[$peerId]) && $conns[$peerId]['wbuf'] !== '') {
            @fwrite($conns[$peerId]['sock'], $conns[$peerId]['wbuf']);
        }

        foreach ([$id, $peerId] as $closeId) {
            if (isset($conns[$closeId])) {
                @fclose($conns[$closeId]['sock']);
                unset($conns[$closeId]);
            }
        }
    }

    /**
     * @param  resource  $sock
     */
    private function sockId($sock): int
    {
        return (int) $sock;
    }
}
