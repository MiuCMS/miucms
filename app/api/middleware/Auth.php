<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

namespace app\api\middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use think\facade\Db;
use think\facade\Request;

class Auth
{
    public function handle($request, \Closure $next)
    {
        $token = Request::header('Token');
        if ($token) {
            if (count(explode('.', $token)) != 3) {
                return json(['code' => 404, 'msg' => '非法请求']);
            }
			$config = get_system_config();
			if (!is_string($config['secrect'] ?? null) || $config['secrect'] === '') {
				return json(['code' => 503, 'msg' => 'API 签名密钥未配置']);
			}
            try {
				JWT::$leeway = 60;
				$decoded = JWT::decode($token, new Key($config['secrect'], 'HS256'));
			} catch (\Firebase\JWT\SignatureInvalidException $e) {
				return json(['code' => 403, 'msg' => '签名错误']);
			} catch (\Firebase\JWT\BeforeValidException $e) {
				return json(['code' => 401, 'msg' => 'token失效']);
			} catch (\Firebase\JWT\ExpiredException $e) {
				return json(['code' => 401, 'msg' => 'token已过期']);
			} catch (\Throwable $e) {
				return json(['code' => 404, 'msg' => '非法请求']);
			}

			$decodedArray = json_decode(json_encode($decoded), true);
			$jwtData = $decodedArray['data'] ?? null;
			$userId = is_array($jwtData) ? filter_var($jwtData['userid'] ?? null, FILTER_VALIDATE_INT) : false;
			if (!$userId || $userId < 1) {
				return json(['code' => 401, 'msg' => 'token无效']);
			}
			$user = Db::name('User')->where('id', $userId)->where('status', 1)->field('id,password')->find();
            if (!$user || !is_string($jwtData['credential'] ?? null) || !hash_equals(hash_hmac('sha256', (string) $user['password'], $config['secrect']), $jwtData['credential'])) {
				return json(['code' => 401, 'msg' => '账号不可用或token已失效']);
			}
			define('JWT_UID', (int) $userId);
			return $next($request);
        } else {
            return json(['code' => 404, 'msg' => 'token不能为空']);
        }
    }
}
