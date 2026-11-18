<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */
declare (strict_types = 1);
namespace app\api\controller;

use app\api\BaseController;
use app\api\middleware\Auth;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Request;

class Index extends BaseController
{
    /**
     * 控制器中间件 [登录、注册 不需要鉴权]
     * @var array
     */
	protected $middleware = [
    	Auth::class => ['except' 	=> ['index','reg','login'] ]
    ];
	
    /**
     * @param $user_id
     * @return string
     */
    protected function getToken($user_id){
        $time = time(); //当前时间
		$conf = $this->jwt_conf;
		if (!is_string($conf['secrect'] ?? null) || $conf['secrect'] === '') {
			throw new \RuntimeException('API 签名密钥未配置');
		}
        $token = [
            'iss' => $conf['iss'], //签发者 可选
            'aud' => $conf['aud'], //接收该JWT的一方，可选
            'iat' => $time, //签发时间
            'nbf' => $time-1 , //(Not Before)：某个时间点后才能访问，比如设置time+30，表示当前时间30秒后才能使用
            'exp' => $time+$conf['exptime'], //过期时间,这里设置2个小时
            'data' => [
                //自定义信息，不要定义敏感信息
                'userid' =>$user_id,
                'credential' => hash_hmac('sha256', (string) Db::name('User')->where('id', (int) $user_id)->value('password'), $conf['secrect']),
            ]
        ];
        return JWT::encode($token, $conf['secrect'], 'HS256'); //输出Token  默认'HS256'
    }

    /**
     * @param $token
     */
    protected static function checkToken($token){
        try {
            $secret = get_system_config('secrect');
            if (!is_string($secret) || $secret === '') {
                return json(['code' => 503, 'msg' => 'API 签名密钥未配置']);
            }
            JWT::$leeway = 60;//当前时间减去60，把时间留点余地
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            return (array)$decoded;
        } catch(\Firebase\JWT\SignatureInvalidException $e) {  //签名不正确
            return json(['code'=>403,'msg'=>'签名错误']);
        }catch(\Firebase\JWT\BeforeValidException $e) {  // 签名在某个时间点之后才能用
            return json(['code'=>401,'msg'=>'token失效']);
        }catch(\Firebase\JWT\ExpiredException $e) {  // token过期
            return json(['code'=>401,'msg'=>'token已过期']);
        } catch(\Throwable $e) {
            return json(['code'=>404,'msg'=>'非法请求']);
        }

    }	
	
    /**
     * @api {post} /index/index API页面
     * @apiDescription  返回首页信息
     */
    public function index()
    {
		$list = Db::name('Article')->alias('a')
			->join('Document d', 'd.id = a.doc_id')
			->where('a.delete_time', 0)
			->where('d.is_del', 0)
			->where('d.status', 1)->where('d.arcrank', '>=', 0)
			->field('a.*')
			->select()
			->toArray();
		$config = get_system_config();
		$seo = array_intersect_key($config, array_flip([
			'site_name', 'site_domain', 'site_logo', 'site_ico', 'site_icp', 'site_beian',
			'site_title', 'site_keywords', 'site_desc',
		]));
		add_user_log('api', '首页');
        $this->apiSuccess('请求成功',['list' => $list,'seo' => $seo]);
    }

    /**
     * @api {post} /index/login 会员登录
     * @apiDescription 系统登录接口，返回 token 用于操作需验证身份的接口

     * @apiParam (请求参数：) {string}             username 登录用户名
     * @apiParam (请求参数：) {string}             password 登录密码

     * @apiParam (响应字段：) {string}             token    Token

     * @apiSuccessExample {json} 成功示例
     * {"code":0,"msg":"登录成功","time":1627374739,"data":{"token":"eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJhcGkuZ291Z3VjbXMuY29tIiwiYXVkIjoiZ291Z3VjbXMiLCJpYXQiOjE2MjczNzQ3MzksImV4cCI6MTYyNzM3ODMzOSwidWlkIjoxfQ.gjYMtCIwKKY7AalFTlwB2ZVWULxiQpsGvrz5I5t2qTs"}}
     * @apiErrorExample {json} 失败示例
     * {"code":1,"msg":"帐号或密码错误","time":1627374820,"data":[]}
     */
    public function login()
    {
		if (!request()->isPost()) {
			$this->apiError('请使用 POST 请求');
		}
		$param = get_params();
		if (!is_scalar($param['username'] ?? null) || !is_scalar($param['password'] ?? null) || (string) $param['password'] === '') {
			$this->apiError('参数错误');
		}
		$loginRateKey = 'apiLoginFailures_' . hash('sha256', (string) request()->ip());
		if ((int) Cache::get($loginRateKey, 0) >= 10) {
			$this->apiError('登录失败次数过多，请 5 分钟后再试');
		}
        // 校验用户名密码
		$user = Db::name('User')->where(['username' => $param['username']])->find();
        if (empty($user)) {
			Cache::set($loginRateKey, (int) Cache::get($loginRateKey, 0) + 1, 300);
            $this->apiError('帐号或密码错误');
        }
		if (!verify_user_password($param['password'], $user['password'], $user['salt'])) {
			Cache::set($loginRateKey, (int) Cache::get($loginRateKey, 0) + 1, 300);
            $this->apiError('帐号或密码错误');
        }
        if ((int) ($user['status'] ?? 0) !== 1) {
            $this->apiError('该用户禁止登录,请于平台联系');
        }
		if (!is_string($this->jwt_conf['secrect'] ?? null) || $this->jwt_conf['secrect'] === '') {
			$this->apiError('API 签名密钥未配置，请先在网站配置中设置');
		}
		Cache::delete($loginRateKey);
        if (user_password_needs_rehash($user['password'])) {
            $user['password'] = hash_user_password($param['password']);
            Db::name('User')->where('id', (int) $user['id'])->update(['password' => $user['password'], 'salt' => '']);
        }
        $data = [
            'last_login_time' => time(),
            'last_login_ip' => request()->ip(),
            'login_num' => $user['login_num'] + 1,
        ];
        $res = Db::name('user')->where(['id' => $user['id']])->update($data);
        if ($res) {
			$token = $this->getToken($user['id']);
			add_user_log('api', '登录');
            $this->apiSuccess('登录成功', ['token' => $token]);
        }
    }

