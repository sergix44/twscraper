<?php

namespace SergiX44\Twitter;

use DateTime;
use JsonSerializable;

class Tweet implements JsonSerializable
{

    public int $id;
    public string $url;
    public string $text;
    public DateTime $date;
    public int $user_id;
    public string $user_name;
    public string $user_fullname;
    public int $user_followers;
    public int $user_following;
    public int $retweets;
    public int $replies;
    public int $likes;
    public array $hashtags;
    public array $mentions;
    public array $images;
    public array $reply_to;
    public string $lang;

    /**
     * @param  int  $id
     * @param  string  $url
     * @param  string  $text
     * @param  DateTime  $dateTime
     * @param  int  $user_id
     * @param  string  $user_name
     * @param  string  $user_fullname
     * @param  int  $user_followers
     * @param  int  $user_following
     * @param  int  $retweets
     * @param  int  $replies
     * @param  int  $likes
     * @param  array  $hashtags
     * @param  array  $mentions
     * @param  array  $images
     * @param  array  $reply_to
     * @param  string  $lang
     */
    public function __construct(
        int $id,
        string $url,
        string $text,
        DateTime $dateTime,
        int $user_id,
        string $user_name,
        string $user_fullname,
        int $user_followers,
        int $user_following,
        int $retweets,
        int $replies,
        int $likes,
        array $hashtags,
        array $mentions,
        array $images,
        array $reply_to,
        string $lang
    ) {
        $this->id = $id;
        $this->url = $url;
        $this->text = $text;
        $this->date = $dateTime;
        $this->user_id = $user_id;
        $this->user_name = $user_name;
        $this->user_fullname = $user_fullname;
        $this->user_followers = $user_followers;
        $this->user_following = $user_following;
        $this->retweets = $retweets;
        $this->replies = $replies;
        $this->likes = $likes;
        $this->hashtags = $hashtags;
        $this->mentions = $mentions;
        $this->images = $images;
        $this->reply_to = $reply_to;
        $this->lang = $lang;
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}