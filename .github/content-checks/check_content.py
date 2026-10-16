#!/usr/bin/env python3
"""Spell-check and date-check the visible text of the site's HTML pages.

By default only the lines added since --base (default: origin/master) are checked,
so existing content never blocks a pull request. Use --all to audit every page.

Checks:
  * dates     "7th October 2026 (Wedneaday)" -> misspelled or wrong weekday, impossible date
  * spelling  via cspell (needs Node.js/npx). Correct names and abbreviations that
              cspell doesn't know go in words.txt next to this script.

Usage:
  python3 .github/content-checks/check_content.py            # lines added vs origin/master
  python3 .github/content-checks/check_content.py --base master
  python3 .github/content-checks/check_content.py --all      # whole site
  python3 .github/content-checks/check_content.py --add-words Zhixue ASTRI
"""
import argparse
import datetime
import json
import os
import re
import subprocess
import sys
import tempfile
from html.parser import HTMLParser
from pathlib import Path

HERE = Path(__file__).resolve().parent
WORDS_FILE = HERE / "words.txt"
CSPELL = "cspell@8.17.5"

# Pages that are not part of the live content: old backups and the WordPress export.
SKIP_PATHS = re.compile(r"(^|/)(backup/|wp-content/|wp-includes/|wp-json/)|backup[^/]*\.html$", re.I)

# Tags that sit inside a word, e.g. <strong>P</strong>assion, so they must not split it.
INLINE_TAGS = {"a", "abbr", "b", "bdi", "bdo", "cite", "code", "data", "dfn", "em", "font", "i",
               "kbd", "mark", "q", "s", "samp", "small", "span", "strong", "sub", "sup", "time",
               "u", "var"}
HIDDEN_TAGS = {"script", "style", "noscript", "svg"}
CJK = re.compile(r"[⺀-鿿豈-﫿＀-￯]")

MONTHS = {m.lower(): i for i, m in enumerate(
    ["January", "February", "March", "April", "May", "June", "July",
     "August", "September", "October", "November", "December"], 1)}
MONTHS.update({name[:3]: num for name, num in list(MONTHS.items())})
MONTHS["sept"] = 9
DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
DAY_ALIASES = {d.lower(): i for i, d in enumerate(DAYS)}
DAY_ALIASES.update({d[:3].lower(): i for i, d in enumerate(DAYS)})
DAY_ALIASES.update({"tues": 1, "thur": 3, "thurs": 3})
DATE = re.compile(
    r"\b(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]{3,9})\.?,?\s+(\d{4})\s*\(\s*([A-Za-z]+)\.?\s*\)")
CSPELL_LINE = re.compile(
    r"^(.+)\.txt:(\d+):\d+ - Unknown word \(([^)]+)\)(?:.*?Suggestions: \[([^\]]*)\])?")


class VisibleText(HTMLParser):
    """Collect the visible text of each source line: tags removed, entities decoded."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.lines = {}
        self.hidden = 0

    def _add(self, line, text):
        self.lines[line] = self.lines.get(line, "") + text

    def handle_starttag(self, tag, _attrs):
        if tag in HIDDEN_TAGS:
            self.hidden += 1
        elif tag not in INLINE_TAGS:
            self._add(self.getpos()[0], " ")

    def handle_endtag(self, tag):
        if tag in HIDDEN_TAGS:
            self.hidden = max(0, self.hidden - 1)
        elif tag not in INLINE_TAGS:
            self._add(self.getpos()[0], " ")

    def handle_startendtag(self, tag, _attrs):
        if tag not in INLINE_TAGS:
            self._add(self.getpos()[0], " ")

    def handle_data(self, data):
        if self.hidden:
            return
        line = self.getpos()[0]
        for offset, part in enumerate(data.split("\n")):
            self._add(line + offset, CJK.sub(" ", part))


def visible_lines(source):
    parser = VisibleText()
    parser.feed(source)
    parser.close()
    return parser.lines


def git(*args):
    return subprocess.run(["git", "-c", "core.quotePath=false", *args],
                          capture_output=True, text=True, check=True).stdout


def added_lines(base, head):
    """Map each added/modified HTML file to the line numbers added since base."""
    diff = git("diff", "--unified=0", "--no-color", "--diff-filter=AMR", f"{base}...{head}",
               "--", "*.html")
    result, path, line, previous = {}, None, 0, ""
    for row in diff.splitlines():
        if row.startswith("+++ ") and previous.startswith("--- "):
            name = row[4:].strip('"')
            path = name[2:] if name.startswith("b/") else None
        elif row.startswith("@@"):
            line = int(re.match(r"@@ -\S+ \+(\d+)", row).group(1))
        elif row.startswith("+") and path:
            result.setdefault(path, set()).add(line)
            line += 1
        previous = row
    return result


def read_source(path, head):
    if head:
        return git("show", f"{head}:{path}")
    return Path(path).read_text(encoding="utf-8", errors="replace")


def match_case(original, word):
    if original.isupper():
        return word.upper()
    if original.islower():
        return word.lower()
    if original[0].isupper() and original[1:].islower():
        return word[0].upper() + word[1:].lower()
    return word


def is_simple_fix(word, candidate):
    """True if candidate is word with one missing letter added, or two neighbours swapped."""
    w, c = word.lower(), candidate.lower()
    if len(c) == len(w) + 1:
        letters = iter(c)
        return all(ch in letters for ch in w)
    if len(c) == len(w):
        diff = [i for i in range(len(w)) if w[i] != c[i]]
        return (len(diff) == 2 and diff[1] == diff[0] + 1
                and w[diff[0]] == c[diff[1]] and w[diff[1]] == c[diff[0]])
    return False


def likely_fix(word, suggestions):
    """A fix only when it's an obvious one; ALL-CAPS words are usually abbreviations."""
    if word.isupper():
        return None
    for candidate in suggestions:
        if is_simple_fix(word, candidate):
            return match_case(word, candidate)
    return None


