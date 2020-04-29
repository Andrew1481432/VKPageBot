<?php
/**
 * ____   ____  ___  ____   _______     _        ______  ________  ______      ___    _________
 *|_  _| |_  _||_  ||_  _| |_   __ \   / \     .' ___  ||_   __  ||_   _ \   .'   `. |  _   _  |
 *   \ \   / /    | |_/ /     | |__) | / _ \   / .'   \_|  | |_ \_|  | |_) | /  .-.  \|_/ | | \_|
 *   \ \ / /     |  __'.     |  ___/ / ___ \  | |   ____  |  _| _   |  __'. | |   | |    | |
 *    \ ' /     _| |  \ \_  _| |_  _/ /   \ \_\ `.___]  |_| |__/ | _| |__) |\  `-'  /   _| |_
 *     \_/     |____||____||_____||____| |____|`._____.'|________||_______/  `.___.'   |_____|
 * @author AlexBrin
 */

class VKPageBot {
	const BASE = 'https://api.vk.com/method/';

	const EVENT_UPDATE_FLAG_MESSAGE = 1;
	const EVENT_SET_FLAG_MESSAGE = 2;
	const EVENT_REPLACE_FLAG_MESSAGE = 3;
	const EVENT_NEW_MESSAGE = "message_new";
	const EVENT_EDIT_MESSAGE = "message_edit";
	const EVENT_READ_INPUT = 6;
	const EVENT_READ_OUTPUT = 7;
	const EVENT_FRIEND_ONLINE = 8;
	const EVENT_FRIEND_OFFLINE = 9;
	const EVENT_CHAT_CHANGE_INFO = 52;
	const EVENT_CHAT_CHANGE = "chat_title_update";
	const EVENT_CHAT_CHANGE_INFO_INVITE_USER = "chat_invite_user";
	const EVENT_CHAT_CHANGE_INFO_INVITE_USER_BY_LINK = "chat_invite_user_by_link";
	const EVENT_CHAT_CHANGE_INFO_KICK_USER = "chat_kick_user";
	const EVENT_USER_WRITING = 61;
	const EVENT_USER_CHAT_WRITING = 62;

	protected static $platforms = [
		1 => 'Мобильная версия сайта или неопознанное приложение',
		2 => 'iPhone',
		3 => 'iPad',
		4 => 'Android',
		5 => 'Windows Phone',
		6 => 'Windows 8/10',
		7 => 'Полная версия сайта или неопознанное приложение'
	];

	/**
	 * @var string
	 */
	protected $token;

	/**
	 * @var array
	 */
	protected $config;

	/**
	 * @var array
	 */
	protected $longpoll = [];

	/**
	 * @var array
	 */
	protected $functions = [];

	/**
	 * @var array
	 */
	protected $data = [];

	protected static $instance;

	public function __construct($loop = false) {
		$this->config = json_decode(file_get_contents('config.json'), true);
		$this->token = $this->config['token'];
		unset($this->config['token']);

		$this->getLongPollData();

		self::$instance = &$this;
	}

	public function __destruct() {
		if($this->com)
			fclose($this->com);
	}

	/**
	 * @param      $url
	 * @param bool $post
	 *
	 * @return string
	 */
	public function curl_get_contents($url, $post = true): string{
		$get_options = [
			"ssl" => [
				"verify_peer" => false,
				"verify_peer_name" => false,
			]
		];
		if($post) {
			if(empty(($array = explode("?", $url))[1]))
				$array[1] = '';
			[$url, $postdata] = $array;
			$get_options = array_merge($get_options, [
				'http'=> [
					'method'  => 'POST',
					'header'  => 'Content-Type: application/x-www-form-urlencoded',
					'content' => $postdata,
					'timeout' => 30
				]
			]);
		} else {
			$get_options = array_merge($get_options, [
				'http'=> [
					'timeout' => 30
				]
			]);
		}
		$data = file_get_contents($url, false, stream_context_create($get_options));
		return $data;
	}

	private function getLongPollData() {
		$this->longpoll = $this->request('groups.getLongPollServer', [
			'group_id' => $this->config['group_id']
		]);
	}

	public function __get($key) {
		return isset($this->data[$key]) ? $this->data[$key] : null;
	}

	public function __set($key, $value) {
		$this->data[$key] = $value;
	}

	public function getUsername($userId) {
		$user = $this->request('users.get', [
			'user_ids' => $userId
		])[0];

		return $user['first_name'] . ' ' . $user['last_name'];
	}

	public function getPlatform($extra) {
		return self::$platforms[$extra & 0xFF];
	}

	protected function sendMessage($message, $targetId, $token = null, $repliedId = null) {
		foreach($this->getConfig()['forwardFilter'] as $match)
			$message = preg_replace($match, $this->getConfig()['forwardReplace'], $message);

		$params = [
			'random_id' => mt_rand(PHP_INT_MIN, PHP_INT_MAX),
			'peer_id' => $targetId,
			'message' => $message,
			'v' => '5.95',
		];

		if($token != null)
			$params['access_token'] = $token;

		if($repliedId && $this->getConfig()['forwardMessage'])
			$params['forward_messages'] = $repliedId;

		$this->request('messages.send', $params);
	}

