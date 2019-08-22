<?php

namespace SergiX44\Scraper;

use Campo\UserAgent;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

class TwitterScraper
{
	const TIMEOUT = 20;

	/** @var array */
	protected $options;

	/** @var Client */
	protected $client;

	/** @var int */
	protected $fetchedTweets = 0;

	/** @var string */
	protected $query;

	/** @var DateTime */
	protected $startDate;

	/** @var DateTime */
	protected $endDate;

	/** @var array */
	protected $tweets = [];

	/** @var callable */
	protected $saveClosure;

	/** @var string */
	private $lang = null;

	/** @var bool */
	private $clearAfterEventSave = false;

	/** @var int */
	private $chunkSize = 100;

	/** @var int */
	private $timeout;

	/**
	 * TwitterScraper constructor.
	 * @param $timeout
	 * @throws Exception
	 */
	private function __construct($timeout)
	{
		$this->timeout = $timeout;
		$this->refreshClient();
	}

	private function refreshClient()
	{
		$this->options = [
			'timeout' => $this->timeout,
			'headers' => [
				'User-Agent' => $this->generateUserAgent(),
			],
		];

		$this->client = new Client($this->options);
	}

	/**
	 * @param int $timeout
	 * @return TwitterScraper
	 * @throws Exception
	 */
	public static function make($timeout = self::TIMEOUT)
	{
		return new static($timeout);
	}

	/**
	 * @param string $lang
	 * @return $this
	 */
	public function setLang(string $lang)
	{
		$this->lang = $lang;
		return $this;
	}


	/**
	 * @param int $size
	 * @return $this
	 */
	public function setChunkSize(int $size)
	{
		$this->chunkSize = $size;
		return $this;
	}

	/**
	 * @param string $query
	 * @param DateTime|null $start
	 * @param DateTime|null $end
	 * @return $this
	 */
	public function search(string $query, ?DateTime $start = null, ?DateTime $end = null)
	{
		$this->query = $query;
		$this->startDate = $start;
		$this->endDate = $end;
		return $this;
	}

	/**
	 * @return $this
	 * @throws GuzzleException
	 */
	public function run()
	{
		$this->query($this->query, $this->startDate, $this->endDate);
		$this->processSave();

		return $this;
	}

