# Themed episodes of Kid Pan Alley songs (prototype)

> **Moved to KPA** (`survos/kpa`, branch `feature/themed-episodes`: `docs/themed-episodes.md`, `src/Episode/`), where the songs, lyrics and recordings are. This copy is the first prototype and is no longer updated.

A countdown-style episode: two synthetic hosts, PaulBot and CherylBot, introduce a song from the Kid Pan Alley (KPA) catalog, play a short excerpt, give its background, and move on to the next song. One theme per episode: holiday songs, patriotic songs, songs about animals, and so on.

Status: the script is generated end to end (`app:song-episode`), with each excerpt placed and timed. Audio assembly (voices plus excerpts in one MP3) is not built yet. The first script: [holidays-script.md](holidays-script.md) (3 songs, about 2 minutes of talk plus 60 seconds of music).

```bash
bin/console app:song-episode fixtures/briefs/kpa-holidays.json --output=docs/kpa/holidays-script.md
```

## Segment structure

```
Opening       2–4 lines   hook, theme, how many songs
Song n        intro       2–4 lines: song, school, grade; the last line hands over ("here is the chorus")
              EXCERPT     15–25 s of the recording, fade in 0.5 s, fade out 1.5 s
              background  2–5 lines: reaction, then the story of the song from its card; the last line leads on
… one segment per song, in the order of the brief …
Closing       1–3 lines   sign-off
```

The excerpt's place is part of the structure, not a marker the model has to remember. The writer returns a `SongScript` (`src/Songs/`): `opening`, then `segments[]`, each with `song`, `intro[]` and `background[]`, then `closing`. The excerpt goes between `intro` and `background`. Everything else reuses the show:

- `SongScript::toScript()` flattens the talk into a plain `Script`, one chapter per song, so the existing `ScriptChecker` runs unchanged: known hosts and emotions, cited sources that exist, no markup, no "today", length, both hosts speak.
- `SongScriptWriter::check()` adds the running-order rules: one segment per song, in order, lines on both sides of each excerpt, and each segment citing its own song's source at least once.
- On problems, the writer is asked once more with the list, as `ScriptWriter` does.

Timing budget: about 25 seconds of talk and 20 seconds of music per song.

| Songs | Talk | Music | Episode |
| --- | --- | --- | --- |
| 3 | ~2 min | ~1 min | ~3 min |
| 10 ("top ten") | ~4–5 min | ~3.5 min | ~8 min |

For ten songs, three of the show's limits have to be lifted for this format: `ScriptChecker::MAX_CHAPTERS` (6), `Brief` minutes (max 5) and `SongBrief` songs (max 5). That means a format-specific checker setting, not a change to the general one.

The hosts are a separate show, `app.kpa_show` in `config/services.yaml`: "The Kid Pan Alley Songbook", cast `[paulbot, cherylbot]`. The hackathon show, its six hosts, its cassettes and its tests are untouched.

## Where each song's facts come from

The writer only uses the brief. Each song arrives as a `SongCard`: its facts as plain text, the recording URL and the excerpt. Like the rest of the studio, the writer gets the text, not a model's summary of it. Everything in the holiday brief came from the KPA app (`~/sites/kpa`, Postgres `kidpanalley` on port 5434):

| Fact | Where in KPA |
| --- | --- |
| Title, school, year, catalog date | `song.title`, `song.school`, `song.year`, `song.date` |
| Writers | `song.writers` |
| Class, grade and co-writers | the ChordPro `{subtitle:}` in `song.lyrics`, e.g. "Paul Reisler, Paddy Dougherty, and Grymes Memorial School 4th graders" |
| Publisher | `song.publisher` (`notes.iswc` repeats it) |
| What the song is about, the excerpt's words | `song.lyrics` (ChordPro, chorus marked `{start_of_chorus}`) |
| Recording, its kind, its context | `audio` → `file_asset.s3` (public URL), `audio.variant`, and the archive folder in `file_asset.path`, e.g. "Christmas Album - 2023/Possible Songs" or "GRYMES 2024/recording concert" |
| Kid Pan Alley itself | the archive homepage text: "brings children and professional songwriters together to create songs collaboratively" |

Rules the prompt (`config/prompts/songs.md`) adds for this catalog:

- No children's names, no head counts, and no anecdotes from the writing session unless the card has them. Cards leave out teachers' names, even when the subtitle credits them.
- The hosts are not the songwriters. They talk about Paul Reisler or Cheryl Toth in the third person.
- No more than one line of lyrics is quoted on air. The excerpt carries the song.

Catalog details to settle before scaling up:

- **"K.P. Alley"** appears in `song.writers` on 1,479 songs; it looks like a house credit. Given as is, the writer read it out ("Paul Reisler, K.P. Alley and Lea Morris wrote it"). Either explain it on the card or leave it out.
- **The credits don't always agree.** For the Savoy song, `writers` lists Paul Reisler, K.P. Alley and Sahffi Lynne, while the subtitle names Cheryl Toth too. The card used the subtitle. Which one wins?
- **`song.year` and the subtitle can differ** (Grymes: year 2025, subtitle 2024). The card says "the 2024 residency, catalog date 1 March 2025".

### Choosing the songs for a theme

The KPA catalog has 2,560 songs, 1,229 recordings across 849 of them, and a 768-dimension embedding per song (Ollama `nomic-embed-text`) in Elasticsearch (`kpa_song_*`). That is the right tool for a theme ("songs about winter holidays" should find "Frosty's Cavern" without the word "holiday"). However, KPA's README pauses semantic search and embedding generation (`KPA_AI_ENABLED=0`) until the raw catalog is verified. So this prototype picked the songs by keyword, read-only:

