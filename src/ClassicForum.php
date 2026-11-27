<?php

namespace SocialBored;

/**
 * Classic forum listing.
 *
 * The core `fetch_threads()` now enforces thread and profile visibility itself
 * and asks this plugin (through the follow-graph hook) only for the
 * "followers" case. The plugin therefore no longer needs its own duplicate
 * privacy query for the classic listing.
 */
class ClassicForum
{
    /**
     * @return array{threads:array<int,array>,total:int,pages:int,page:int,sort:string}
     */
    public static function fetch(array $opts = []): array
    {
        return \fetch_threads($opts);
    }
}
