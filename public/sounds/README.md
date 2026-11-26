# Sound placeholders

R10.2 — three slots, to be replaced with the real effects later:

| File | Slot | Notes |
|---|---|---|
| `game-loop.mp3` | in-game loop | plays from the countdown until the run ends; loops, volume 0.35 |
| `fail.mp3` | fail | one shot, stops the loop first |
| `win.mp3` | win | one shot, stops the loop first |

Drop the files in beside this README, keeping the names. Nothing else needs changing: the paths are
in `config/quiz.php` and mirrored in `resources/js/composables/useSound.ts`.

## Why `public/` and not `resources/sounds/`

The spec names `resources/sounds/{game-loop,fail,win}.*`. Files under `resources/` are bundled by
Vite, which **fails the build** when an imported asset is missing — so the placeholder slots could
not exist as placeholders. Served from `public/` a missing file is a silent 404 and the sound simply
does not play, which is the right behaviour while the real effects are outstanding.

Move them to `resources/sounds/` and import them properly once they are real, if you want the
cache-busting hashes.

## Format

`.mp3` is assumed. Every current browser plays it, and it avoids shipping two encodings. If you have
`.ogg` instead, change the three paths in `config/quiz.php` and the `SOURCES` map in
`useSound.ts` — they are the only two places.

Keep them short (the one-shots under a second) and quiet: this is a timed game, and a loud sting
over a 2-second timer is punishing rather than fun.
