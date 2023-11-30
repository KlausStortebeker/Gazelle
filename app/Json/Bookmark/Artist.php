<?php

namespace Gazelle\Json\Bookmark;

class Artist extends \Gazelle\Json {
    public function __construct(
        protected \Gazelle\User\Bookmark $bookmark,
    ) {}

    public function payload(): array {
        self::$db->prepared_query("
            SELECT ag.ArtistID AS artistId,
                ag.Name        AS artistName
            FROM bookmarks_artists AS ba
            INNER JOIN artists_group AS ag USING (ArtistID)
            WHERE ba.UserID = ?
            ", $this->bookmark->id()
        );
        return self::$db->to_array(false, MYSQLI_ASSOC, false);
    }
}
