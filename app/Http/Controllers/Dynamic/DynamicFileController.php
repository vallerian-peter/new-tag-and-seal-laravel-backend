<?php

namespace App\Http\Controllers\Dynamic;

use App\Http\Controllers\Controller;
use App\Services\Dynamic\FarmAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photos for configured forms. The client uploads first (with its own UUID so a
 * retry is harmless), then submits the event with only the file UUID.
 */
class DynamicFileController extends Controller
{
    private const DISK = 'local';

    public function store(Request $request, FarmAccessService $access): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'farmUuid' => ['required', 'string', 'max:36'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        abort_unless($access->canAccess($request->user(), $data['farmUuid']), 403);
        $existing = DB::table('dynamic_files')->where('uuid', $data['uuid'])->first();
        if ($existing) {
            abort_unless($existing->created_by_uuid === $request->user()->uuid, 409);

            return response()->json(['status' => true, 'data' => $this->present($existing)], 201);
        }
        $file = $request->file('file');
        $path = $file->storeAs("dynamic-files/{$data['farmUuid']}", $data['uuid'].'.'.$file->extension(), self::DISK);
        DB::table('dynamic_files')->insert([
            'uuid' => $data['uuid'],
            'farm_uuid' => $data['farmUuid'],
            'disk' => self::DISK,
            'path' => $path,
            'content_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'created_by_uuid' => $request->user()->uuid,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => true, 'data' => $this->present(DB::table('dynamic_files')->where('uuid', $data['uuid'])->first())], 201);
    }

    public function show(Request $request, string $uuid, FarmAccessService $access): StreamedResponse
    {
        $file = DB::table('dynamic_files')->where('uuid', $uuid)->first();
        abort_unless($file, 404);
        abort_unless($file->farm_uuid === null || $access->canAccess($request->user(), $file->farm_uuid), 403);

        return Storage::disk($file->disk)->response($file->path, null, ['Content-Type' => $file->content_type]);
    }

    private function present(object $file): array
    {
        return ['uuid' => $file->uuid, 'contentType' => $file->content_type, 'sizeBytes' => (int) $file->size_bytes, 'sha256' => $file->sha256];
    }
}