def check_dates(text):
    """Yield (message, stated weekday word, correct weekday word or None)."""
    for m in DATE.finditer(text):
        day, month_word, year, weekday_word = m.groups()
        month = MONTHS.get(month_word.lower())
        if month is None:
            continue
        try:
            actual = datetime.date(int(year), month, int(day)).weekday()
        except ValueError:
            yield f'"{m.group(0)}" is not a real date', weekday_word, None
            continue
        stated = DAY_ALIASES.get(weekday_word.lower())
        correct = DAYS[actual]
        if stated is not None and len(weekday_word) <= 5:
            correct = correct[:3]  # keep the short style, e.g. "(Mon)" -> "(Wed)"
        correct = match_case(weekday_word, correct)
        if stated is None:
            yield (f'unknown weekday "{weekday_word}" in "{m.group(0)}" (should be {DAYS[actual]})',
                   weekday_word, correct)
        elif stated != actual:
            yield (f'"{m.group(0)}" says {DAYS[stated]}, but that date is a {DAYS[actual]}',
                   weekday_word, correct)


def check_spelling(texts):
    """texts: {path: {line: text}} -> list of problem dicts."""
    with tempfile.TemporaryDirectory() as tmp:
        names = []
        for path, lines in texts.items():
            dest = Path(tmp) / f"{path}.txt"
            dest.parent.mkdir(parents=True, exist_ok=True)
            last = max(lines, default=0)
            dest.write_text("\n".join(lines.get(i, "") for i in range(1, last + 1)),
                            encoding="utf-8")
            names.append(f"{path}.txt")
        try:
            run = subprocess.run(
                ["npx", "--yes", CSPELL, "lint", "--config", str(HERE / "cspell.json"),
                 "--no-progress", "--no-summary", "--no-color", "--show-suggestions",
                 "--no-must-find-files", "--relative", *names],
                cwd=tmp, capture_output=True, text=True)
        except FileNotFoundError:
            sys.exit("Spell check needs Node.js (npx was not found).")
    if run.returncode not in (0, 1):
        sys.exit(f"cspell failed:\n{run.stdout}{run.stderr}")
    found, seen = [], set()
    for row in run.stdout.splitlines():
        m = CSPELL_LINE.match(row.strip())
        if not m:
            continue
        path, line, word = m.group(1), int(m.group(2)), m.group(3)
        if (path, line, word) in seen:
            continue
        seen.add((path, line, word))
        suggestions = [s.strip().rstrip("*") for s in (m.group(4) or "").split(",") if s.strip()]
        fix = likely_fix(word, suggestions)
        found.append({"path": path, "line": line, "kind": "spelling", "word": word, "fix": fix,
                      "message": f'unknown word "{word}"' + (f" (did you mean {fix}?)" if fix else "")})
    return found


def fix_line(raw, problems):
    """Apply the known fixes for one source line; None if nothing could be applied."""
    fixed = raw
    for p in problems:
        if not p.get("fix"):
            continue
        if p["kind"] == "date":
            pattern = r"(\(\s*)" + re.escape(p["word"]) + r"(?![A-Za-z])"
        else:
            pattern = r"(?<![A-Za-z])()" + re.escape(p["word"]) + r"(?![A-Za-z])"
        fixed = re.sub(pattern, lambda m, f=p["fix"]: m.group(1) + f, fixed, count=1)
    return fixed if fixed != raw else None


