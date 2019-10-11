## TW Scraper: A simple PHP Twitter search scraper.

Installation:
```
composer require sergix44/twscraper
```

Example usage:
```php
use SergiX44\Scraper\TwitterScraper;

$scraper = TwitterScraper::make()
	->search('near:Verona within:2km filter:images filter:hashtags', new DateTime('2019-06-15'), new DateTime('2019-07-22'))
	->setLang('en')// optional
	->save(function ($tweets, $totalTweets) { // optional
		//...
	}, true) // call gc?
	->setChunkSize(100)// optional
	->run();

$tweets = $scraper->getTweets();
```
WARNING: if the GC call is set to true, the method getTweets will return only the latest chunk of tweets!