	public function loop() {
		try {
			print_r("Work...\n");

			$ts = $this->longpoll['ts'];
			while(true) {
				$events = $this->curl_get_contents($this->longpoll['server'] . "?act=a_check&mode=2&version=3&key=" . $this->longpoll['key'] . "&ts=" . $ts . "&wait=25");
				$events = json_decode($events, true);

				if(isset($events['failed'])) {
					switch($events['failed']) {
						case 1:
							$ts = $events['ts'];
							break;
						case 2:
						case 3:
							$this->getLongPollData();
							break;
					}
					continue;
				}

				$ts = $events["ts"];

				if(is_array($events["updates"])) {
					foreach($events["updates"] as $event) {
						if(isset($event["object"]['action']) && is_array($event["object"]['action'])) {
							switch($event["object"]['action']['type']) {
								case self::EVENT_CHAT_CHANGE:
									$event = ["type" => self::EVENT_CHAT_CHANGE, "object" => ["chat_id" => $event["object"]["peer_id"] - 2000000000, "from_id" => $event["object"]["from_id"], "text" => $event["object"]['action']['text']],];
									break;

								case self::EVENT_CHAT_CHANGE_INFO_INVITE_USER:
								case self::EVENT_CHAT_CHANGE_INFO_INVITE_USER_BY_LINK:
								case self::EVENT_CHAT_CHANGE_INFO_KICK_USER:
									$event = ["type" => self::EVENT_CHAT_CHANGE_INFO, "object" => ["type" => $event["object"]['action']['type'], "chat_id" => $event["object"]["peer_id"] - 2000000000, "from_id" => (($event["object"]['action']['type'] === self::EVENT_CHAT_CHANGE_INFO_INVITE_USER) ? $event["object"]['action']['member_id'] : $event["object"]["from_id"])],];
									break;
							}
						}

						switch(array_shift($event)) {
							case self::EVENT_UPDATE_FLAG_MESSAGE:
								if(!isset($this->functions[self::EVENT_UPDATE_FLAG_MESSAGE]))
									continue 2;

								for($i = 0; $i < count($this->functions[self::EVENT_UPDATE_FLAG_MESSAGE]); $i++) {
									$callable = $this->functions[self::EVENT_UPDATE_FLAG_MESSAGE][$i]['class'];
									$func = $this->functions[self::EVENT_UPDATE_FLAG_MESSAGE][$i]['func'];

									$callable::$func($event, $this->getConfig());
								}
								break;

							case self::EVENT_SET_FLAG_MESSAGE:
								if(!isset($this->functions[self::EVENT_SET_FLAG_MESSAGE]))
									continue 2;

								for($i = 0; $i < count($this->functions[self::EVENT_SET_FLAG_MESSAGE]); $i++) {
									$callable = $this->functions[self::EVENT_SET_FLAG_MESSAGE][$i]['class'];
									$func = $this->functions[self::EVENT_SET_FLAG_MESSAGE][$i]['func'];

									$callable::$func($event, $this->getConfig());
								}
								break;

							case self::EVENT_REPLACE_FLAG_MESSAGE:
								if(!isset($this->functions[self::EVENT_REPLACE_FLAG_MESSAGE]))
									continue 2;

								for($i = 0; $i < count($this->functions[self::EVENT_REPLACE_FLAG_MESSAGE]); $i++) {
									$callable = $this->functions[self::EVENT_REPLACE_FLAG_MESSAGE][$i]['class'];
									$func = $this->functions[self::EVENT_REPLACE_FLAG_MESSAGE][$i]['func'];

									$callable::$func($event, $this->getConfig());
								}
								break;

							case self::EVENT_NEW_MESSAGE:
								if(!isset($this->functions[self::EVENT_NEW_MESSAGE]))
									continue 2;

								for($i = 0; $i < count($this->functions[self::EVENT_NEW_MESSAGE]); $i++) {
									$callable = $this->functions[self::EVENT_NEW_MESSAGE][$i]['class'];
									$func = $this->functions[self::EVENT_NEW_MESSAGE][$i]['func'];

									$callable::$func($event["object"], $this->getConfig());
								}
								break;

							case self::EVENT_EDIT_MESSAGE:
								if(!isset($this->functions[self::EVENT_NEW_MESSAGE]))
									continue 2;

								for($i = 0; $i < count($this->functions[self::EVENT_NEW_MESSAGE]); $i++) {
									$callable = $this->functions[self::EVENT_NEW_MESSAGE][$i]['class'];
									$func = $this->functions[self::EVENT_NEW_MESSAGE][$i]['func'];

									$callable::$func($event, $this->getConfig());
								}
								break;

							case self::EVENT_READ_INPUT:
								if(!isset($this->functions[self::EVENT_READ_INPUT]))
									continue 2;

								for($i = 0; $i < count($this->functions[self::EVENT_READ_INPUT]); $i++) {
									$callable = $this->functions[self::EVENT_READ_INPUT][$i]['class'];
									$func = $this->functions[self::EVENT_READ_INPUT][$i]['func'];

									$callable::$func($event, $this->getConfig());
								}
								break;

							case self::EVENT_READ_OUTPUT:
								if(!isset($this->functions[self::EVENT_READ_OUTPUT]))
									continue 2;


								for($i = 0; $i < count($this->functions[self::EVENT_READ_OUTPUT]); $i++) {
									$callable = $this->functions[self::EVENT_READ_OUTPUT][$i]['class'];
									$func = $this->functions[self::EVENT_READ_OUTPUT][$i]['func'];

									$callable::$func($event, $this->getConfig());
								}
								break;

							case self::EVENT_FRIEND_ONLINE:
								if(!isset($this->functions[self::EVENT_FRIEND_ONLINE]))
									continue 2;


								for($i = 0; $i < count($this->functions[self::EVENT_FRIEND_ONLINE]); $i++) {
									$callable = $this->functions[self::EVENT_FRIEND_ONLINE][$i]['class'];
									$func = $this->functions[self::EVENT_FRIEND_ONLINE][$i]['func'];

									$callable::$func($event, $this->getConfig());
								}
								break;

							case self::EVENT_FRIEND_OFFLINE:
								if(!isset($this->functions[self::EVENT_FRIEND_OFFLINE]))
									continue 2;

								for($i = 0; $i < count($this->functions[self::EVENT_FRIEND_OFFLINE]); $i++) {
									$callable = $this->functions[self::EVENT_FRIEND_OFFLINE][$i]['class'];
									$func = $this->functions[self::EVENT_FRIEND_OFFLINE][$i]['func'];

									$callable::$func($event, $this->getConfig());
								}
								break;

							case self::EVENT_USER_WRITING:
								if(!isset($this->functions[self::EVENT_USER_WRITING]))
									continue 2;

								for($i = 0; $i < count($this->functions[self::EVENT_USER_WRITING]); $i++) {
									$callable = $this->functions[self::EVENT_USER_WRITING][$i]['class'];
									$func = $this->functions[self::EVENT_USER_WRITING][$i]['func'];

									$callable::$func($event, $this->getConfig());
								}
								break;

							case self::EVENT_CHAT_CHANGE:
								if(!isset($this->functions[self::EVENT_CHAT_CHANGE]))
									continue 2;

								for($i = 0; $i < count($this->functions[self::EVENT_CHAT_CHANGE]); $i++) {
									$callable = $this->functions[self::EVENT_CHAT_CHANGE][$i]['class'];
									$func = $this->functions[self::EVENT_CHAT_CHANGE][$i]['func'];

									$callable::$func($event["object"], $this->getConfig());
								}
								break;

							case self::EVENT_CHAT_CHANGE_INFO:
								if(!isset($this->functions[self::EVENT_CHAT_CHANGE_INFO]))
									continue 2;

								for($i = 0; $i < count($this->functions[self::EVENT_CHAT_CHANGE_INFO]); $i++) {
									$callable = $this->functions[self::EVENT_CHAT_CHANGE_INFO][$i]['class'];
									$func = $this->functions[self::EVENT_CHAT_CHANGE_INFO][$i]['func'];

									$callable::$func($event["object"], $this->getConfig());
								}
								break;

							case self::EVENT_USER_CHAT_WRITING:
								if(!isset($this->functions[self::EVENT_USER_CHAT_WRITING]))
									continue 2;

								for($i = 0; $i < count($this->functions[self::EVENT_USER_CHAT_WRITING]); $i++) {
									$callable = $this->functions[self::EVENT_USER_CHAT_WRITING][$i]['class'];
									$func = $this->functions[self::EVENT_USER_CHAT_WRITING][$i]['func'];

									$callable::$func($event, $this->getConfig());
								}
								break;


						}

					}
				} else {
					// TODO error
				}
			}
		} catch (\Exception $e) {
			// TODO error
		}
	}

	public function addHandler($eventType, $className, $functionName, $params = []) {
		if(!isset($this->functions[$eventType]))
			$this->functions[$eventType] = [];

		$this->functions[$eventType][] = [
			'class' => $className,
			'func' => $functionName,
			'params' => $params,
		];
	}

	public function request($method, $params = []) {
		if(!isset($params['access_token']))
			$params['access_token'] = $this->token;
		$params['v'] = '5.95';
		$params = http_build_query($params);

		$response = $this->curl_get_contents(self::BASE . $method . '?' . $params);
		$response = json_decode($response, true);

		if(isset($response['error']))
			echo $response['error']['error_msg'], ' ', $response['error']['error_code'];

		return $response['response'];
	}

	public function getToken() {
		return $this->token;
	}

	public function getConfig() {
		return $this->config;
	}

	public static function getInstance() : VKPageBot {
		return self::$instance;
	}

}

?>
