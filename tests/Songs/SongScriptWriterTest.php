<?php

declare(strict_types=1);

namespace App\Tests\Songs;

use App\Episode\Line;
use App\Episode\ScriptChecker;
use App\Episode\Source;
use App\Songs\SongBrief;
use App\Songs\SongCard;
use App\Songs\SongScript;
use App\Songs\SongScriptWriter;
use App\Songs\SongSegment;
use App\Tests\Episode\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Validator\Validation;

final class SongScriptWriterTest extends TestCase
{
    private const WORDS = 'with enough spoken words to make this line count towards the length';

    public function testAcceptsOneSegmentPerSongInOrder(): void
    {
        self::assertSame([], $this->writer()->check($this->script([1, 2]), self::brief()));
    }

    public function testRejectsSongsOutOfOrderOrMissing(): void
    {
        $problems = $this->writer()->check($this->script([2]), self::brief());

        self::assertStringContainsString('1 song segments, the episode has 2 songs', $problems[0]);
        self::assertStringContainsString('Segment 1 is about song 2', $problems[1]);
    }

    public function testRejectsASegmentThatCitesNothingFromItsSong(): void
    {
        $script = $this->script([1, 2]);
        $script = new SongScript($script->title, $script->opening, [
            $script->segments[0],
            new SongSegment(2, [new Line('paul', 'excited', 'Next one '.self::WORDS.'.', [])], [new Line('jane', 'neutral', 'Nothing about the song '.self::WORDS.'.', [1])]),
        ], $script->closing, $script->summary);

        self::assertContains('Segment 2 gives no fact from the source of its song (source 2).', $this->writer()->check($script, self::brief()));
    }

    public function testFlattensToAScriptWithAChapterPerSong(): void
    {
        $script = $this->script([1, 2])->toScript(self::brief());

        self::assertSame(['Welcome', 'Song A', 'Song B'], array_values(array_filter(array_map(static fn (Line $line) => $line->chapter, $script->lines))));
    }

    public function testExcerptTimes(): void
    {
        $song = self::brief()->songs[0];

        self::assertSame('0:11.5 → 0:31.0', $song->excerptAt());
        self::assertSame(19.5, $song->excerptLength());
    }

    private function writer(): SongScriptWriter
    {
        return new SongScriptWriter(self::createStub(PlatformInterface::class), Fixtures::show(), new ScriptChecker(Fixtures::show()), Validation::createValidator(), 'model', __FILE__);
    }

    /**
     * @param list<int> $songs
     */
    private function script(array $songs): SongScript
    {
        $segments = array_map(static fn (int $n) => new SongSegment(
            $n,
            [new Line('paul', 'excited', "Song {$n} comes next ".self::WORDS.'.', [$n])],
            [new Line('jane', 'neutral', "That was song {$n} ".self::WORDS.'.', [$n]), new Line('paul', 'neutral', 'And now '.self::WORDS.'.', [])],
        ), $songs);

        return new SongScript(
            'Two songs',
            [new Line('paul', 'excited', 'Welcome to two songs '.self::WORDS.'.', []), new Line('jane', 'neutral', 'Two of them '.self::WORDS.'.', [3])],
            $segments,
            [new Line('jane', 'neutral', 'Goodbye '.self::WORDS.'.', [])],
            'Two songs, two schools.',
        );
    }

    private static function brief(): SongBrief
    {
        $song = static fn (string $title, float $start, float $end) => new SongCard(strtolower(str_replace(' ', '-', $title)), $title, "Facts about {$title}.", 'https://kpa-songs.s3.amazonaws.com/a.mp3', 'guitar and vocal version', $start, $end);

        return new SongBrief('Two songs', [$song('Song A', 11.5, 31.0), $song('Song B', 7.5, 25.5)], [new Source('Kid Pan Alley', 'Songs written with children.')], 1);
    }
}
