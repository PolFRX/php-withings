<?php

namespace Paxx\Withings;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Paxx\Withings\Exception\ApiException;
use Paxx\Withings\Exception\WbsException;

class Api
{
    const ENDPOINT = 'http://wbsapi.withings.net/';

    private $identifier;
    private $secret;
    private $access_token;
    private $token_secret;
    private $user_id;

    private $client;

    private $required_params = array(
        'identifier',
        'secret',
        'access_token',
        'token_secret',
        'user_id'
    );

    private $errors = array(
        0    => 'Operation was successful',
        247  => 'The user_id provided is absent, or incorrect',
        250  => 'The provided user_id and/or Oauth credentials do not match',
        286  => 'No such subscription was found',
        293  => 'The callback URL is either absent or incorrect (Note: Withings only sends notifications to valid post-requests)',
        294  => 'No such subscription could be deleted',
        304  => 'The comment is either absent or incorrect',
        305  => 'Too many notifications are already set',
        342  => 'The signature (using Oauth) is invalid',
        343  => 'Wrong Notification Callback Url doesn\'t exist',
        601  => 'Too Many Requests',
        2554 => 'Unspecified unknown error occurred',
        2555 => 'An unknown error occurred'
    );

    public function __construct(array $params = array())
    {
        $this->hydrateParams($params);

        $config = array(
            'consumer_key'    => $this->identifier,
            'consumer_secret' => $this->secret,
            'token'           => $this->access_token,
            'token_secret'    => $this->token_secret,
            'request_method'  => 'query'
        );

        // Create a stack so we can add the oauth-subscriber
        $stack = HandlerStack::create();

        $stack->push(new Oauth1($config));

        $this->client = new Client([
            'base_uri' => static::ENDPOINT,
            'handler'  => $stack,
            'auth'     => 'oauth'
        ]);
    }

    /**
     * Validate that the required parameters were passed into object constructor
     *
     * @param array $params
     * @throws ApiException
     */
    private function validateParams(array $params)
    {
        foreach ($this->required_params as $param) {
            if (! isset($params{$param})) {
                throw new ApiException('Missing parameters');
            }
        }
    }

    /**
     * Hydrate object from passed parameters
     *
     * @param array $params
     * @throws ApiException
     */
    private function hydrateParams(array $params)
    {
        $this->validateParams($params);

        foreach ($this->required_params as $param) {
            $this->{$param} = $params[$param];
        }
    }

    /**
     * Make a request to the API
     *
     * @param string $path   Path to the service
     * @param string $action Action query string
     * @param array $params  Parameters
     * @return bool
     * @throws WbsException
     */
    private function request($path = '', $action = '', $params = array())
    {
        $params['userid'] = $this->user_id;

        if (!empty($action)) {
            $params['action'] = $action;
        }

        // Build a request
        $request = $this->client->get($path, array('query' => $params));

        // Decode the response
        $response = json_decode($request->getBody()->getContents(), true);

        if ($response['status'] !== 0) {
            if (isset($this->errors[$response['status']])) {
                throw new WbsException($this->errors[$response['status']], $response['status']);
            } else {
                throw new WbsException($response['error']);
            }
        }

        // Check
        if (isset($response['body'])) {
            return $response['body'];
        }

        // We'll return true if nothing else has happened...
        return true;
    }

    /**
     * @return Entity\User
     * @throws WbsException
     */
    public function getUser()
    {
        $user = $this->request('user', 'getbyuserid');

        // Pluck single record
        $user = end($user['users']);

        return new Entity\User($this, $user);
    }

    /**
     * Get user's activity
     *
     * Omit both parameters to get all
     *
     * @param string $start
     * @param string $end
     * @return Collection\ActivityCollection
     * @throws WbsException
     */
    public function getActivity($start='', $end='')
    {
        $params = array();

        // Check if we have a single day
        if(!empty($start) && empty($end)) {
            $params['date'] = $start;
        // Or if we have a range
        } elseif(!empty($start) && !empty($end)) {
            $params['startdateymd'] = $start;
            $params['enddateymd'] = $end;
        }

        $activity = $this->request('v2/measure', 'getactivity', $params);

        return new Collection\ActivityCollection($activity);
    }

    /**
     * Get user's measurements
     *
     * @param array $params
     * @return Collection\MeasureCollection
     * @throws WbsException       If an error is returned from the API
     */
    public function getMeasures(array $params = array())
    {
        $measure = $this->request('measure', 'getmeas', $params);
        return new Collection\MeasureCollection($measure);
    }

    /**
     * Note: From Withings API FAQ:
     * Make sure you specify the right Appli parameters when creating your subscriptions
     * We test your callback URL when you subscribe for notifications. It must be reachable for POST requests, otherwise the subscription will fail.
     *
     * @param string $callback
     * @param string $comment
     * @param int $appli
     * @return bool
     * @throws ApiException
     * @throws WbsException
     */
    public function subscribe($callback = '', $comment = '', $appli = 1)
    {
        if (empty($callback)) {
            throw new ApiException('First parameter "callback" can\'t be empty');
        }

        if (empty($comment)) {
            throw new ApiException('Second parameter "comment" can\'t be empty');
        }

        $params = array(
            'callbackurl' => $callback,
            'comment'     => $comment,
            'appli'       => $appli
        );

        // Add a subscription
        $subscribe = $this->request('notify', 'subscribe', $params);
        return $subscribe;
    }

    /**
     * @param string $callback
     * @param int $appli
     * @return bool
     * @throws ApiException
     * @throws WbsException
     */
    public function unsubscribe($callback = '', $appli = 1)
    {
        if (empty($callback)) {
            throw new ApiException('First parameter "callback" can\'t be empty');
        }

        $params = array(
            'callbackurl' => $callback,
            'appli'       => $appli
        );

        // Revoke subscription
        $unsubscribe = $this->request('notify', 'revoke', $params);
        return $unsubscribe;
    }

    /**
     * @param int $appli
     * @return Collection\SubscriptionCollection
     * @throws WbsException
     */
    public function listSubscriptions($appli = 1)
    {
        $list = $this->request('notify', 'list', array('appli' => $appli));
        return new Collection\SubscriptionCollection($list);
    }

    /**
     * @param string $callback
     * @param int $appli
     * @return Entity\Subscription
     * @throws ApiException
     * @throws WbsException
     */
    public function isSubscribed($callback = '', $appli = 1)
    {
        if (empty($callback)) {
            throw new ApiException('First parameter "callback" can\'t be empty');
        }

        $params = array(
            'callbackurl' => $callback,
            'appli'       => $appli
        );

        $isSubscribed = $this->request('notify', 'get', $params);
        return new Entity\Subscription($isSubscribed);
    }
}
