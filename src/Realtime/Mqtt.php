<?php

namespace InstagramAPI\Realtime;

use BinSoul\Net\Mqtt\Client\React\ReactMqttClient;
use BinSoul\Net\Mqtt\DefaultConnection;
use BinSoul\Net\Mqtt\DefaultMessage;
use BinSoul\Net\Mqtt\Message;
use Evenement\EventEmitterInterface;
use Fbns\Client\AuthInterface;
use InstagramAPI\Client as HttpClient;
use InstagramAPI\Constants;
use InstagramAPI\Devices\DeviceInterface;
use InstagramAPI\React\PersistentInterface;
use InstagramAPI\React\PersistentTrait;
use InstagramAPI\Realtime;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\TimerInterface;
use React\Socket\ConnectorInterface;

class Mqtt implements PersistentInterface
{
    use PersistentTrait;

    const REALTIME_CLIENT_TYPE = 'mqtt';

    /* PubSub topics */
    const DIRECT_TOPIC_TEMPLATE = 'ig/u/v1/%s';
    const LIVE_TOPIC_TEMPLATE = 'ig/live_notification_subscribe/%s';

    /* GraphQL subscription topics */
    const TYPING_TOPIC_TEMPLATE = '1/graphqlsubscriptions/17867973967082385/{"input_data": {"user_id":%s}}';

    /** @var EventEmitterInterface */
    protected $_target;

    /** @var ConnectorInterface */
    protected $_connector;

    /** @var AuthInterface */
    protected $_auth;

    /** @var DeviceInterface */
    protected $_device;

    /** @var LoopInterface */
    protected $_loop;

    /** @var TimerInterface */
    protected $_keepaliveTimer;

    /** @var bool */
    protected $_shutdown;

    /** @var LoggerInterface */
    protected $_logger;

    /** @var string[] */
    protected $_pubsubTopics;
    /** @var string[] */
    protected $_graphqlTopics;

    /** @var ReactMqttClient */
    protected $_client;

    /** @var bool */
    protected $_mqttLiveEnabled;
    /** @var bool */
    protected $_irisEnabled;
    /** @var string|null */
    protected $_msgTypeBlacklist;
    /** @var bool */
    protected $_graphQlEnabled;

    /**
     * Constructor.
     *
     * @param EventEmitterInterface $target
     * @param ConnectorInterface    $connector
     * @param AuthInterface         $auth
     * @param DeviceInterface       $device
     * @param array                 $experiments
     * @param LoopInterface         $loop
     * @param LoggerInterface       $logger
     */
    public function __construct(
        EventEmitterInterface $target,
        ConnectorInterface $connector,
        AuthInterface $auth,
        DeviceInterface $device,
        array $experiments,
        LoopInterface $loop,
        LoggerInterface $logger)
    {
        $this->_target = $target;
        $this->_connector = $connector;
        $this->_auth = $auth;
        $this->_device = $device;
        $this->_loop = $loop;
        $this->_logger = $logger;

        $this->_loadExperiments($experiments);

        $this->_shutdown = false;
        $this->_client = $this->_getClient();
    }

    /** {@inheritdoc} */
    public function getLoop()
    {
        return $this->_loop;
    }

    /** {@inheritdoc} */
    public function isActive()
    {
        return !$this->_shutdown;
    }

    /**
     * Cancel a keepalive timer (if any).
     */
    protected function _cancelKeepaliveTimer()
    {
        if ($this->_keepaliveTimer !== null) {
            if ($this->_keepaliveTimer->isActive()) {
                $this->_logger->info('Existing keepalive timer has been canceled.');
                $this->_keepaliveTimer->cancel();
            }
            $this->_keepaliveTimer = null;
        }
    }

    /**
     * Set up a new keepalive timer.
     */
    protected function _setKeepaliveTimer()
    {
        $this->_cancelKeepaliveTimer();
        $keepaliveInterval = Mqtt\Config::MQTT_KEEPALIVE;
        $this->_logger->info(sprintf('Setting up keepalive timer to %d seconds', $keepaliveInterval));
        $this->_keepaliveTimer = $this->_loop->addTimer($keepaliveInterval, function () {
            $this->_logger->info('Keepalive timer has been fired.');
            $this->_disconnect();
        });
    }