    /**
     * @api {post} /index/reg 会员注册
     * @apiDescription  系统注册接口，返回是否成功的提示，需再次登录

     * @apiParam (请求参数：) {string}             username 用户名
     * @apiParam (请求参数：) {string}             password 密码

     * @apiSuccessExample {json} 成功示例
     * {"code":0,"msg":"注册成功","time":1627375117,"data":[]}
     * @apiErrorExample {json} 失败示例
     * {"code":1,"msg":"该账户已经存在","time":1627374899,"data":[]}
     */
    public function reg()
    {
		if (!request()->isPost()) {
			$this->apiError('请使用 POST 请求');
		}
		$param = get_params();
		if (!is_scalar($param['username'] ?? null) || !is_scalar($param['pwd'] ?? null)) {
			$this->apiError('参数错误');
		}
		$registerRateKey = 'apiRegister_' . hash('sha256', (string) request()->ip());
		$registerAttempts = (int) Cache::get($registerRateKey, 0);
		if ($registerAttempts >= 10) {
			$this->apiError('注册请求过于频繁，请稍后再试');
		}
		Cache::set($registerRateKey, $registerAttempts + 1, 600);
		$username = trim((string) $param['username']);
		$password = (string) $param['pwd'];
		$emailParam = $param['email'] ?? '';
		if (!is_scalar($emailParam)) {
			$this->apiError('邮箱格式不正确');
		}
		$email = strtolower(trim((string) $emailParam));
		if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{4,19}$/', $username) || mb_strlen($password, 'UTF-8') < 6 || mb_strlen($password, 'UTF-8') > 128 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
			$this->apiError('账号格式或密码长度不正确');
		}
		if ((int) get_system_config('register_email_verify') === 1) {
			$this->apiError('当前已开启邮箱验证，请通过网站注册页面完成注册');
		}
		$user = Db::name('user')->where(['username' => $username])->find();
        if (!empty($user)) {
			$this->apiError('该账户已经存在');
        }
		if ($email !== '' && Db::name('user')->where('email', $email)->where('status', '<>', -1)->count() > 0) {
			$this->apiError('该邮箱已被使用');
		}
		$userData = [
			'username' => $username,
			'email' => $email,
			'password' => hash_user_password($password),
			'salt' => '',
			'register_time' => time(),
			'headimgurl' => '/static/admin/images/icon.png',
			'register_ip' => request()->ip(),
		];
		try {
			$uid = Db::name('User')->strict(false)->field(true)->insertGetId($userData);
		} catch (\Throwable $e) {
			if (is_duplicate_key_error($e)) {
				$this->apiError('该账户已经存在');
			}
			throw $e;
		}
		if($uid){
			add_user_log('api', '注册');
			$this->apiSuccess('注册成功,请登录');
		}else{
			$this->apiError('注册失败');
		}
    }

    /**
     * @api {post} /index/demo 测试页面
     * @apiDescription  返回文章列表信息

     * @apiParam (请求参数：) {string}  token Token

     * @apiSuccessExample {json} 响应数据样例
     * {"code":0,"msg":"请求成功","data":{"user":{"id":13,"username":"member","nickname":"会员","email":"member@example.com"}}}
     */
    public function demo()
    {
		$uid = JWT_UID;
		$userInfo = Db::name('User')->field('id,username,nickname,email,headimgurl,sex,desc,birthday,country,province,city,register_time')->where(['id' => $uid])->find();
        $this->apiSuccess('请求成功', ['user' => $userInfo]);
    }
}
