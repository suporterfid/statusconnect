<?php

namespace App\Infrastructure\Tcp;

final class StreamSelectPinnedTcpProbe implements PinnedTcpProbe
{
    /**
     * @param  list<PinnedTcpRequest>  $requests
     * @return list<PinnedTcpResult>
     */
    public function probe(array $requests): array
    {
        return $this->finish($this->start($requests));
    }

    /** @param list<PinnedTcpRequest> $requests */
    public function start(array $requests): PendingTcpBatch
    {
        $batch = new PendingTcpBatch();

        foreach ($requests as $request) {
            $startedAt = hrtime(true);
            $socket = @stream_socket_client(
                sprintf('tcp://%s:%d', str_contains($request->endpoint->pinnedIp, ':') ? "[{$request->endpoint->pinnedIp}]" : $request->endpoint->pinnedIp, $request->endpoint->port),
                $errorCode,
                $errorMessage,
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            );

            if ($socket === false) {
                $batch->results[] = new PinnedTcpResult($request->monitorId, false, 0, $errorMessage ?: "tcp_connect_error_{$errorCode}");
                continue;
            }

            stream_set_blocking($socket, false);
            $batch->pending[(int) $socket] = [
                'socket' => $socket,
                'request' => $request,
                'startedAt' => $startedAt,
                'deadline' => $startedAt + ($request->timeoutMs * 1_000_000),
            ];
        }

        return $batch;
    }

    public function finish(PendingTcpBatch $batch, ?int $deadline = null): array
    {
        while ($batch->pending !== []) {
            $now = hrtime(true);
            $nearestDeadline = min(array_column($batch->pending, 'deadline'));
            if ($deadline !== null) $nearestDeadline = min($nearestDeadline, $deadline);
            $remainingNs = max(0, $nearestDeadline - $now);
            $seconds = intdiv($remainingNs, 1_000_000_000);
            $microseconds = intdiv($remainingNs % 1_000_000_000, 1_000);
            $write = array_column($batch->pending, 'socket');
            $read = [];
            $except = [];
            $selected = @stream_select($read, $write, $except, $seconds, $microseconds);

            if ($selected !== false && $selected > 0) {
                foreach ($write as $socket) {
                    $key = (int) $socket;
                    if (! isset($batch->pending[$key])) {
                        continue;
                    }
                    $item = $batch->pending[$key];
                    $connected = stream_socket_get_name($socket, true) !== false;
                    fclose($socket);
                    unset($batch->pending[$key]);
                    $batch->results[] = new PinnedTcpResult(
                        $item['request']->monitorId,
                        $connected,
                        (int) round((hrtime(true) - $item['startedAt']) / 1_000_000),
                        $connected ? null : 'tcp_connect_error',
                    );
                }
            }

            $now = hrtime(true);
            foreach ($batch->pending as $key => $item) {
                if ($item['deadline'] > $now && ($deadline === null || $deadline > $now)) {
                    continue;
                }
                fclose($item['socket']);
                unset($batch->pending[$key]);
                $batch->results[] = new PinnedTcpResult(
                    $item['request']->monitorId,
                    false,
                    (int) round(($now - $item['startedAt']) / 1_000_000),
                    'tcp_timeout',
                );
            }
        }

        return $batch->results;
    }
}
