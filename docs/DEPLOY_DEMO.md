# Hosting a free public demo (Render)

This is for a showcase link you can put in an Upwork portfolio or proposal. It is not a production setup.

## What the public demo does

Set `DOCAI_PUBLIC_DEMO=true` (the Docker image does this) and the app changes:

- The login page shows one **Enter the demo workspace** button. It signs in a seeded *reviewer*, never an admin. Password login is disabled, so the seeded `password` accounts are not exposed.
- The rule-based demo parser is forced. No OpenAI or Anthropic key is read or used, so nobody can spend your credits.
- Uploads are capped at 3 PDFs of 2 MB each.
- A banner reminds visitors to use synthetic data only.
- `php artisan docai:demo-reset` wipes the database and uploaded files and re-seeds. It runs on every container start and daily at 03:00 (through `schedule:work`). It refuses to run unless the demo flag is on, or you pass `--force`.

## Deploy

1. Push the project to a GitHub repository. `.env` is git-ignored; check that no key is committed.
2. On render.com choose **New → Blueprint** (or **Web Service → Docker**) and select the repository. `render.yaml` selects the free plan.
3. Do **not** add `DOCAI_OPENAI_API_KEY` or `DOCAI_ANTHROPIC_API_KEY`.
4. When the build finishes, open the `onrender.com` URL.

`APP_KEY` is generated at start and `APP_URL` is taken from `RENDER_EXTERNAL_URL`.

## Honest limits

- The free plan sleeps after about 15 minutes idle; the next visit takes around a minute. Say so in your proposal.
- Storage is ephemeral: SQLite and uploads are lost on every restart. That is intentional for a demo, since it resets anyway.
- It runs `php artisan serve` (single process). Fine for a client clicking around, not for load.
- **Verified on Render (free plan, 2026-10-29).** The image built and the demo ran: one-click login, dashboard, document review pages, PDF and CSV/JSON exports. The first deploy returned a 500 on the dashboard because the `intl` PHP extension was missing from the image (Laravel's currency formatter needs it); it is now installed. No Docker on the build machine, so the image was built by Render, not locally. Free instances sleep after about 15 minutes and take around a minute to wake.

## Alternative without hosting

Run it locally and expose it for a call with a free tunnel (Cloudflare Tunnel or ngrok). Keep `DOCAI_PUBLIC_DEMO=true` while it is public.

## Upwork rules

Put the link in your proposal or portfolio item. Do not put your personal email, phone or messaging handle in a proposal before a contract starts.