    /**
     * Try to establish a connection.
     */
    protected function _connect()
    {
        $this->_setReconnectTimer(function () {
            $this->_logger->info(sprintf('Connecting to %s:%d...', Mqtt\Config::DEFAULT_HOST, Mqtt\Config::DEFAULT_PORT));

            $connection = new DefaultConnection(
                $this->_getMqttUsername(),
                $this->_auth->getPassword(),
                null,
                $this->_auth->getClientId(),
                Mqtt\Config::MQTT_KEEPALIVE,
                Mqtt\Config::MQTT_VERSION,
                true
            );

            return $this->_client->connect(Mqtt\Config::DEFAULT_HOST, Mqtt\Config::DEFAULT_PORT, $connection, Mqtt\Config::CONNECTION_TIMEOUT);
        });
    }

    /**
     * Perform first connection in a row.
     */
    public function start()
    {
        $this->_shutdown = false;
        $this->_reconnectInterval = 0;
        $this->_connect();
    }

    /**
     * Whether connection is established.
     *
     * @return bool
     */
    protected function _isConnected()
    {
        return $this->_client->isConnected();
    }

    /**
     * Disconnect from server.
     */
    protected function _disconnect()
    {
        $this->_cancelKeepaliveTimer();
        $this->_client->disconnect();
    }

    /**
     * Proxy for _disconnect().
     */
    public function stop()
    {
        $this->_logger->info('Shutting down...');
        $this->_shutdown = true;
        $this->_cancelReconnectTimer();
        $this->_disconnect();
    }

    /**
     * Check if feature is enabled.
     *
     * @param array  $params
     * @param string $feature
     *
     * @return bool
     */
    protected function _isFeatureEnabled(
        array $params,
        $feature)
    {
        if (!isset($params[$feature])) {
            return false;
        }

        return in_array($params[$feature], ['enabled', 'true', '1']);
    }

    /**
     * Send the command.
     *
     * @param CommandInterface $command
     *
     * @throws \LogicException
     */
    public function sendCommand(
        CommandInterface $command)
    {
        if (!$this->_isConnected()) {
            throw new \LogicException('Tried to send the command while offline.');
        }

        $this->_publish($command->getTopic(), Realtime::jsonEncode($command), $command->getQosLevel());
    }

    /**
     * Process incoming action.
     *
     * @param array $message
     */
    protected function _processAction(
        array $message)
    {
        $this->_logger->info(sprintf('Received action "%s"', $message['action']));
        switch ($message['action']) {
            case Action::ACK:
                /** @var Action\Ack $action */
                $action = new Action\Ack($message);
                break;
            default:
                $this->_logger->warning(sprintf('Action "%s" is ignored (unknown type)', $message['action']));

                return;
        }
        $action->handle($this->_target, $this->_logger);
    }

    /**
     * Process incoming event.
     *
     * @param array $message
     */
    protected function _processEvent(
        array $message)
    {
        $this->_logger->info(sprintf('Received event "%s"', $message['event']));
        switch ($message['event']) {
            case Event::PATCH:
                /** @var Event\Patch $event */
                $event = new Event\Patch($message);
                break;
            default:
                $this->_logger->warning(sprintf('Event "%s" is ignored (unknown type)', $message['event']));

                return;
        }
        $event->handle($this->_target, $this->_logger);
    }

    /**
     * Process single incoming message.
     *
     * @param array $message
     */
    protected function _processSingleMessage(
        array $message)
    {
        if (isset($message['event'])) {
            $this->_processEvent($message);
        } elseif (isset($message['action'])) {
            $this->_processAction($message);
        } else {
            $this->_logger->warning('Invalid message (both event and action are missing)');
        }
    }

    /**
     * Process incoming message.
     *
     * @param string $message
     */
    protected function _processMessage(
        $message)
    {
        $this->_logger->info(sprintf('Received message %s', $message));
        // TODO: Rewrite this in a nicer way? Such as checking for keys on the
        // decoded array to determine if key [0] exists (hence a multi-message)?
        $isMultiMessages = is_string($message) && $message !== '' && $message[0] === '[';
        $message = HttpClient::api_body_decode($message);
        if (!$isMultiMessages) {
            $this->_processSingleMessage($message);
        } else {
            foreach ($message as $singleMessage) {
                $this->_processSingleMessage($singleMessage);
            }
        }
    }

