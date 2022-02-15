## TW Scraper: A simple PHP Twitter search scraper.

Installation:
```
composer require sergix44/twscraper
```

Example:
```php
use SergiX44\Twitter\TwitterScraper;

$scraper = TwitterScraper::make()
	->search('near:Verona within:2km filter:images filter:hashtags', new DateTime('2019-06-15'), new DateTime('2019-07-22'))
	->setLang('en')// optional
	/** @var Tweet[] $tweets */
	->onChunk(function (array $tweets, int $totalTweets) { // optional
		//...
	}, true) // than flush? (useflul when processing lot of tweets and avoid memory exhausted errors)
	->setChunkSize(100)// optional
	->run();
	
/** @var Tweet[] $tweets */
$tweets = $scraper->getTweets();
```
WARNING: if the flush is set to true, the method getTweets will return only the latest chunk of tweets!
