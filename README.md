## TW Scraper: A simple PHP Twitter search scraper.

Installation:
```
composer require sergix44/twscraper
```

Example usage:
```php
use SergiX44\Scraper\TwitterScraper;

$scraper = TwitterScraper::make()
	->search('near:"Verona, Veneto" within:5km', new DateTime('2019-03-01'), new DateTime())
	->setSaveFile('tweets.json') // optional
	->onSave(function(array $tweets) { ... }) // optional
	->saveEveryPass() // optional
	->setLang('it') // optional
	->run();
	
$tweets = $scraper->getTweets();
```

If both `->saveEveryPass()` and `->onSave` are set, the tweets received by the callback will be incremental.