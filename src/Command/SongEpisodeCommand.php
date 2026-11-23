<?php

declare(strict_types=1);

namespace App\Command;

use App\Episode\Line;
use App\Show\Show;
use App\Songs\SongBrief;
use App\Songs\SongScript;
use App\Songs\SongScriptWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

#[AsCommand('app:song-episode', 'Prototype: write a themed episode of Kid Pan Alley songs, with its excerpts, as a running order')]
final readonly class SongEpisodeCommand
{
    public function __construct(
        private SerializerInterface $serializer,
        private Filesystem $filesystem,
        private SongScriptWriter $writer,
        #[Autowire(service: 'app.kpa_show')] private Show $show,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path to a song brief JSON file')] string $brief,
        #[Option('Where to write the running order (Markdown) and, next to it, the script (JSON)')] ?string $output = null,
    ): int {
        $brief = $this->serializer->deserialize($this->filesystem->readFile($brief), SongBrief::class, JsonEncoder::FORMAT);
        $script = $this->writer->write($brief);
        $markdown = self::runningOrder($script, $brief, $this->show);

        if (null === $output) {
            $io->writeln($markdown);
        } else {
            $this->filesystem->dumpFile($output, $markdown);
            $this->filesystem->dumpFile(preg_replace('/\.md$/', '', $output).'.json', (string) json_encode($script, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
            $io->success("Running order written to {$output}");
        }

        return Command::SUCCESS;
    }

    public static function runningOrder(SongScript $script, SongBrief $brief, Show $show): string
    {
        $sources = $brief->allSources();
        $line = static fn (Line $line) => \sprintf("**%s** _(%s)_%s: %s\n\n", $show->hosts[$line->host]->name ?? $line->host, $line->emotion, [] === $line->sources ? '' : ' ['.implode(', ', $line->sources).']', $line->text);

        $md = "# {$script->title}\n\n> {$script->summary}\n\n## Opening\n\n";
        foreach ($script->opening as $l) {
            $md .= $line($l);
        }
        foreach ($script->segments as $segment) {
            $song = $brief->songs[$segment->song - 1];
            $md .= "## {$segment->song}. {$song->title}\n\n";
            foreach ($segment->intro as $l) {
                $md .= $line($l);
            }
            $md .= \sprintf("> ▶ **EXCERPT** `%s` %s (%.1f s), %s, fade in 0.5 s, out 1.5 s  \n> `%s`%s\n\n", basename($song->recording), $song->excerptAt(), $song->excerptLength(), $song->performance, $song->recording, '' === $song->excerptLyrics ? '' : "  \n> _{$song->excerptLyrics}_");
            foreach ($segment->background as $l) {
                $md .= $line($l);
            }
        }
        $md .= "## Closing\n\n";
        foreach ($script->closing as $l) {
            $md .= $line($l);
        }
        $md .= "## Sources\n\n";
        foreach ($sources as $i => $source) {
            $md .= \sprintf("%d. %s%s\n", $i + 1, $source->title, $source->url ? " ({$source->url})" : '');
        }

        return $md;
    }
}