	/**
	 * @param callable $closure
	 * @param bool $shouldClear
	 * @return $this
	 */
	public function save(callable $closure, $shouldClear = false)
	{
		$this->saveClosure = $closure;
		$this->clearAfterEventSave = $shouldClear;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getTweets()
	{
		return array_values($this->tweets);
	}

	/**
	 * @param string $query
	 * @param DateTime|null $start
	 * @param DateTime|null $end
	 * @throws GuzzleException
	 */
	protected function query(string $query, ?DateTime $start = null, ?DateTime $end = null)
	{
		if ($start === null) {
			$start = new DateTime('2006-03-01');
		}

		if ($end === null) {
			$end = new DateTime();
		}

		$start->setTime(0, 0, 0);
		$start->setTimezone(new \DateTimeZone('+00:00'));
		$end->setTime(0, 0, 0);
		$end->setTimezone(new \DateTimeZone('+00:00'));

		if (strpos($query, 'since') === false) {
			$query .= " since:{$start->format('Y-m-d')}";
		}

		if (strpos($query, 'until') === false) {
			$query .= " until:{$end->format('Y-m-d')}";
		}

		if ($this->lang !== null) {
			$query .= " lang:{$this->lang}";
		}

		$retries = 1;
		$lastDate = null;
		while ($retries < 5) {
			try {
				$lastDate = $this->queryInterval(rawurlencode($query));
				$retries = 5;
			} catch (Exception $e) {
				$this->refreshClient();
				sleep($retries);
				$retries++;
			}
		}
		if ($lastDate !== null) {
			$lastDate->setTime(0, 0, 0);

			if ($lastDate > $start) {
				$this->refreshClient();
				$this->query($this->query, $start, $lastDate);
			}
		}
	}

	protected function queryInterval(string $query)
	{
		$oldestTweetDate = null;
		$html = $this->client->request('GET', "https://mobile.twitter.com/search?src=typed_query&f=live&q={$query}")->getBody()->getContents();

		$matches = [];
		preg_match('/gt=(\d+)/', $html, $matches);

		$this->options['headers']['Authorization'] = 'Bearer AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs%3D1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';
		$this->options['headers']['X-Guest-Token'] = $matches[1];

		$this->client = new Client($this->options);

		$shouldScroll = true;
		$cursor = '';
		do {
			try {
				$response = $this->client->request('GET', "https://api.twitter.com/2/search/adaptive.json?include_profile_interstitial_type=1&include_blocking=1&include_blocked_by=1&include_followed_by=1&include_want_retweets=&include_mute_edge=1&include_can_dm=1&include_can_media_tag=1&skip_status=1&cards_platform=Web-12&include_cards=1&include_composer_source=true&include_ext_alt_text=true&include_reply_count=1&tweet_mode=extended&include_entities=true&include_user_entities=true&include_ext_media_color=true&include_ext_media_availability=true&send_error_codes=true&q={$query}&tweet_search_mode=live&count={$this->chunkSize}&query_source=typed_query&cursor={$cursor}&pc=1&spelling_corrections=1&ext=mediaStats%2ChighlightedLabel%2CcameraMoment");
			} catch (ClientException $ce) {
				if ($ce->getCode() === 429) { // too many requests, refresh client and restrict interval
					return $oldestTweetDate;
				}
				throw $ce; // something else was gone wrong
			}
			$json = json_decode($response->getBody()->getContents(), true);


			foreach ($json['globalObjects']['tweets'] as $scrapedTweet) {

				$url = "https://twitter.com/{$json['globalObjects']['users'][$scrapedTweet['user_id']]['screen_name']}/status/{$scrapedTweet['id']}";

				$hashtags = [];
				foreach ($scrapedTweet['entities']['hashtags'] as $hashtag) {
					$hashtags[] = "#{$hashtag['text']}";
				}

				$mentions = [];
				foreach ($scrapedTweet['entities']['user_mentions'] as $mention) {
					$mentions[$mention['id']] = $mention['screen_name'];
				}

				$images = [];
				if (isset($scrapedTweet['entities']['media'])) {
					foreach ($scrapedTweet['entities']['media'] as $key => $image) {
						if ($image['type'] === 'photo') {
							$images[] = $image['media_url_https'];
						}
					}
				}

				$date = DateTime::createFromFormat('D M d H:i:s O Y', $scrapedTweet['created_at']);

				$replyTo = null;
				if ($scrapedTweet['in_reply_to_user_id'] !== null) {
					$replyTo = [$scrapedTweet['in_reply_to_user_id'] => $scrapedTweet['in_reply_to_screen_name']];
				}

				if (!array_key_exists($scrapedTweet['id'], $this->tweets)) {
					$this->fetchedTweets++;

					$this->tweets[$scrapedTweet['id']] = [
						'tweetId' => $scrapedTweet['id'],
						'url' => $url,
						'text' => $scrapedTweet['full_text'],
						'datetime' => $date,
						'user_id' => $scrapedTweet['user_id'],
						'user_name' => $json['globalObjects']['users'][$scrapedTweet['user_id']]['screen_name'],
						'user_fullname' => $json['globalObjects']['users'][$scrapedTweet['user_id']]['name'],
						'user_followers' => $json['globalObjects']['users'][$scrapedTweet['user_id']]['followers_count'],
						'user_following' => $json['globalObjects']['users'][$scrapedTweet['user_id']]['friends_count'],
						'retweets' => $scrapedTweet['retweet_count'],
						'replies' => $scrapedTweet['reply_count'],
						'likes' => $scrapedTweet['favorite_count'],
						'hashtags' => $hashtags,
						'mentions' => $mentions,
						'images' => $images,
						'reply_to' => $replyTo,
						'lang' => $scrapedTweet['lang'],
					];

					if ($this->fetchedTweets % $this->chunkSize === 0) {
						$this->processSave();
					}
				}
				$oldestTweetDate = $date;
			}

			// get the next cursor
			$entries = $json['timeline']['instructions'];
			$entries = $entries[count($entries) - 1]; // get last element
			if (isset($entries['addEntries'])) {
				$entries = $entries['addEntries']['entries'];
				$entries = $entries[count($entries) - 1]; // get last element
			} else {
				$entries = $entries['replaceEntry']['entry'];
			}

			$newCursor = $entries['content']['operation']['cursor']['value'];
			if ($newCursor === $cursor) {
				$shouldScroll = false;
			}
			$cursor = $newCursor;

		} while ($shouldScroll);

		return $oldestTweetDate;
	}

	protected function processSave()
	{
		if ($this->saveClosure !== null) {
			$closure = $this->saveClosure;

			$closure(array_values($this->tweets), $this->fetchedTweets);

			if ($this->clearAfterEventSave) {
				$this->tweets = [];
			}
		}
	}

	/**
	 * @return string
	 * @throws Exception
	 */
	private function generateUserAgent()
	{
		return UserAgent::random(['os_type' => ['Windows', 'OS X', 'Linux'], 'device_type' => 'Desktop', 'agent_name' => 'Chrome']);
	}
}