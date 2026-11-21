<?php

declare(strict_types=1);

/*
 * File storage fixture: uploads validated and stored on a public and a private local disk,
 * served by StorageRoutes. Env: OMNITRIX_FIXTURE_PORT, FIXTURE_ROOT, FIXTURE_KEY.
 */

use Omnitrix\Application;
use Omnitrix\Facades\Storage;
use Omnitrix\Filesystem\StorageRoutes;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$portEnv = getenv('OMNITRIX_FIXTURE_PORT');
$port = is_string($portEnv) && $portEnv !== '' ? (int) $portEnv : 18720;
$root = (string) getenv('FIXTURE_ROOT');

$app = Application::create([
    'server' => ['host' => '127.0.0.1', 'port' => $port, 'workers' => 2, 'log_level' => 'error', 'package_max_length' => 8 * 1024 * 1024],
    'http' => ['max_body_size' => 8 * 1024 * 1024],
    'app' => ['url' => "http://127.0.0.1:{$port}", 'key' => (string) getenv('FIXTURE_KEY')],
    'filesystems' => [
        'default' => 'local',
        'disks' => [
            'local' => ['driver' => 'local', 'root' => $root . '/private'],
            'public' => ['driver' => 'local', 'root' => $root . '/public', 'url' => '/storage', 'visibility' => 'public'],
        ],
    ],
]);
StorageRoutes::register($app->router());
$app->post('/avatars', static function (Request $request): Response {
    $data = $request->validate(['name' => 'required|string', 'avatar' => 'required|file|image|max:512']);
    $avatar = $request->file('avatar') ?? throw new LogicException('validated');
    $path = $avatar->store('avatars', 'public');

    return Response::json(['name' => $data['name'], 'path' => $path, 'url' => Storage::disk('public')->url($path), 'client' => $avatar->clientName()], 201);
});
$app->post('/documents', static function (Request $request): Response {
    $request->validate(['doc' => 'required|file|mimes:pdf,txt', 'extra' => 'file']);
    $doc = $request->file('doc') ?? throw new LogicException('validated');
    $path = $doc->storeAs('docs', 'report.' . $doc->extension());
    $photos = [];
    $uploaded = $request->allFiles()['photos'] ?? [];
    foreach (is_array($uploaded) ? $uploaded : [$uploaded] as $photo) {
        $photos[] = $photo->clientName();
    }

    return Response::json(['path' => $path, 'link' => Storage::temporaryUrl($path, 60), 'expired' => Storage::temporaryUrl($path, new DateTimeImmutable('-1 minute')), 'photos' => $photos], 201);
});

$app->run();
