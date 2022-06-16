<?php

namespace Wanphp\Libray\User;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Predis\Client as Redis;

class User
{
  private array $headers;
  private Client $client;

  /**
   * @param string $appId
   * @param string $appSecret
   * @param string $apiUri
   * @param array $redisConfig
   * @throws Exception
   */
  public function __construct(string $appId, string $appSecret, string $apiUri, array $redisConfig)
  {

    $redis = new Redis($redisConfig['parameters'], $redisConfig['options']);
    $this->client = new Client(['base_uri' => $apiUri]);

    //数据库取缓存
    $access_token = $redis->get('wanphp_access_token');
    if (!$access_token) {
      $data = [
        'grant_type' => 'client_credentials',
        'client_id' => $appId,
        'client_secret' => $appSecret,
        'scope' => ''
      ];
      $result = $this->request($this->client, 'POST', '/auth/accessToken', ['body' => json_encode($data, JSON_UNESCAPED_UNICODE), 'headers' => ['Accept' => 'application/json']]);
      if (isset($result['access_token'])) {
        $redis->setex('wanphp_access_token', $result['expires_in'], $result['access_token']);
        $access_token = $result['access_token'];
      }
    }

    $this->headers = [
      'Accept' => 'application/json',
      'Authorization' => 'Bearer ' . $access_token
    ];
  }

  /**
   * 添加用户
   * @param $data
   * @return array
   * @throws Exception
   */
  public function addUser($data): array
  {
    return $this->request($this->client, 'POST', '/user', [
      'json' => $data,
      'headers' => $this->headers
    ]);
  }

  /**
   * 更新用户信息
   * @param int $uid
   * @param array $data
   * @return array
   * @throws Exception
   */
  public function updateUser(int $uid, array $data): array
  {
    return $this->request($this->client, 'PUT', '/user/' . $uid, [
      'json' => $data,
      'headers' => $this->headers
    ]);
  }

  /**
   * 获取用户信息
   * @param array $uid
   * @return array
   * @throws Exception
   */
  public function getUsers(array $uid): array
  {
    return $this->request($this->client, 'POST', '/user/get', [
      'json' => ['id' => $uid],
      'headers' => $this->headers
    ]);
  }

  /**
   * 搜索用户
   * @param string $keyword
   * @param int $page
   * @return array
   * @throws Exception
   */
  public function searchUsers(string $keyword, int $page = 0): array
  {
    return $this->request($this->client, 'GET', '/user/search', [
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