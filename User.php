<?php

namespace Wanphp\Libray\User;

use Exception;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Wanphp\Libray\Slim\CacheInterface;
use Wanphp\Libray\Slim\HttpTrait;
use Wanphp\Libray\Slim\Setting;
use Wanphp\Libray\Slim\WpUserInterface;

class User implements WpUserInterface
{
  use HttpTrait;

  protected array $headers;
  private string $appId;
  private string $appSecret;
  private string $oauthServer;
  private string $apiUri;
  protected Client $client;
  private CacheInterface $cache;

  /**
   * @param Setting $setting
   * @param CacheInterface $cache
   * @throws Exception
   */
  public function __construct(Setting $setting, CacheInterface $cache)
  {
    $userServerConfig = $setting->get('userServer');
    $this->appId = $userServerConfig['appId'];
    $this->appSecret = $userServerConfig['appSecret'];
    $this->oauthServer = $userServerConfig['oauthServer'];
    $this->apiUri = $userServerConfig['apiUri'];

    $this->cache = $cache;
    $this->client = new Client(['base_uri' => $this->apiUri]);

    //数据库取缓存
    $access_token = $this->cache->get($this->appId . '_wanphp_user_client_access_token');
    if (!$access_token) {
      $data = [
        'grant_type' => 'client_credentials',
        'client_id' => $this->appId,
        'client_secret' => $this->appSecret,
        'scope' => ''
      ];
      $result = $this->request($this->client, 'POST', $this->oauthServer . 'auth/accessToken', ['json' => $data]);
      if (isset($result['access_token'])) {
        $this->cache->set($this->appId . '_wanphp_user_client_access_token', $result['access_token'], $result['expires_in']);
        $access_token = $result['access_token'];
      }
    }
    $this->headers = [
      'Authorization' => 'Bearer ' . $access_token
    ];
  }

  /**
   * 用户授权操作，第一步：资源服务器，前往认证服务器获取code
   * @throws Exception
   */
  public function oauthRedirect(Request $request, Response $response): Response
  {
    $queryParams = $request->getQueryParams();
    $redirectUri = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . $request->getUri()->getPath();
    $scope = $queryParams['scope'] ?? '';
    $state = $queryParams['state'] ?? '';
    $url = $this->oauthServer . 'auth/authorize?client_id=' . $this->appId . '&redirect_uri=' . urlencode($redirectUri) . '&response_type=code&scope=' . $scope . '&state=' . $state;
    return $response->withHeader('Location', $url)->withStatus(301);
  }

  /**
   * 用户授权操作，第二步：通过code换取网页授权access_token
   * @param string $code
   * @param string $redirect_uri
   * @return string
   * @throws Exception
   */
  public function getOauthAccessToken(string $code, string $redirect_uri): string
  {
    //数据库取缓存
    $access_token = $this->cache->get($this->appId . '_wanphp_user_access_token');
    if (!$access_token) {
      $refresh_token = $this->cache->get($this->appId . '_wanphp_user_refresh_token');
      if ($refresh_token) {
        $data = [
          'grant_type' => 'refresh_token',
          'client_id' => $this->appId,
          'client_secret' => $this->appSecret,
          'refresh_token' => $refresh_token
        ];
        $result = $this->request(new Client(), 'POST', $this->oauthServer . 'auth/refreshAccessToken', ['json' => $data]);
      } else {
        $data = [
          'grant_type' => 'authorization_code',
          'client_id' => $this->appId,
          'client_secret' => $this->appSecret,
          'redirect_uri' => $redirect_uri,
          'code' => $code
        ];
        $result = $this->request(new Client(), 'POST', $this->oauthServer . 'auth/accessToken', ['json' => $data]);
      }
      if (isset($result['access_token'])) {
        $this->cache->set($this->appId . '_wanphp_user_access_token', $result['access_token'], $result['expires_in']);
        $this->cache->set($this->appId . '_wanphp_user_refresh_token', $result['refresh_token'], $result['expires_in']);
        $access_token = $result['access_token'];
      }
    }
    return $access_token;
  }

  /**
   * 用户授权操作，第三步：获取用户信息
   * @param string $access_token
   * @return array
   * @throws Exception
   */
  public function getOauthUserinfo(string $access_token): array
  {
    return $this->request($this->client, 'GET', 'user', [
      'headers' => [
        'Authorization' => 'Bearer ' . $access_token
      ]
    ]);
  }

  /**
   * 用户授权操作，更新用户信息
   * @param string $access_token
   * @param array $data
   * @return array
   * @throws Exception
   */
  public function updateOauthUser(string $access_token, array $data): array
  {
    return $this->request($this->client, 'PATCH', 'user', [
      'json' => $data,
      'headers' => [
        'Authorization' => 'Bearer ' . $access_token
      ]
    ]);
  }

  /**
   * 客户端，添加用户
   * @param $data
   * @return array
   * @throws Exception
   */
  public function addUser($data): array
  {
    return $this->request($this->client, 'POST', 'user', [
      'json' => $data,
      'headers' => $this->headers
    ]);
  }

  /**
   * 客户端，更新用户信息
   * @param int $uid
   * @param array $data
   * @return array
   * @throws Exception
   */
  public function updateUser(int $uid, array $data): array
  {
    return $this->request($this->client, 'PUT', 'user/' . $uid, [
      'json' => $data,
      'headers' => $this->headers
    ]);
  }

  /**
   * 客户端，获取用户信息
   * @param array $uidArr
   * @return array
   * @throws Exception
   */
  public function getUsers(array $uidArr): array
  {
    return $this->request($this->client, 'POST', 'user/get', [
      'json' => ['uid' => $uidArr],
      'headers' => $this->headers
    ]);
  }

  /**
   * 客户端，获取用户信息
   * @param int $uid
   * @return array
   * @throws Exception
   */
  public function getUser(int $uid): array
  {
    return $this->request($this->client, 'GET', 'user/get/' . $uid, [
      'headers' => $this->headers
    ]);
  }

  /**
   * 客户端，搜索用户
   * @param string $keyword
   * @param int $page
   * @return array
   * @throws Exception
   */
  public function searchUsers(string $keyword, int $page = 0): array
  {
    return $this->request($this->client, 'GET', 'user/search', [
      'query' => ['q' => $keyword, 'page' => $page],
      'headers' => $this->headers
    ]);
  }

  /**
   * @param array $uidArr
   * @param array $msgData
   * @return array
   * @throws Exception
   */
  public function sendMessage(array $uidArr, array $msgData): array
  {
    return $this->request($this->client, 'POST', 'user/sendMsg', [
      'json' => ['users' => $uidArr, 'data' => $msgData],
      'headers' => $this->headers
    ]);
  }

  /**
   * @param string $uid 用户ID
   * @param int $tagId 公众号标签ID
   * @return array
   * @throws Exception
   */
  public function membersTagging(string $uid, int $tagId): array
  {
    return $this->request($this->client, 'PATCH', 'user/tag', [
      'json' => ['uid' => $uid, 'tagId' => $tagId],
      'headers' => $this->headers
    ]);
  }

  /**
   * @param string $uid 用户ID
   * @param int $tagId 公众号标签ID
   * @return array
   * @throws Exception
   */
  public function membersUnTagging(string $uid, int $tagId): array
  {
    return $this->request($this->client, 'DELETE', 'user/tag', [
      'json' => ['uid' => $uid, 'tagId' => $tagId],
      'headers' => $this->headers
    ]);
  }

  public function userLogin(string $account, string $password): int|string
  {
    return '客户端无权做用户登录认证';
  }
}
