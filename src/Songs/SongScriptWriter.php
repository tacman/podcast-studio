<?php

declare(strict_types=1);

namespace App\Songs;

use App\Episode\Brief;
use App\Episode\Line;
use App\Episode\ScriptChecker;
use App\Episode\ScriptWriter;
use App\Show\Show;
use InvalidArgumentException;
use RuntimeException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Writes a themed episode of KPA songs: the same rules as any episode (ScriptChecker),
 * plus the running order of the songs. Asks once more with the problems found.
 */
final readonly class SongScriptWriter
{
    /** @param non-empty-string $model */
    public function __construct(
        private PlatformInterface $platform,
        private Show $show,
        private ScriptChecker $checker,
        private ValidatorInterface $validator,
        #[Autowire(env: 'WRITER_MODEL')] private string $model,
        #[Autowire('%kernel.project_dir%/config/prompts/songs.md')] private string $promptFile,
    ) {
    }

    public function write(SongBrief $brief): SongScript
    {
        foreach ($this->validator->validate($brief) as $violation) {
            throw new InvalidArgumentException(\sprintf('The brief is not valid (%s): %s', $violation->getPropertyPath(), $violation->getMessage()));
        }

        $messages = new MessageBag(
            Message::forSystem((string) file_get_contents($this->promptFile)),
            Message::ofUser($this->request($brief)),
        );
        $script = $this->ask($messages);

        if ([] !== $problems = $this->check($script, $brief)) {
            $script = $this->ask($messages
                ->with(Message::ofAssistant((string) json_encode($script)))
                ->with(Message::ofUser("Problems found in your script:\n- ".implode("\n- ", $problems))));

            if ([] !== $problems = $this->check($script, $brief)) {
                throw new RuntimeException("The writer could not produce a valid script:\n- ".implode("\n- ", $problems));
            }
        }

        return $script;
    }

    /**
     * @return list<string> problems, empty when the script is fine
     */
    public function check(SongScript $script, SongBrief $brief): array
    {
        $problems = [];
        if (\count($script->segments) !== \count($brief->songs)) {
            $problems[] = \sprintf('The script has %d song segments, the episode has %d songs: one segment per song.', \count($script->segments), \count($brief->songs));
        }
        foreach ($script->segments as $i => $segment) {
            $n = $i + 1;
            if ($segment->song !== $n) {
                $problems[] = \sprintf('Segment %d is about song %d: play the songs in the order given, segment %d is song %d.', $n, $segment->song, $n, $n);
            }
            if ([] === $segment->intro || [] === $segment->background) {
                $problems[] = \sprintf('Segment %d needs lines both before and after its excerpt.', $n);
            }
            $cites = array_merge([], ...array_map(static fn (Line $line) => $line->sources, [...$segment->intro, ...$segment->background]));
            if (!\in_array($segment->song, $cites, true)) {
                $problems[] = \sprintf('Segment %d gives no fact from the source of its song (source %d).', $n, $segment->song);
            }
        }
        if ([] === $script->opening || [] === $script->closing) {
            $problems[] = 'The script needs an opening and a closing.';
        }

        $plain = new Brief($brief->theme, $brief->allSources(), $brief->minutes, $brief->tone, [], $brief->hosts);

        return [...$problems, ...$this->checker->check($script->toScript($brief), $plain)];
    }

    private function ask(MessageBag $messages): SongScript
    {
        return $this->platform->invoke($this->model, $messages, ['response_format' => SongScript::class, 'temperature' => 0.4])->asObject(SongScript::class);
    }

    private function request(SongBrief $brief): string
    {
        $hosts = '';
        foreach ($this->show->cast($brief->hosts) as $host) {
            $hosts .= \sprintf("- id \"%s\", %s: %s Emotions: %s.\n", $host->id, $host->name, $host->persona, implode(', ', array_keys($host->voices)));
        }

        $songs = '';
        foreach ($brief->songs as $i => $song) {
            $songs .= \sprintf("[%d] \"%s\"\n%s\nExcerpt played on air: %.0f seconds of the %s%s\n\n", $i + 1, $song->title, $song->facts, $song->excerptLength(), $song->performance, '' === $song->excerptLyrics ? '.' : ", the words sung: \"{$song->excerptLyrics}\"");
        }

        $others = '';
        foreach ($brief->sources as $i => $source) {
            $others .= \sprintf("[%d] %s\n%s\n\n", \count($brief->songs) + $i + 1, $source->title, $source->notes);
        }

        $tone = $brief->tone ?? "the show's usual tone";
        $words = $brief->minutes * ScriptWriter::WORDS_PER_MINUTE;

        return <<<MESSAGE
            Show: "{$this->show->title}", in language "{$this->show->language}".
            Hosts:
            {$hosts}
            Theme: {$brief->theme}
            Tone: {$tone}
            Length of the talk: about {$words} words in all, the excerpts come on top.

            Songs, in the order they are played (each is also the numbered source of its facts):

            {$songs}
            MESSAGE.('' === $others ? '' : "Other sources:\n\n{$others}");
    }
}
