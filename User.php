<?php

namespace Wanphp\Libray\User;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Predis\Client as Redis;
use Predis\ClientInterface;

class User
{
  private array $headers;
  private string $appId;
  private string $appSecret;
  private string $oauthServer;
  private string $apiUri;
  private Client $client;
  private ClientInterface $redis;

  /**
   * @param array $userServerConfig
   * @param array $redisConfig
   * @throws Exception
   */
  public function __construct(array $userServerConfig, array $redisConfig)
  {
    $this->appId = $userServerConfig['appId'];
    $this->appSecret = $userServerConfig['appSecret'];
    $this->oauthServer = $userServerConfig['oauthServer'];
    $this->apiUri = $userServerConfig['apiUri'];

    $this->redis = new Redis($redisConfig['parameters'], $redisConfig['options']);
    $this->client = new Client(['base_uri' => $this->apiUri]);

    //数据库取缓存
    $access_token = $this->redis->get('wanphp_client_access_token');
    if (!$access_token) {
      $data = [
        'grant_type' => 'client_credentials',
        'client_id' => $this->appId,
        'client_secret' => $this->appSecret,
        'scope' => ''
      ];
      $result = $this->request(new Client(), 'POST', $this->oauthServer . 'auth/accessToken', ['json' => $data]);
      if (isset($result['access_token'])) {
        $this->redis->setex('wanphp_client_access_token', $result['expires_in'], $result['access_token']);
        $access_token = $result['access_token'];
      }
    }
    $this->headers = [
      'Authorization' => 'Bearer ' . $access_token
    ];
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
    $access_token = $this->redis->get('wanphp_user_access_token');
    if (!$access_token) {
      $data = [
        'grant_type' => 'authorization_code',
        'client_id' => $this->appId,
        'client_secret' => $this->appSecret,
        'redirect_uri' => $redirect_uri,
        'code' => $code
      ];
      $result = $this->request(new Client(), 'POST', $this->oauthServer . 'auth/accessToken', ['json' => $data]);
      if (isset($result['access_token'])) {
        $this->redis->setex('wanphp_user_access_token', $result['expires_in'], $result['access_token']);
        $this->redis->setex('wanphp_user_refresh_token', $result['expires_in'], $result['refresh_token']);
        $access_token = $result['access_token'];
      }
    }
    return $access_token;
  }

  /**
   * 用户授权操作，第三步：获取用户信息
   * @param $access_token
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
   * @param array $uid
   * @return array
   * @throws Exception
   */
  public function getUsers(array $uid): array
  {
    return $this->request($this->client, 'POST', 'user/get', [
      'json' => ['uid' => $uid],
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
   * @param Client $client
   * @param string $method
   * @param string $uri
   * @param array $options
   * @return array
   * @throws Exception
   */
  private function request(Client $client, string $method, string $uri, array $options): array
  {
    try {
      $resp = $client->request($method, $uri, $options);
      $body = $resp->getBody()->getContents();
      if ($resp->getStatusCode() == 200) {
        $content_type = $resp->getHeaderLine('Content-Type');
        if (str_contains($content_type, 'application/json') || str_contains($content_type, 'text/plain')) {
          $json = json_decode($body, true);
          if (json_last_error() === JSON_ERROR_NONE) {
            if (isset($json['errMsg'])) {
              throw new Exception($json['errMsg'], 400);
            } else {
              return $json;
            }
          }
        }
        return ['content_type' => $content_type, 'content_disposition' => $resp->getHeaderLine('Content-disposition'), 'body' => $body];
      } else {
        throw new Exception($resp->getReasonPhrase(), $resp->getStatusCode());
      }
    } catch (RequestException $e) {
      $message = $e->getMessage();
      if ($e->hasResponse()) {
        $message .= "\n" . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase();
        $message .= "\n" . $e->getResponse()->getBody();
      }
      throw new Exception($message);
    } catch (GuzzleException $e) {
      throw new Exception($e->getMessage(), $e->getCode());
    }
  }
}