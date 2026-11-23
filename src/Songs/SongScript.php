<?php

declare(strict_types=1);

namespace App\Songs;

use App\Episode\Line;
use App\Episode\Script;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * What the writer returns for a themed episode: the talk, segment by segment. Where
 * the excerpts go is the structure itself, not something the writer has to remember.
 */
final readonly class SongScript
{
    /**
     * @param list<Line>        $opening
     * @param list<SongSegment> $segments
     * @param list<Line>        $closing
     */
    public function __construct(
        public string $title,
        #[Schema(description: 'Two to four lines before the first song: a hook and the theme')]
        public array $opening,
        #[Schema(description: 'One segment per song, in the order of the user message')]
        public array $segments,
        #[Schema(description: 'One to three lines after the last song: a short sign-off')]
        public array $closing,
        #[Schema(description: 'Two sentences for the show notes: the theme and the songs, only facts from the sources')]
        public string $summary = '',
    ) {
    }

    /**
     * The same episode as a plain script, a chapter per song, for the checks, the
     * transcript and the voices; excerpts are not lines.
     */
    public function toScript(SongBrief $brief): Script
    {
        $lines = [];
        foreach ($this->opening as $i => $line) {
            $lines[] = self::chapter($line, 0 === $i ? 'Welcome' : null);
        }
        foreach ($this->segments as $segment) {
            foreach ([...$segment->intro, ...$segment->background] as $i => $line) {
                $lines[] = self::chapter($line, 0 === $i ? ($brief->songs[$segment->song - 1]->title ?? "Song {$segment->song}") : null);
            }
        }
        foreach ($this->closing as $line) {
            $lines[] = self::chapter($line, null);
        }

        return new Script($this->title, $lines, $this->summary);
    }

    private static function chapter(Line $line, ?string $chapter): Line
    {
        return new Line($line->host, $line->emotion, $line->text, $line->sources, $chapter);
    }
}