def merge_words(text, new_words):
    """Add words to the word-list text (case-insensitive, sorted). Returns (text, added)."""
    lines = text.splitlines()
    header = [l for l in lines if l.startswith("#")]
    words = [l.strip() for l in lines if l.strip() and not l.startswith("#")]
    known = {w.lower() for w in words}
    added = []
    for word in new_words:
        word = word.strip()
        if word and word.lower() not in known:
            known.add(word.lower())
            words.append(word)
            added.append(word)
    return "\n".join(header + sorted(words, key=str.lower)) + "\n", added


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--base", default="origin/master",
                        help="branch or commit to compare against (default: origin/master)")
    parser.add_argument("--head", help="commit to check instead of the files on disk")
    parser.add_argument("--all", action="store_true", help="check every page, not just added lines")
    parser.add_argument("--json", metavar="FILE", help="also write the results to FILE as JSON")
    parser.add_argument("--add-words", nargs="+", metavar="WORD",
                        help="add correctly spelled words to words.txt and exit")
    args = parser.parse_args()

    if args.add_words:
        text, added = merge_words(WORDS_FILE.read_text(encoding="utf-8"), args.add_words)
        WORDS_FILE.write_text(text, encoding="utf-8")
        print(f"Added to words.txt: {', '.join(added)}" if added else "All of these are already in words.txt.")
        return 0

    head = None if args.all else args.head
    if args.all:
        targets = {path: None for path in git("ls-files", "*.html").splitlines()}
    else:
        targets = added_lines(args.base, args.head or "HEAD")

    texts, sources = {}, {}
    for path, wanted in sorted(targets.items()):
        if SKIP_PATHS.search(path) or (not head and not Path(path).is_file()):
            continue
        sources[path] = read_source(path, head)
        lines = visible_lines(sources[path])
        picked = {n: t for n, t in lines.items() if t.strip() and (wanted is None or n in wanted)}
        if picked:
            texts[path] = picked

    problems = []
    for path, lines in texts.items():
        for line, text in lines.items():
            for message, word, fix in check_dates(text):
                problems.append({"path": path, "line": line, "kind": "date", "word": word,
                                 "fix": fix, "message": message})
    if texts:
        problems += check_spelling(texts)
    problems.sort(key=lambda p: (p["path"], p["line"], p["kind"]))

    # A misspelled weekday gets its fix from the date check.
    date_fixes = {(p["path"], p["line"], p["word"]): p["fix"] for p in problems if p["kind"] == "date"}
    for p in problems:
        if p["kind"] == "spelling" and not p["fix"]:
            p["fix"] = date_fixes.get((p["path"], p["line"], p["word"]))
            if p["fix"]:
                p["message"] += f" (did you mean {p['fix']}?)"

    line_fixes = []
    for key in sorted({(p["path"], p["line"]) for p in problems if p["fix"]}):
        raw = sources[key[0]].split("\n")[key[1] - 1].rstrip("\r")
        fixed = fix_line(raw, [p for p in problems if (p["path"], p["line"]) == key])
        if fixed:
            line_fixes.append({"path": key[0], "line": key[1], "original": raw, "fixed": fixed})

    if args.json:
        report = {"head_sha": git("rev-parse", head or "HEAD").strip(),
                  "problems": problems, "line_fixes": line_fixes}
        Path(args.json).write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")

    if not texts:
        print("No changed page text to check.")
        return 0

    in_actions = os.environ.get("GITHUB_ACTIONS") == "true"
    for p in problems:
        print(f"{p['path']}:{p['line']}: {p['message']}")
        if in_actions:
            print(f"::error file={p['path']},line={p['line']},title=Content check::{p['message']}")

    checked = sum(len(lines) for lines in texts.values())
    if not problems:
        print(f"All good: checked {checked} line(s) of page text in {len(texts)} file(s).")
        return 0
    print(f"\n{len(problems)} problem(s) found in {checked} checked line(s).\n"
          "How to fix:\n"
          "  - A real typo or wrong weekday: correct it in the HTML.\n"
          "  - A correctly spelled name or abbreviation (e.g. a new speaker's name):\n"
          "    python3 .github/content-checks/check_content.py --add-words NAME")
    return 1


if __name__ == "__main__":
    sys.exit(main())