    /**
     * Load experiments.
     *
     * @param array $experiments
     */
    protected function _loadExperiments(
        array $experiments)
    {
        // Direct features.
        $directFeatures = isset($experiments['ig_android_realtime_iris'])
            ? $experiments['ig_android_realtime_iris'] : [];
        $this->_irisEnabled = $this->_isFeatureEnabled($directFeatures, 'is_direct_over_iris_enabled');
        if (isset($directFeatures['pubsub_msg_type_blacklist'])) {
            $this->_msgTypeBlacklist = $directFeatures['pubsub_msg_type_blacklist'];
        }

        // Live features.
        $liveFeatures = isset($experiments['ig_android_skywalker_live_event_start_end'])
            ? $experiments['ig_android_skywalker_live_event_start_end'] : [];
        $this->_mqttLiveEnabled = $this->_isFeatureEnabled($liveFeatures, 'is_enabled');

        // GraphQL features.
        $graphqlFeatures = isset($experiments['ig_android_gqls_typing_indicator'])
            ? $experiments['ig_android_gqls_typing_indicator'] : [];
        $this->_graphQlEnabled = $this->_isFeatureEnabled($graphqlFeatures, 'is_enabled');

        // Set up PubSub topics.
        $this->_pubsubTopics = [];
        if ($this->_mqttLiveEnabled) {
            $this->_pubsubTopics[] = sprintf(self::LIVE_TOPIC_TEMPLATE, $this->_auth->getUserId());
        }
        $this->_pubsubTopics[] = sprintf(self::DIRECT_TOPIC_TEMPLATE, $this->_auth->getUserId());

        // Set up GraphQL topics.
        $this->_graphqlTopics = [];
        if ($this->_graphQlEnabled) {
            $this->_graphqlTopics[] = sprintf(self::TYPING_TOPIC_TEMPLATE, $this->_auth->getUserId());
        }
    }

    /**
     * Returns application specific info.
     *
     * @return array
     */
    protected function _getAppSpecificInfo()
    {
        $result = [
            'platform'      => Constants::PLATFORM,
            'app_version'   => Constants::IG_VERSION,
            'capabilities'  => Constants::X_IG_Capabilities,
            'User-Agent'    => $this->_device->getUserAgent(),
            'ig_mqtt_route' => 'django',
        ];
        // PubSub message type blacklist.
        $msgTypeBlacklist = '';
        if ($this->_msgTypeBlacklist !== null && strlen($this->_msgTypeBlacklist)) {
            $msgTypeBlacklist = $this->_msgTypeBlacklist;
        }
        if ($this->_graphQlEnabled) {
            if (strlen($msgTypeBlacklist)) {
                $msgTypeBlacklist .= ', typing_type';
            } else {
                $msgTypeBlacklist = 'typing_type';
            }
        }
        if (strlen($msgTypeBlacklist)) {
            $result['pubsub_msg_type_blacklist'] = $msgTypeBlacklist;
        }
        // Accept-Language should be last one.
        $result['Accept-Language'] = Constants::ACCEPT_LANGUAGE;

        return $result;
    }

