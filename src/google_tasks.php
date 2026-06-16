<?php

declare(strict_types=1);

namespace GoogleTasksConnector;

use RuntimeException;

function repo_root(): string
{
    return dirname(__DIR__);
}

function storage_dir(): string
{
    return repo_root().'/storage';
}

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function require_config(): void
{
    foreach (['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI'] as $key) {
        if (env($key) === null) {
            throw new RuntimeException("Missing required environment variable: {$key}");
        }
    }
}

function base_url(): string
{
    $configured = env('APP_BASE_URL');
    if ($configured) {
        return rtrim($configured, '/');
    }

    $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';

    return $scheme.'://'.$host;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function redirect(string $location): never
{
    header('Location: '.$location);
    exit;
}

function token_storage_path(): string
{
    return repo_root().'/'.ltrim(env('GOOGLE_TOKEN_STORAGE', 'storage/google_tokens.json') ?? 'storage/google_tokens.json', '/');
}

function current_token_store(): ?array
{
    $path = token_storage_path();
    if (! is_file($path)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

function write_token_store(array $token): void
{
    $path = token_storage_path();
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    file_put_contents($path, json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function clear_token_store(): void
{
    $path = token_storage_path();
    if (is_file($path)) {
        unlink($path);
    }
}

function build_authorization_url(string $state): string
{
    $query = http_build_query([
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/tasks.readonly',
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'state' => $state,
    ]);

    return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
}

function finish_oauth_callback(array $query, ?string $expectedState): void
{
    if (($query['state'] ?? null) !== $expectedState) {
        throw new RuntimeException('OAuth state mismatch.');
    }

    if (! isset($query['code']) || $query['code'] === '') {
        throw new RuntimeException('Missing authorization code.');
    }

    $token = exchange_code_for_token((string) $query['code']);
    write_token_store($token);
}

function exchange_code_for_token(string $code): array
{
    return http_post_form('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'grant_type' => 'authorization_code',
    ]);
}

function refresh_access_token(string $refreshToken): array
{
    return http_post_form('https://oauth2.googleapis.com/token', [
        'refresh_token' => $refreshToken,
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'grant_type' => 'refresh_token',
    ]);
}

function get_access_token(): string
{
    $token = current_token_store();
    if (! $token) {
        throw new RuntimeException('No Google token stored. Open /connect first.');
    }

    $expiresAt = (int) ($token['expires_at'] ?? 0);
    if (($token['access_token'] ?? '') !== '' && $expiresAt > time() + 60) {
        return (string) $token['access_token'];
    }

    if (($token['refresh_token'] ?? '') === '') {
        throw new RuntimeException('No refresh token stored. Reconnect with prompt=consent.');
    }

    $refreshed = refresh_access_token((string) $token['refresh_token']);
    $token['access_token'] = $refreshed['access_token'] ?? null;
    $token['expires_in'] = $refreshed['expires_in'] ?? null;
    $token['expires_at'] = time() + (int) ($refreshed['expires_in'] ?? 0);

    if (isset($refreshed['scope'])) {
        $token['scope'] = $refreshed['scope'];
    }

    write_token_store($token);

    return (string) ($token['access_token'] ?? '');
}

function google_get(string $url, array $query = []): array
{
    $accessToken = get_access_token();

    if ($query !== []) {
        $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$accessToken,
            'Accept: application/json',
        ],
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Google API request failed: '.$error);
    }

    $decoded = json_decode($body, true);
    if (! is_array($decoded)) {
        throw new RuntimeException('Google API response was not JSON.');
    }

    if ($status >= 400) {
        throw new RuntimeException('Google API error: '.($decoded['error']['message'] ?? $body));
    }

    return $decoded;
}

function http_post_form(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Token request failed: '.$error);
    }

    $decoded = json_decode($body, true);
    if (! is_array($decoded)) {
        throw new RuntimeException('Token response was not JSON.');
    }

    if ($status >= 400) {
        throw new RuntimeException('Token request failed: '.($decoded['error_description'] ?? $decoded['error'] ?? $body));
    }

    if (isset($decoded['expires_in'])) {
        $decoded['expires_at'] = time() + (int) $decoded['expires_in'];
    }

    return $decoded;
}

function default_list_id(): string
{
    return (string) env('GOOGLE_TASKS_LIST_ID', '');
}

function fetch_tasklists(): array
{
    $payload = google_get(rtrim(env('GOOGLE_TASKS_BASE_URL', 'https://tasks.googleapis.com/tasks/v1') ?? 'https://tasks.googleapis.com/tasks/v1', '/').'/users/@me/lists', [
        'maxResults' => 1000,
    ]);

    $items = [];
    foreach ($payload['items'] ?? [] as $item) {
        $items[] = [
            'id' => $item['id'] ?? null,
            'title' => $item['title'] ?? null,
            'updated' => $item['updated'] ?? null,
            'selfLink' => $item['selfLink'] ?? null,
        ];
    }

    return $items;
}

function fetch_tasks(?string $tasklistId): array
{
    if ($tasklistId === null || $tasklistId === '') {
        $lists = fetch_tasklists();
        $tasklistId = (string) ($lists[0]['id'] ?? '');
    }

    if ($tasklistId === '') {
        throw new RuntimeException('No task list available.');
    }

    $payload = google_get(rtrim(env('GOOGLE_TASKS_BASE_URL', 'https://tasks.googleapis.com/tasks/v1') ?? 'https://tasks.googleapis.com/tasks/v1', '/').'/lists/'.rawurlencode($tasklistId).'/tasks', [
        'maxResults' => (int) env('GOOGLE_DEFAULT_PAGE_SIZE', '20'),
        'showCompleted' => filter_var(env('GOOGLE_TASKS_SHOW_COMPLETED', 'false'), FILTER_VALIDATE_BOOL),
        'showHidden' => filter_var(env('GOOGLE_TASKS_SHOW_HIDDEN', 'true'), FILTER_VALIDATE_BOOL),
        'showDeleted' => false,
        'showAssigned' => true,
    ]);

    $tasks = [];
    foreach ($payload['items'] ?? [] as $item) {
        $tasks[] = [
            'id' => $item['id'] ?? null,
            'title' => $item['title'] ?? 'Untitled task',
            'notes' => $item['notes'] ?? null,
            'status' => $item['status'] ?? null,
            'updated' => $item['updated'] ?? null,
            'due' => $item['due'] ?? null,
            'completed' => $item['completed'] ?? null,
            'hidden' => (bool) ($item['hidden'] ?? false),
            'deleted' => (bool) ($item['deleted'] ?? false),
            'parent' => $item['parent'] ?? null,
        ];
    }

    return [
        'generated_at' => gmdate(DATE_ATOM),
        'task_list' => [
            'id' => $tasklistId,
            'title' => $payload['title'] ?? $tasklistId,
            'updated' => $payload['updated'] ?? null,
        ],
        'tasks' => $tasks,
    ];
}

function render_homepage(array $state = []): void
{
    $token = $state['token'] ?? null;
    $connected = (bool) ($state['connected'] ?? false);
    $disconnected = (bool) ($state['disconnected'] ?? false);
    $feedUrl = (string) ($state['feed_url'] ?? '');
    $listId = default_list_id();
    $tokenMessage = $token ? 'Token store present' : 'No token stored yet';
    $authUrl = '/connect';

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>LaraPaper Google Tasks Connector</title>';
    echo '<style>
        body{font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:linear-gradient(135deg,#f3efe5,#e8f0ff);color:#132238;margin:0;padding:40px}
        .card{max-width:860px;margin:0 auto;background:rgba(255,255,255,.86);backdrop-filter:blur(10px);border:1px solid rgba(19,34,56,.1);border-radius:24px;padding:32px;box-shadow:0 20px 60px rgba(19,34,56,.12)}
        code,pre{background:#0f172a;color:#e2e8f0;border-radius:12px;padding:2px 8px}
        a.button{display:inline-block;background:#132238;color:white;padding:12px 16px;border-radius:12px;text-decoration:none;font-weight:600}
        .row{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
        .muted{color:#516072}
    </style></head><body><div class="card">';
    echo '<h1>LaraPaper Google Tasks Connector</h1>';
    echo '<p class="muted">Read-only Google Tasks feed with OAuth-backed refresh tokens.</p>';
    if ($connected) {
        echo '<p><strong>Connected.</strong> Refresh token stored locally.</p>';
    }
    if ($disconnected) {
        echo '<p><strong>Disconnected.</strong> Token store cleared.</p>';
    }
    echo '<p>'.$tokenMessage.'</p>';
    echo '<div class="row">';
    echo '<a class="button" href="'.htmlspecialchars($authUrl, ENT_QUOTES).'">Connect Google Tasks</a>';
    echo '<a class="button" href="/tasks">View Feed</a>';
    echo '<a class="button" href="/lists">Inspect Lists</a>';
    echo '</div>';
    echo '<h2>Feed URL</h2><p><code>'.htmlspecialchars($feedUrl, ENT_QUOTES).'</code></p>';
    echo '<h2>Config</h2><ul>';
    echo '<li>Task list: <code>'.htmlspecialchars($listId ?: '(default first list)', ENT_QUOTES).'</code></li>';
    echo '<li>Stored token: <code>'.htmlspecialchars($tokenMessage, ENT_QUOTES).'</code></li>';
    echo '</ul>';
    echo '<form method="post" action="/disconnect" onsubmit="return confirm(\'Clear stored token?\')">';
    echo '<button class="button" type="submit" style="border:none;cursor:pointer;margin-top:12px">Disconnect</button>';
    echo '</form>';
    echo '</div></body></html>';
}
