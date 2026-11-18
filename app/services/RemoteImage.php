<?php
declare(strict_types=1);
namespace app\services;

/** 限定公网图片下载：固定解析后的地址，逐跳校验并限制响应大小。 */
final class RemoteImage
{
    private const MAX_BYTES = 8388608;

    public static function get(string $url, float $seconds, string $agent, ?string $caFile = null): string
    {
        if ($caFile !== null && (!is_file($caFile) || !is_readable($caFile))) throw new \RuntimeException('图片下载使用的CA证书文件不存在或不可读');
        $deadline = microtime(true) + $seconds;
        for ($redirect = 0; $redirect <= 5; $redirect++) {
            $parts = parse_url($url);
            if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) throw new \RuntimeException('图片地址无效');
            $host = trim($parts['host'] ?? '', '[]');
            $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
                array_column(dns_get_record($host, DNS_A) ?: [], 'ip'),
                array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
            );
            if (!$ips) throw new \RuntimeException('无法解析图片地址');
            foreach ($ips as $ip) {
                $v4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? array_map('intval', explode('.', $ip)) : [];
                if (($v4 && ($v4[0] >= 224 || ($v4[0] === 100 && $v4[1] >= 64 && $v4[1] <= 127))) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                    || (str_contains($ip, ':') && (stripos($ip, '::ffff:') === 0 || !str_starts_with(strtolower($ip), '2') && !str_starts_with(strtolower($ip), '3')))) {
                    throw new \RuntimeException('不允许下载非公网地址');
                }
            }
            $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) throw new \RuntimeException('图片下载超时');
            $body = ''; $location = ''; $status = 0;
            if (function_exists('curl_init')) {
                $curl = curl_init($url);
                $ip = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
                curl_setopt_array($curl, [
                    CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip],
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_TIMEOUT_MS => max(1, (int) ($remaining * 1000)),
                    CURLOPT_USERAGENT => $agent,
                    CURLOPT_REFERER => $parts['scheme'] . '://' . $parts['host'] . '/',
                    CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$location) {
                        if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
                        return strlen($line);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$body) {
                        if (strlen($body) + strlen($chunk) > self::MAX_BYTES) return 0;
                        $body .= $chunk;
                        return strlen($chunk);
                    },
                ]);
                if ($caFile !== null) curl_setopt($curl, CURLOPT_CAINFO, $caFile);
                $success = curl_exec($curl);
                $errno = curl_errno($curl);
                $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                unset($curl);
                if ($success === false) throw new \RuntimeException('图片下载失败或超过大小限制（cURL ' . $errno . '）', $errno);
            } else {
                $ip = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
                $target = $parts['scheme'] . '://' . $ip . ':' . $port . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
                $context = stream_context_create([
                    'http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => $remaining, 'header' => 'Host: ' . $parts['host'] . ':' . $port, 'user_agent' => $agent],
                    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true],
                ]);
                if ($caFile !== null) stream_context_set_option($context, 'ssl', 'cafile', $caFile);
                $stream = fopen($target, 'rb', false, $context);
                if (!$stream) throw new \RuntimeException('图片下载失败');
                try {
                    foreach (stream_get_meta_data($stream)['wrapper_data'] ?? [] as $header) {
                        if (preg_match('~^HTTP/\S+\s+(\d+)~', $header, $match)) $status = (int) $match[1];
                        if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
                    }
                    while (!feof($stream)) {
                        if (microtime(true) > $deadline) throw new \RuntimeException('图片下载超时');
                        $chunk = fread($stream, 8192);
                        if ($chunk === false || stream_get_meta_data($stream)['timed_out']) throw new \RuntimeException('图片下载失败');
                        $body .= $chunk;
                        if (strlen($body) > self::MAX_BYTES) throw new \RuntimeException('图片超过大小限制');
                    }
                } finally { fclose($stream); }
            }
            if ($status >= 200 && $status < 300) return $body;
            if ($status < 300 || $status >= 400 || $location === '') throw new \RuntimeException('图片响应无效');
            $url = (string) \GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($url), new \GuzzleHttp\Psr7\Uri($location));
        }
        throw new \RuntimeException('图片重定向次数过多');
    }
}