    /**
     * Returns username for MQTT connection.
     *
     * @return string
     */
    protected function _getMqttUsername()
    {
        // Session ID is uptime in msec.
        $sessionId = (microtime(true) - strtotime('Last Monday')) * 1000;
        // Random buster-string to avoid clashing with other data.
        $randNum = mt_rand(1000000, 9999999);
        $topics = [
            Mqtt\Topics::PUBSUB,
        ];
        if ($this->_graphQlEnabled) {
            $topics[] = Mqtt\Topics::REALTIME_SUB;
        }
        $topics[] = Mqtt\Topics::SEND_MESSAGE_RESPONSE;
        if ($this->_irisEnabled) {
            $topics[] = Mqtt\Topics::IRIS_SUB_RESPONSE;
            $topics[] = Mqtt\Topics::MESSAGE_SYNC;
        }
        $result = json_encode([
            // USER_ID
            'u'                 => '%ACCOUNT_ID_'.$randNum.'%',
            // AGENT
            'a'                 => $this->_device->getFbUserAgent(Constants::INSTAGRAM_APPLICATION_NAME),
            // CAPABILITIES
            'cp'                => Mqtt\Capabilities::DEFAULT_SET,
            // CLIENT_MQTT_SESSION_ID
            'mqtt_sid'          => '%SESSION_ID_'.$randNum.'%',
            // NETWORK_TYPE
            'nwt'               => Mqtt\Config::NETWORK_TYPE_WIFI,
            // NETWORK_SUBTYPE
            'nwst'              => 0,
            // MAKE_USER_AVAILABLE_IN_FOREGROUND
            'chat_on'           => false,
            // NO_AUTOMATIC_FOREGROUND
            'no_auto_fg'        => true,
            // DEVICE_ID
            'd'                 => $this->_auth->getDeviceId(),
            // DEVICE_SECRET
            'ds'                => $this->_auth->getDeviceSecret(),
            // INITIAL_FOREGROUND_STATE
            'fg'                => false,
            // ENDPOINT_CAPABILITIES
            'ecp'               => 0,
            // PUBLISH_FORMAT
            'pf'                => Mqtt\Config::PUBLISH_FORMAT,
            // CLIENT_TYPE
            'ct'                => Mqtt\Config::CLIENT_TYPE,
            // APP_ID
            'aid'               => Constants::FACEBOOK_ANALYTICS_APPLICATION_ID,
            // SUBSCRIBE_TOPICS
            'st'                => $topics,
            // CLIENT_STACK
            'clientStack'       => 3,
            // APP_SPECIFIC_INFO
            'app_specific_info' => $this->_getAppSpecificInfo(),
        ]);
        $result = strtr($result, [
            json_encode('%ACCOUNT_ID_'.$randNum.'%') => $this->_auth->getUserId(),
            json_encode('%SESSION_ID_'.$randNum.'%') => round($sessionId),
        ]);

        return $result;
    }

    /**
     * Create a new MQTT client.
     *
     * @return ReactMqttClient
     */
    protected function _getClient()
    {
        $client = new ReactMqttClient($this->_connector, $this->_loop, null, new Mqtt\StreamParser());

        $client->on('error', function (\Exception $e) {
            $this->_logger->error($e->getMessage());
        });
        $client->on('warning', function (\Exception $e) {
            $this->_logger->warning($e->getMessage());
        });
        $client->on('open', function () {
            $this->_logger->info('Connection has been established');
        });
        $client->on('close', function () {
            $this->_logger->info('Connection has been closed');
            $this->_cancelKeepaliveTimer();
            if (!$this->_reconnectInterval) {
                $this->_connect();
            }
        });
        $client->on('connect', function () {
            $this->_logger->info('Connected to a broker');
            $this->_setKeepaliveTimer();
            $this->_subscribe();
        });
        $client->on('ping', function () {
            $this->_logger->info('Ping flow completed');
            $this->_setKeepaliveTimer();
        });
        $client->on('publish', function () {
            $this->_logger->info('Publish flow completed');
            $this->_setKeepaliveTimer();
        });
        $client->on('message', function (Message $message) {
            $this->_setKeepaliveTimer();
            $this->_onReceive($message);
        });
        $client->on('disconnect', function () {
            $this->_logger->info('Disconnected from broker');
        });

        return $client;
    }

    /**
     * Subscribe to all topics.
     */
    protected function _subscribe()
    {
        if (count($this->_pubsubTopics)) {
            $this->_logger->info(sprintf('Subscribing to pubsub topics %s', implode(', ', $this->_pubsubTopics)));
            $command = [
                'sub' => $this->_pubsubTopics,
            ];
            $this->_publish(Mqtt\Topics::PUBSUB, Realtime::jsonEncode($command), Mqtt\QosLevel::ACKNOWLEDGED_DELIVERY);
        }
        if (count($this->_graphqlTopics)) {
            $this->_logger->info(sprintf('Subscribing to graphql topics %s', implode(', ', $this->_graphqlTopics)));
            $command = [
                'sub' => $this->_graphqlTopics,
            ];
            $this->_publish(Mqtt\Topics::REALTIME_SUB, Realtime::jsonEncode($command), Mqtt\QosLevel::ACKNOWLEDGED_DELIVERY);
        }
    }

    /**
     * Unsubscribe from all topics.
     */
    protected function _unsubscribe()
    {
        if (count($this->_pubsubTopics)) {
            $this->_logger->info(sprintf('Unsubscribing from pubsub topics %s', implode(', ', $this->_pubsubTopics)));
            $command = [
                'unsub' => $this->_pubsubTopics,
            ];
            $this->_publish(Mqtt\Topics::PUBSUB, Realtime::jsonEncode($command), Mqtt\QosLevel::ACKNOWLEDGED_DELIVERY);
        }
        if (count($this->_graphqlTopics)) {
            $this->_logger->info(sprintf('Unsubscribing from graphql topics %s', implode(', ', $this->_graphqlTopics)));
            $command = [
                'unsub' => $this->_graphqlTopics,
            ];
            $this->_publish(Mqtt\Topics::REALTIME_SUB, Realtime::jsonEncode($command), Mqtt\QosLevel::ACKNOWLEDGED_DELIVERY);
        }
    }

