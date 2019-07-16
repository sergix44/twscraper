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
	->setLang('it') // optional
	->setChunkSize(100) // optional
	->save(function(array $tweets) { ... }, true) // optional
	->run();
	
$tweets = $scraper->getTweets();
```
