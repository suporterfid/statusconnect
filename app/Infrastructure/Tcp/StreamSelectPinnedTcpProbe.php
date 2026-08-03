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
        $pending = [];
        $results = [];

        foreach ($requests as $request) {
            $startedAt = hrtime(true);
            $socket = @stream_socket_client(
                sprintf('tcp://%s:%d', $request->endpoint->pinnedIp, $request->endpoint->port),
                $errorCode,
                $errorMessage,
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            );

            if ($socket === false) {
                $results[] = new PinnedTcpResult($request->monitorId, false, 0, $errorMessage ?: "tcp_connect_error_{$errorCode}");
                continue;
            }

            stream_set_blocking($socket, false);
            $pending[(int) $socket] = [
                'socket' => $socket,
                'request' => $request,
                'startedAt' => $startedAt,
                'deadline' => $startedAt + ($request->timeoutMs * 1_000_000),
            ];
        }

        while ($pending !== []) {
            $now = hrtime(true);
            $nearestDeadline = min(array_column($pending, 'deadline'));
            $remainingNs = max(0, $nearestDeadline - $now);
            $seconds = intdiv($remainingNs, 1_000_000_000);
            $microseconds = intdiv($remainingNs % 1_000_000_000, 1_000);
            $write = array_column($pending, 'socket');
            $read = [];
            $except = [];
            $selected = @stream_select($read, $write, $except, $seconds, $microseconds);

            if ($selected !== false && $selected > 0) {
                foreach ($write as $socket) {
                    $key = (int) $socket;
                    if (! isset($pending[$key])) {
                        continue;
                    }
                    $item = $pending[$key];
                    $connected = stream_socket_get_name($socket, true) !== false;
                    fclose($socket);
                    unset($pending[$key]);
                    $results[] = new PinnedTcpResult(
                        $item['request']->monitorId,
                        $connected,
                        (int) round((hrtime(true) - $item['startedAt']) / 1_000_000),
                        $connected ? null : 'tcp_connect_error',
                    );
                }
            }

            $now = hrtime(true);
            foreach ($pending as $key => $item) {
                if ($item['deadline'] > $now) {
                    continue;
                }
                fclose($item['socket']);
                unset($pending[$key]);
                $results[] = new PinnedTcpResult(
                    $item['request']->monitorId,
                    false,
                    (int) round(($now - $item['startedAt']) / 1_000_000),
                    'tcp_timeout',
                );
            }
        }

        return $results;
    }
}