```sql
select s.id, s.title, s.school, count(a.id)
from song s join audio a on a.song_id = s.id
where s.title ~* 'christmas|holiday|hanukkah|winter|snow|thanksgiving|kwanzaa|thank'
   or s.lyrics ~* 'christmas|hanukkah|thanksgiving|kwanzaa'
group by s.id;
```

That returned 36 candidate songs with audio. The pick: one song each for Thanksgiving, Christmas and the end of winter, each from a different school and grade. Each one has the lyrics and a human recording.

Next step: `app:kpa-brief "<theme>" --songs=10`. It would query KPA (hybrid search once AI is back on), keep songs that have lyrics and a usable recording, and write the cards and the brief. Editors would review the list before the writer runs, as they review the sources today.

**Skip AI-generated recordings.** The archive holds Suno arrangements next to the real ones: "recording Suno/…", "AI recordings of KPA:PR songs/…", and files marked `(s)`. They are useless for "here are the kids' songs". The brief builder should exclude them unless the episode is about them.

## How excerpts are chosen and mixed in

**Which recording.** In order of preference: `gv` (guitar and vocal, the clearest words), then a studio mix, then `gtr`, then `live` (concert recordings; lovely, but often rough). Never `Suno` or AI arrangements, as above.

**Which part.** The first full chorus: it names the song, and the catalog marks it with `{start_of_chorus}`. Length is 15 to 25 seconds, cut on a phrase end, with a 0.5 s fade-in and a 1.5 s fade-out.

**Finding the timestamps.** The catalog lyrics say which words; Whisper says when. VoiceStudio's `/v1/audio/transcriptions` (`whisper-1`, at `127.0.0.1:3900`) worked like this:

1. A single pass over the whole song **does not work over music**. It returned one segment for the 3-minute Thanksgiving mix and nothing at all for the 79-second Christmas demo. That looks like voice-activity filtering.
2. Overlapping 12-second chunks every 10 seconds, then 4-second chunks every 2 seconds around the first chorus, do work: each chunk's text locates a lyric line to about ±1 s.
3. Whisper invents lines over instrumental bars: "Thank you." and "We'll see you next time." both appeared. Only a chunk that matches the catalog lyrics counts.

The holiday excerpts:

| Song | Recording | Excerpt |
| --- | --- | --- |
| Every Day Should Be A Thanksgiving | `every-day-should-be-a-thanksgiving-recording.mp3` (studio mix, 3:11) | 0:11.5 → 0:31.0 |
| Going To My Cousin's For Christmas | `going-to-my-cousins-for-christmas-gv.mp3` (gv, 1:19) | 0:07.5 → 0:25.5 |
| Waving Goodbye to Winter | `waving-goodbye-to-winter-gv.mp3` (gv, 2:22) | 0:06.0 → 0:28.5 |

Cut with:

```bash
ffmpeg -ss 11.5 -t 19.5 -i every-day-should-be-a-thanksgiving-recording.mp3 \
  -af "afade=t=in:d=0.5,afade=t=out:st=18:d=1.5" -b:a 128k x1-thanksgiving.mp3
```

Automating this is the next piece: a `ChorusFinder` that transcribes in chunks, matches the chunks to the `{start_of_chorus}` lines with fuzzy matching, and proposes start and end times. The editor would hear the proposal before the episode is voiced. The excerpt should stay on the card as data (`excerptStart`, `excerptEnd`) so an editor can nudge it.

**Mixing into the episode** (not built). `Mp3Joiner` joins MP3 frames in PHP, without ffmpeg, so every clip must share one format. So:

- Cut each excerpt once, when the brief is prepared, and re-encode it to the voice clips' sample rate and channels. Normalize its loudness to the voices (about −16 LUFS) so the music doesn't jump out. Store it with the episode (`var/episodes/<id>/excerpts/`).
- At publish time, build the running order (lines and excerpts) and voice only the lines. Join everything in order with a short pause on each side of an excerpt.
- The timings then drive the chapters, one per song starting at its intro. The VTT transcript shows each excerpt as a cue (`[Music: chorus of "Waving Goodbye to Winter"]`). The show notes credit the writers and the school for each excerpt.

## Lyrics for songs that only have audio

The catalog has 403 songs without lyrics. Whisper through VoiceStudio is a reasonable first draft only for the clearest `gv` recordings, and only in chunks (see above). On a mix with drums or a choir it drops and invents words. Its output would be a draft for a human to correct, never a source the hosts quote. Separating the vocals first (for example with Demucs) would likely help a lot; it has not been tried.

## Voices

PaulBot and CherylBot are synthetic hosts "inspired by" the duo, with personas that describe the bots' roles, not the real people. For now they use Voxtral stock voices (`en_paul_*` and `gb_jane_*`; `en_paul` is Mistral's own stock voice, not a clone of anyone). The local path is VoiceStudio through the Generic bridge text-to-speech PR (`tacman:generic-text-to-speech`): it would only change the voice ids in `app.kpa_show`. **No real person's voice is cloned.** Voices modeled on Paul Reisler and Cheryl Toth wait for their explicit consent, which Tac is arranging.

## Open questions

1. What is "K.P. Alley" in `writers`, and which credit wins when `writers` and the subtitle disagree?
2. Is it fine to name the teacher when the subtitle credits them, or only the school and the grade?
3. May episodes use any recording on the public S3 bucket, or only the ones cleared for release (for example the "Latest Mixes" and album folders)?
4. Should the brief builder query KPA's database directly, or through its API Platform endpoints?
