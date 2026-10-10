# Content checks

Every pull request to `master` gets an automatic check of the page text it adds or changes.
Old content is never checked, so it can't block a PR.

It looks for:

- **Words that aren't in the dictionary**, e.g. `Wedneaday`, `ogranizers` (using [cspell](https://cspell.org)
  plus the site's own word list, [`words.txt`](words.txt))
- **Wrong or misspelled weekdays** next to a date, e.g. `26 Jan 2022 (Monday)` when that day was a Wednesday

Only visible text is checked. HTML tags, scripts, styles and Chinese characters are ignored.

## When it finds something

A bot comments on the PR with a list of the problems. For each word:

- **It's a typo:** fix it in the HTML. For obvious typos and wrong weekdays the bot also puts a
  suggestion on the line in **Files changed**. Click **Commit suggestion** to apply it.
- **It's spelled correctly** (a name or abbreviation, e.g. a new speaker): tick its box in the bot's
  comment. The bot adds it to `words.txt` on your branch and re-runs the check.

The bot can't tell a typo from a name, so it never adds words on its own: only tick words you've checked.

PRs from forks only get the ❌ and the inline notes, not the bot's comment or one-click fixes.

## Run it on your computer

Needs Python 3 and Node.js.

```bash
python3 .github/content-checks/check_content.py --base master        # lines changed vs master
python3 .github/content-checks/check_content.py --all                # every page on the site
python3 .github/content-checks/check_content.py --add-words Zhixue   # add correct words to words.txt
```

## How it works

| File | Role |
|---|---|
| `.github/workflows/content-checks.yml` | Runs the check on each PR (and when the bot re-checks after adding words) |
| `.github/workflows/content-check-words.yml` | Reacts to ticked boxes in the bot's comment |
| `check_content.py` | The checker |
| `github_bot.py` | Posts the PR comment and suggestions, and adds ticked words |
| `words.txt` | Correct words the dictionary doesn't know |
| `cspell.json` | Spell checker settings |
