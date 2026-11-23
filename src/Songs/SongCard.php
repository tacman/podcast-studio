<?php

declare(strict_types=1);

namespace App\Songs;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One song of a themed episode, as the KPA archive knows it: the facts the hosts
 * may say about it, and the part of a recording played on air.
 */
final readonly class SongCard
{
    /**
     * @param string $kpaId         the song's id in the KPA catalog (Song::$id)
     * @param string $facts         what the hosts may say about the song: its only source
     * @param string $recording     public URL of the recording the excerpt is cut from
     * @param string $performance   what the recording is, e.g. "guitar and vocal demo"
     * @param string $excerptLyrics the words sung in the excerpt, from the catalog's lyrics
     */
    public function __construct(
        #[Assert\NotBlank] public string $kpaId,
        #[Assert\NotBlank] public string $title,
        #[Assert\NotBlank] public string $facts,
        #[Assert\Url(protocols: ['https'], requireTld: true)] public string $recording,
        #[Assert\NotBlank] public string $performance,
        #[Assert\PositiveOrZero] public float $excerptStart,
        #[Assert\GreaterThan(propertyPath: 'excerptStart')] public float $excerptEnd,
        public string $excerptLyrics = '',
    ) {
    }

    public function excerptLength(): float
    {
        return $this->excerptEnd - $this->excerptStart;
    }

    /**
     * e.g. "0:11.5 → 0:31.0".
     */
    public function excerptAt(): string
    {
        return \sprintf('%s → %s', self::at($this->excerptStart), self::at($this->excerptEnd));
    }

    private static function at(float $seconds): string
    {
        return \sprintf('%d:%04.1f', intdiv((int) $seconds, 60), fmod($seconds, 60));
    }
}
