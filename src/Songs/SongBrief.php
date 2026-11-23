<?php

declare(strict_types=1);

namespace App\Songs;

use App\Episode\Source;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A themed episode: a list of KPA songs the two hosts introduce, play and explain,
 * one after the other.
 */
final readonly class SongBrief
{
    /**
     * @param list<SongCard> $songs   in the order they are played
     * @param list<Source>   $sources background that is not about one song (Kid Pan Alley itself)
     * @param list<string>   $hosts   ids of the two hosts, none for the songbook's usual duo
     */
    public function __construct(
        #[Assert\NotBlank] #[Assert\Length(max: 200)] public string $theme,
        #[Assert\Count(min: 2, max: 5)] #[Assert\Valid] public array $songs,
        #[Assert\Valid] public array $sources = [],
        // minutes of talk, the excerpts come on top
        #[Assert\Range(min: 1, max: 5)] public int $minutes = 2,
        #[Assert\Length(max: 100)] public ?string $tone = null,
        public array $hosts = [],
    ) {
    }

    /**
     * The numbered sources the writer cites: one per song first, in order, then the others.
     *
     * @return list<Source>
     */
    public function allSources(): array
    {
        $sources = [];
        foreach ($this->songs as $song) {
            $sources[] = new Source($song->title, $song->facts, $song->recording);
        }

        return [...$sources, ...$this->sources];
    }
}