    /**
     * Maps human readable topic to its identifier.
     *
     * @param string $topic
     *
     * @return string
     */
    protected function _mapTopic(
        $topic)
    {
        if (array_key_exists($topic, Mqtt\Topics::TOPIC_TO_ID_MAP)) {
            $result = Mqtt\Topics::TOPIC_TO_ID_MAP[$topic];
            $this->_logger->debug(sprintf('Topic "%s" has been mapped to "%s"', $topic, $result));
        } else {
            $result = $topic;
            $this->_logger->warning(sprintf('Topic "%s" does not exist in the enum', $topic));
        }

        return $result;
    }

    /**
     * @param string $topic
     * @param string $payload
     * @param int    $qosLevel
     */
    protected function _publish(
        $topic,
        $payload,
        $qosLevel)
    {
        $this->_logger->info(sprintf('Sending message %s to topic %s', $payload, $topic));
        $payload = zlib_encode($payload, ZLIB_ENCODING_DEFLATE, 9);
        // We need to map human readable topic name to its ID because of bandwidth saving.
        $topic = $this->_mapTopic($topic);
        $this->_client->publish(new DefaultMessage($topic, $payload, $qosLevel));
    }

    /**
     * Incoming message handler.
     *
     * @param Message $msg
     */
    protected function _onReceive(
        Message $msg)
    {
        $topic = $msg->getTopic();
        $payload = @zlib_decode($msg->getPayload());
        if ($payload === false) {
            $this->_logger->warning('Failed to inflate payload');

            return;
        }
        switch ($topic) {
            case Mqtt\Topics::PUBSUB:
            case Mqtt\Topics::PUBSUB_ID:
                $skywalker = new Mqtt\Skywalker($payload);
                if (!in_array($skywalker->getType(), [Mqtt\Skywalker::TYPE_DIRECT, Mqtt\Skywalker::TYPE_LIVE])) {
                    $this->_logger->warning(sprintf('Received Skywalker message with unsupported type %d', $skywalker->getType()));

                    return;
                }
                $payload = $skywalker->getPayload();
                break;
            case Mqtt\Topics::GRAPHQL:
            case Mqtt\Topics::GRAPHQL_ID:
            case Mqtt\Topics::REALTIME_SUB:
            case Mqtt\Topics::REALTIME_SUB_ID:
                $graphQl = new Mqtt\GraphQl($payload);
                if (!in_array($graphQl->getTopic(), [Mqtt\GraphQl::TOPIC_DIRECT])) {
                    $this->_logger->warning(sprintf('Received GraphQL message with unsupported topic %s', $graphQl->getTopic()));

                    return;
                }
                $payload = $graphQl->getPayload();
                break;
            case Mqtt\Topics::IRIS_SUB_RESPONSE:
            case Mqtt\Topics::IRIS_SUB_RESPONSE_ID:
                $json = HttpClient::api_body_decode($payload);
                if (!is_array($json)) {
                    $this->_logger->warning(sprintf('Failed to decode Iris JSON: %s', json_last_error_msg()));

                    return;
                }
                /** @var Mqtt\Iris $iris */
                $iris = new Mqtt\Iris($json);
                if (!$iris->isSucceeded()) {
                    $this->_logger->warning(sprintf('Failed to subscribe to Iris (%d): %s', $iris->getErrorType(), $iris->getErrorMessage()));
                }

                return;
            case Mqtt\Topics::SEND_MESSAGE_RESPONSE:
            case Mqtt\Topics::SEND_MESSAGE_RESPONSE_ID:
            case Mqtt\Topics::MESSAGE_SYNC:
            case Mqtt\Topics::MESSAGE_SYNC_ID:
                break;
            default:
                $this->_logger->warning(sprintf('Received message from unsupported topic "%s"', $topic));

                return;
        }
        $this->_processMessage($payload);
    }

    /** {@inheritdoc} */
    public function getLogger()
    {
        return $this->_logger;
    }
}
