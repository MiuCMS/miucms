<?php
declare(strict_types=1);

namespace app\services;

use app\extend\plugin\PluginManager;
use think\facade\Cache;

/** 官网插件市场接口。 */
final class PluginMarket
{
    private function apiUrl(): string
    {
        return rtrim((string) config('official.service'), '/') . '/api/plugin-market';
    }
    private const TOKEN_KEY = 'miucms_plugin_market_token';
    private const TOKEN_TTL = 7200;
    private const MAX_PACKAGE_BYTES = 31457280;

    public function isLoggedIn(): bool
    {
        return is_string(Cache::get(self::TOKEN_KEY)) && Cache::get(self::TOKEN_KEY) !== '';
    }

    public function login(string $username, string $password): array
    {
        $result = $this->request('/auth/login', 'POST', ['username' => $username, 'password' => $password]);
        $token = $result['data']['access_token'] ?? '';
        if ((int) ($result['code'] ?? 1) !== 0 || !is_string($token) || $token === '') {
            return ['code' => 1, 'msg' => (string) ($result['message'] ?? '官网登录失败')];
        }
        Cache::set(self::TOKEN_KEY, $token, min(self::TOKEN_TTL, max(60, (int) ($result['data']['expires_in'] ?? self::TOKEN_TTL) - 60)));
        return ['code' => 0, 'msg' => '官网账号登录成功'];
    }

    public function logout(): void
    {
        Cache::delete(self::TOKEN_KEY);
    }

    public function plugins(int $page, int $perPage, string $keyword = '', string $price = 'all', string $source = 'all', int $categoryId = 0, string $purchaseStatus = 'all'): array
    {
        $query = http_build_query(['page' => $page, 'per_page' => $perPage, 'keyword' => $keyword, 'price' => $price, 'source' => $source, 'category_id' => $categoryId, 'purchase_status' => $purchaseStatus]);
        return $this->request('/plugins?' . $query);
    }

    public function download(string $name): array
    {
        if (!PluginManager::instance()->isValidName($name) || PluginManager::instance()->isPhpKeyword($name)) {
            return ['code' => 1, 'msg' => '插件标识不合法'];
        }
        if (is_dir(PluginManager::instance()->getPluginPath() . $name)) {
            return ['code' => 1, 'msg' => '本地已存在该插件'];
        }
        $token = (string) Cache::get(self::TOKEN_KEY);
        if ($token === '') return ['code' => 1, 'msg' => '官网登录已过期，请重新登录'];

        $context = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 30, 'follow_location' => 0, 'ignore_errors' => true,
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/zip, application/json\r\nContent-Length: 0\r\nUser-Agent: MiuCMS-PluginMarket/" . CMS_VERSION . "\r\n",
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @fopen($this->apiUrl() . '/plugins/' . rawurlencode($name) . '/download', 'rb', false, $context);
        if (!is_resource($stream)) return ['code' => 1, 'msg' => '无法连接官网插件市场'];
        $meta = stream_get_meta_data($stream);
        $status = 0;
        $headers = [];
        foreach (($meta['wrapper_data'] ?? []) as $line) {
            if (preg_match('#\AHTTP/\S+\s+(\d{3})#i', (string) $line, $m)) $status = (int) $m[1];
            if (str_contains((string) $line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        $body = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) { fclose($stream); return ['code' => 1, 'msg' => '读取插件包失败']; }
            $body .= $chunk;
            if (strlen($body) > self::MAX_PACKAGE_BYTES) { fclose($stream); return ['code' => 1, 'msg' => '插件包超过 30 MB 限制']; }
        }
        fclose($stream);
        if ($status !== 200) {
            $error = json_decode($body, true);
            if (($error['code'] ?? null) === 1004) $this->logout();
            return ['code' => 1, 'msg' => (string) ($error['message'] ?? '官网拒绝下载插件')];
        }
        $hash = strtolower((string) ($headers['x-plugin-sha256'] ?? ''));
        if (($headers['x-plugin-id'] ?? '') !== $name || !preg_match('/\A[a-f0-9]{64}\z/', $hash)
            || !hash_equals($hash, hash('sha256', $body)) || strlen($body) < 4 || substr($body, 0, 2) !== 'PK') {
            return ['code' => 1, 'msg' => '插件包信息或 SHA-256 校验失败'];
        }
        $dir = runtime_path() . 'plugin_market' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return ['code' => 1, 'msg' => '无法创建临时目录'];
        $zipPath = $dir . bin2hex(random_bytes(16)) . '.zip';
        if (file_put_contents($zipPath, $body, LOCK_EX) !== strlen($body)) return ['code' => 1, 'msg' => '无法保存插件包'];
        try {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true || $zip->numFiles < 1 || $zip->numFiles > 5000) {
                if (isset($zip)) $zip->close();
                return ['code' => 1, 'msg' => '插件包 ZIP 结构无效'];
            }
            $hasEntry = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) ($zip->statIndex($i)['name'] ?? '');
                $normalized = str_replace('\\', '/', $entry);
                $segments = explode('/', rtrim($normalized, '/'));
                if ($entry === '' || str_contains($entry, "\0") || str_starts_with($normalized, '/')
                    || preg_match('/\A[A-Za-z]:/', $normalized) || str_contains($entry, '\\')
                    || in_array('..', $segments, true) || in_array('.', $segments, true)
                    || !str_starts_with($normalized, $name . '/')) {
                    $zip->close();
                    return ['code' => 1, 'msg' => '插件包包含不安全路径或标识不匹配'];
                }
                if ($normalized === $name . '/Plugin.php') $hasEntry = true;
            }
            $zip->close();
            if (!$hasEntry) return ['code' => 1, 'msg' => '插件包缺少 Plugin.php'];
            $result = PluginManager::instance()->installFromZip($zipPath, false);
            return ['code' => (int) ($result['code'] ?? 1), 'msg' => (string) ($result['msg'] ?? '插件下载失败')];
        } finally {
            @unlink($zipPath);
        }
    }

    private function request(string $path, string $method = 'GET', ?array $payload = null): array
    {
        $headers = "Accept: application/json\r\nUser-Agent: MiuCMS-PluginMarket/" . CMS_VERSION . "\r\n";
        $token = (string) Cache::get(self::TOKEN_KEY);
        if ($path !== '/auth/login') {
            if ($token === '') return ['code' => 1, 'message' => '官网登录已过期，请重新登录'];
            $headers .= "Authorization: Bearer {$token}\r\n";
        }
        if ($payload !== null) $headers .= "Content-Type: application/json\r\n";
        $context = stream_context_create(['http' => [
            'method' => $method, 'timeout' => 15, 'follow_location' => 0, 'ignore_errors' => true,
            'header' => $headers, 'content' => $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE),
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($this->apiUrl() . $path, false, $context);
        if (!is_string($body)) return ['code' => 1, 'message' => '无法连接官网插件市场'];
        $result = json_decode($body, true);
        if (!is_array($result)) return ['code' => 1, 'message' => '官网插件市场返回格式无效'];
        if ((int) ($result['code'] ?? 0) === 1004) $this->logout();
        return $result;
    }
}
