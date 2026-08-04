<?php

namespace App\Http\Controllers\Public;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

final class StatusPageController
{
    public function show(Request $request, string $slug): Response
    {
        $snapshot = $this->cachedSnapshot($slug, $request->user()?->id);
        if ($snapshot === null) {
            abort(404);
        }

        if ($this->isNotModified($request, $snapshot->etag)) {
            return $this->notModified($snapshot->etag);
        }

        App::setLocale($snapshot->payload['locale']);

        return $this->cacheable(response()->view('status.page', [
            'snapshot' => $snapshot->payload,
            'unlisted' => $snapshot->visibility === 'unlisted',
        ]), $snapshot->etag);
    }

    public function json(Request $request, string $slug): JsonResponse|Response
    {
        $snapshot = $this->cachedSnapshot($slug, $request->user()?->id);
        if ($snapshot === null) {
            abort(404);
        }

        if ($this->isNotModified($request, $snapshot->etag)) {
            return $this->notModified($snapshot->etag);
        }

        return $this->cacheable(response()->json($snapshot->payload), $snapshot->etag);
    }

    private function cachedSnapshot(string $slug, ?int $userId): ?object
    {
        $row = DB::table('status_page_cache as cache')
            ->join('status_pages as page', 'page.id', '=', 'cache.status_page_id')
            ->where('page.slug', $slug)
            ->where(function ($query) use ($userId): void {
                $query->whereIn('page.visibility', ['public', 'unlisted']);

                if ($userId !== null) {
                    $query->orWhere(function ($private) use ($userId): void {
                        $private->where('page.visibility', 'private')
                            ->whereExists(function ($membership) use ($userId): void {
                                $membership->selectRaw('1')
                                    ->from('tenant_memberships')
                                    ->whereColumn('tenant_memberships.tenant_id', 'page.tenant_id')
                                    ->where('tenant_memberships.user_id', $userId);
                            });
                    });
                }
            })
            ->select(['cache.payload_json', 'cache.etag', 'page.visibility'])
            ->first();

        if ($row === null) {
            return null;
        }

        try {
            $payload = json_decode($row->payload_json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($payload) || ! isset($payload['locale'])) {
            return null;
        }

        return (object) ['etag' => $row->etag, 'payload' => $payload, 'visibility' => $row->visibility];
    }

    private function isNotModified(Request $request, string $etag): bool
    {
        return $request->header('If-None-Match') === sprintf('"%s"', $etag);
    }

    private function notModified(string $etag): Response
    {
        return $this->cacheable(response('', 304), $etag);
    }

    private function cacheable(Response|JsonResponse $response, string $etag): Response|JsonResponse
    {
        $response->headers->set('Cache-Control', 'public, max-age=60');
        $response->headers->set('ETag', sprintf('"%s"', $etag));

        return $response;
    }
}
