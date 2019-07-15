<?php


namespace SergiX44\Scraper;


use Campo\UserAgent;
use DateTime;
use Exception;
use Goutte\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DomCrawler\Crawler;

class TwitterScraper
{
	const TIMEOUT = 10;

	/** @var array */
	protected $options;

	/** @var Client */
	protected $client;

	/** @var int */
	protected $fetchedTweets = 0;

	/** @var int */
	protected $pass = 1;

	/** @var string */
	protected $query;

	/** @var DateTime */
	protected $startDate;

	/** @var DateTime */
	protected $endDate;

	/** @var array */
	protected $tweets = [];

	/** @var string */
	protected $pathToFile;

	/** @var bool */
	protected $saveEveryPass = false;

	/** @var callable */
	protected $saveClosure;

	private function __construct($timeout)
	{
		$this->client = new Client();
		$this->setOptions($timeout);
	}

    /**
     * @param int $timeout
     * @throws Exception
     */
    private function setOptions($timeout = self::TIMEOUT)
	{
		$this->options = [
			'timeout' => $timeout,
			'headers' => [
				'User-Agent' => UserAgent::random(['os_type' => 'Windows', 'device_type' => 'Desktop']),
			],
		];
	}

	/**
	 * @param int $timeout
	 * @return TwitterScraper
	 */
	public static function make($timeout = self::TIMEOUT)
	{
		return new static($timeout);
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
	 * @param string $filepath
	 * @return $this
	 */
	public function setSaveFile(string $filepath)
	{
		$this->pathToFile = $filepath;
		return $this;
	}

	/**
	 * @param bool $flag
	 * @return $this
	 */
	public function saveEveryPass(bool $flag = null)
	{
		$this->saveEveryPass = $flag !== null ? $flag : !$this->saveEveryPass;
		return $this;
	}

	/**
	 * @return $this
	 */
	public function run()
	{
		$this->tweets = $this->query($this->query, $this->startDate, $this->endDate);
		$this->save($this->tweets, false);

		return $this;
	}

	/**
	 * @param callable $closure
	 * @return $this
	 */
	public function onSave(callable $closure)
	{
		$this->saveClosure = $closure;
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
	 * @return array|mixed|null
	 */
	protected function query(string $query, ?DateTime $start = null, ?DateTime $end = null)
	{
		$start->setTime(0, 0, 0);
		$end->setTime(0, 0, 0);

		if ($start !== null && strpos($query, 'since') === false) {
			$query .= " since:{$start->format('Y-m-d')}";
		}

		if ($end !== null && strpos($query, 'until') === false) {
			$query .= " until:{$end->format('Y-m-d')}";
		}


		$retries = 1;
		$tweets = null;
		$lastDate = $end;
		while ($retries < 5) {
			try {
				list($tweets, $lastDate) = $this->queryInterval(rawurlencode($query));
				$retries = 5;
			} catch (Exception | GuzzleException $e) {
				sleep($retries);
				$retries++;
			}
		}

		if ($this->saveEveryPass && !empty($tweets)) {
			$this->save($tweets, true);
		}

		if ($lastDate !== null) {
			$lastDate->setTime(0, 0, 0);
		}

		if ($start !== null && $lastDate > $start && !empty($tweets)) {
			$this->pass++;
			$this->logProgress();
			$tweets = array_merge($tweets, $this->query($this->query, $start, $lastDate));
		}

		return $tweets;
	}

	/**
	 * @param string $query
	 * @return array
	 * @throws GuzzleException
	 * @throws Exception
	 */
	protected function queryInterval(string $query)
	{

		$tweets = [];
		$oldestTweetDate = null;

		$firstPage = true;
		$hasAnotherPage = true;
		$currentPosition = null;
		$failState = false;
		$requestUrl = "https://twitter.com/search?f=tweets&vertical=default&q={$query}&src=typd&qf=off";
		do {

			if ($firstPage) {
				$retries = 0;
				do {
					$this->logTry($retries, 'FIRST PAGE');
					$crawler = $this->client->request('GET', $requestUrl, $this->options);
					$retries++;
				} while (
					$crawler->filter('.SearchEmptyTimeline')->count() === 0
					&& $crawler->filter('div.tweet')->count() === 0
					&& $retries < 6
					&& sleep($retries) === 0
				);

				if ($crawler->filter('.SearchEmptyTimeline')->count() !== 0) {
					$hasAnotherPage = false;
				}

			} else {
				$result = null;
				$retries = 0;
				do {
					$this->logTry($retries, 'NEXT PAGE');
					$result = $this->client->getClient()->request('GET', $requestUrl, $this->options)->getBody()->getContents();
					$retries++;
				} while (
					empty($result)
					&& $retries < 6
					&& sleep($retries) === 0
				);

				if (empty($result)) {
					if ($failState) {
						throw new Exception('Cannot exit from the current failstate.');
					}

					$this->setOptions();
					$failState = true;
					continue;
				}

				$json = json_decode($result);
				$hasAnotherPage = $json->has_more_items;
				$currentPosition = $json->min_position;
				$crawler = new Crawler($json->items_html);
			}


			$crawler->filter('div.tweet')->each(function (Crawler $tweet) use (&$tweets, &$oldestTweetDate) {
				if ($tweet->filter('.Tombstone')->count() !== 0) {
					return;
				}
				$tid = $tweet->attr('data-tweet-id');
				$url = 'https://twitter.com' . $tweet->attr('data-permalink-path');
				$userId = $tweet->filter('.account-group')->first()->attr('data-user-id');
				$username = $tweet->filter('.username')->first()->text();
				$likes = (int)$tweet->filter('.ProfileTweet-action--favorite > .ProfileTweet-actionButton > .ProfileTweet-actionCount > span.ProfileTweet-actionCountForPresentation')->first()->text();
				$retweets = (int)$tweet->filter('.ProfileTweet-action--retweet > .ProfileTweet-actionButton > .ProfileTweet-actionCount > span.ProfileTweet-actionCountForPresentation')->first()->text();
				$replies = (int)$tweet->filter('.ProfileTweet-action--reply > .ProfileTweet-actionButton > .ProfileTweet-actionCount > span.ProfileTweet-actionCountForPresentation')->first()->text();
				$text = $tweet->filter('.tweet-text')->first()->text();

				$date = new DateTime();
				$date->setTimestamp((int)$tweet->filter('._timestamp')->first()->attr('data-time'));

				$hashtags = [];
				$tweet->filter('.twitter-hashtag')->each(function (Crawler $hashtagNode) use (&$hashtags) {
					$hashtags[] = $hashtagNode->text();
				});

				$images = [];
				$tweet->filter('.AdaptiveMedia-photoContainer')->each(function (Crawler $imagesNode) use (&$images) {
					$images[] = $imagesNode->attr('data-image-url');
				});

				$mentions = [];
				$tweet->filter('.twitter-atreply')->each(function (Crawler $mentionsNode) use (&$mentions) {
					$mentions[$mentionsNode->attr('data-mentioned-user-id')] = $mentionsNode->text();
				});

				$replying = [];
				$tweet->filter('.ReplyingToContextBelowAuthor > .pretty-link')->each(function (Crawler $replyingNode) use (&$replying) {
					$replying[$replyingNode->attr('data-user-id')] = $replyingNode->text();
				});

				if (!array_key_exists($tid, $tweets)) {
					$this->fetchedTweets++;


					$tweets[$tid] = [
						'tweetId' => $tid,
						'url' => $url,
						'text' => $text,
						'datetime' => $date,
						'user_id' => $userId,
						'user_name' => $username,
						'retweets' => $retweets,
						'replies' => $replies,
						'likes' => $likes,
						'hashtags' => $hashtags,
						'mentions' => $mentions,
						'images' => $images,
						'replying' => $replying,
					];
				}

				$oldestTweetDate = $date;
			});

			if ($firstPage && $crawler->filter('#timeline > div')->count() !== 0) {
				$currentPosition = $crawler->filter('#timeline > div')->first()->attr('data-min-position');
			}

			$this->logProgress();

			$firstPage = false;
			$requestUrl = "https://twitter.com/i/search/timeline?f=tweets&vertical=default&include_available_features=1&include_entities=1&reset_error_state=false&src=typd&max_position={$currentPosition}&q={$query}&l=en";
		} while ($hasAnotherPage);

		return [$tweets, $oldestTweetDate];
	}

	protected function logProgress()
	{
		echo sprintf('[PASS=%s][TWEETS=%s]' . PHP_EOL, $this->pass, $this->fetchedTweets);
	}

	protected function logTry($try, $message)
	{
		echo sprintf('[TRY=%s][%s]', $try, $message);
	}

	/**
	 * @param array $tweets
	 * @param bool $partials
	 */
	protected function save(array $tweets, bool $partials)
	{
		if ((!$partials && $this->pathToFile !== null) || ($this->pathToFile !== null && $this->saveClosure === null)) {
			file_put_contents($this->pathToFile, json_encode(array_values($tweets)));
		}

		if ($this->saveClosure !== null && ($this->saveEveryPass && $partials)) {
			$closure = $this->saveClosure;

			$closure(array_values($tweets));
		}
	}
}