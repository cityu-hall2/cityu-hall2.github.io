#!/usr/bin/env python3
"""GitHub side of the content check, run by the workflows in .github/workflows/.

  github_bot.py report --pr N --report report.json   post/update the PR comment and fix suggestions
  github_bot.py add-words                            add the words ticked in that comment to words.txt

Uses the `gh` CLI (GH_TOKEN must be set). --dry-run prints what would be sent instead.
"""
import argparse
import base64
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path
from urllib.parse import quote

sys.path.insert(0, str(Path(__file__).resolve().parent))
from check_content import merge_words  # noqa: E402

BOT = "github-actions[bot]"
MARKER = "<!-- content-check-bot -->"
SUGGESTION_MARKER = "<!-- content-check-suggestion -->"
WORDS_PATH = ".github/content-checks/words.txt"
TICKED = re.compile(r"^- \[[xX]\] `([^`]+)`.*<!-- add-word -->\s*$", re.M)
VALID_WORD = re.compile(r"^[\w'’.\-]{1,40}$")
MAX_ROWS = 40

REPO = os.environ.get("GITHUB_REPOSITORY", "")
DRY_RUN = False


def gh(*args, payload=None, allow_fail=False):
    """Call `gh api`; returns parsed JSON (a list for paginated calls), or None on allowed failure."""
    if DRY_RUN and (payload is not None or "-X" in args):
        print(f"[dry-run] gh api {' '.join(args)}")
        if payload is not None:
            print(json.dumps(payload, indent=2, ensure_ascii=False))
        return {}
    if DRY_RUN:
        return []
    cmd = ["gh", "api", *args]
    with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8") as f:
        if payload is not None:
            json.dump(payload, f)
            cmd += ["--input", f.name]
    run = subprocess.run(cmd, capture_output=True, text=True)
    os.unlink(f.name)
    if run.returncode != 0:
        if allow_fail:
            print(f"warning: gh api {args[0]} failed: {run.stderr.strip()}")
            return None
        sys.exit(f"gh api {' '.join(args)} failed:\n{run.stderr}")
    if "--paginate" in args:
        return [json.loads(row) for row in run.stdout.splitlines() if row.strip()]
    return json.loads(run.stdout) if run.stdout.strip() else {}


def bot_comment(pr):
    for c in gh(f"repos/{REPO}/issues/{pr}/comments", "--paginate", "--jq", ".[]"):
        if c["user"]["login"] == BOT and MARKER in c["body"]:
            return c
    return None


def link(head_sha, path, line):
    return f"[`{path}` line {line}](https://github.com/{REPO}/blob/{head_sha}/{quote(path)}#L{line})"


def build_comment(report):
    sha = report["head_sha"]
    spelling = [p for p in report["problems"] if p["kind"] == "spelling"]
    dates = [p for p in report["problems"] if p["kind"] == "date"]
    if not spelling and not dates:
        return (f"{MARKER}\n### ✅ Content check: no problems left\n\n"
                "Every word is in the dictionary or the word list, and every weekday matches its date.")

    parts = [MARKER, f"### 🔤 Content check found {len(spelling) + len(dates)} problem(s)"]
    if spelling:
        has_fix = any(p["fix"] for p in spelling)
        rows = [f"| {link(sha, p['path'], p['line'])} | `{p['word']}` | {p['fix'] or '—'} |"
                for p in spelling[:MAX_ROWS]]
        if len(spelling) > MAX_ROWS:
            rows.append(f"| … and {len(spelling) - MAX_ROWS} more | | |")
        fixes = {}
        for p in spelling:
            fixes.setdefault(p["word"], p["fix"])
        boxes = [f"- [ ] `{w}`" + (f" (probably a typo of *{fix}*)" if fix else "") + " <!-- add-word -->"
                 for w, fix in fixes.items()]
        parts += [
            "These words aren't in the dictionary or the site's word list:",
            "| Where | Word | Did you mean |\n|---|---|---|\n" + "\n".join(rows),
            "**Is it a typo?** Fix it in the HTML."
            + (" Where there's a likely fix, there's a suggestion on that line in **Files changed**:"
               " click **Commit suggestion** to apply it." if has_fix else ""),
            "**Is it spelled correctly** (a name or abbreviation)? Tick it and it will be added to the word list:",
            "\n".join(boxes),
        ]
    if dates:
        parts += ["#### 📅 Dates", "\n".join(f"- {link(sha, p['path'], p['line'])}: {p['message']}"
                                            for p in dates)]
    return "\n\n".join(parts)


def suggestion_body(fix, problems):
    dates = [p for p in problems if p["fix"] and p["kind"] == "date"]
    dated = {p["word"] for p in dates}
    typos = [p for p in problems if p["fix"] and p["kind"] == "spelling" and p["word"] not in dated]
    lines = [f"**Wrong weekday:** {p['message']}." for p in dates]
    if typos:
        lines.append("**Possible typo:** " + ", ".join(f"`{p['word']}` → `{p['fix']}`" for p in typos))
    note = "Click **Commit suggestion** to apply it."
    if typos:
        note += (" If the original is a correct name or abbreviation, ignore this and tick it"
                 " in the content check comment instead.")
    return f"{SUGGESTION_MARKER}\n" + "\n".join(lines) + f"\n\n````suggestion\n{fix['fixed']}\n````\n{note}"


def report_cmd(args):
    path = Path(args.report)
    if not path.is_file():
        print("No report was produced, nothing to post.")
        return 0
    report = json.loads(path.read_text(encoding="utf-8"))
    body = build_comment(report)
    existing = bot_comment(args.pr)
    if existing:
        if existing["body"] != body:
            gh(f"repos/{REPO}/issues/comments/{existing['id']}", "-X", "PATCH", payload={"body": body})
    elif report["problems"]:
        gh(f"repos/{REPO}/issues/{args.pr}/comments", "-X", "POST", payload={"body": body})

    if not report["line_fixes"]:
        return 0
    posted = {(c["path"], c["body"]) for c in
              gh(f"repos/{REPO}/pulls/{args.pr}/comments", "--paginate", "--jq", ".[]")
              if c["user"]["login"] == BOT and SUGGESTION_MARKER in c["body"]}
    comments = []
    for fix in report["line_fixes"]:
        related = [p for p in report["problems"] if (p["path"], p["line"]) == (fix["path"], fix["line"])]
        body = suggestion_body(fix, related)
        if (fix["path"], body) not in posted:
            comments.append({"path": fix["path"], "line": fix["line"], "side": "RIGHT", "body": body})
    if not comments:
        return 0
    review = {"commit_id": report["head_sha"], "event": "COMMENT", "comments": comments,
              "body": "The content check has one-click fixes for likely typos below."}
    if gh(f"repos/{REPO}/pulls/{args.pr}/reviews", "-X", "POST", payload=review, allow_fail=True) is None:
        for c in comments:  # e.g. a line GitHub doesn't consider part of the diff: post the rest
            gh(f"repos/{REPO}/pulls/{args.pr}/comments", "-X", "POST",
               payload={**c, "commit_id": report["head_sha"]}, allow_fail=True)
    return 0


def add_words_cmd(args):
    event = json.loads(Path(args.event or os.environ["GITHUB_EVENT_PATH"]).read_text(encoding="utf-8"))
    comment, sender, pr = event["comment"], event["sender"]["login"], event["issue"]["number"]
    if comment["user"]["login"] != BOT or MARKER not in comment["body"] or event["sender"]["type"] == "Bot":
        print("Not a content check comment edited by a person; nothing to do.")
        return 0
    words = [w for w in TICKED.findall(comment["body"]) if VALID_WORD.match(w)]
    if not words:
        print("No ticked words.")
        return 0

    def note(text):
        body = TICKED.sub(lambda m: f"- [x] `{m.group(1)}` {text}", comment["body"])
        gh(f"repos/{REPO}/issues/comments/{comment['id']}", "-X", "PATCH", payload={"body": body})

    if not DRY_RUN:
        permission = (gh(f"repos/{REPO}/collaborators/{sender}/permission", allow_fail=True) or {}).get("permission")
        if permission not in ("admin", "maintain", "write"):
            print(f"@{sender} doesn't have write access; not changing anything.")
            return 0
        info = gh(f"repos/{REPO}/pulls/{pr}")
        branch, head_repo, state = info["head"]["ref"], info["head"]["repo"]["full_name"], info["state"]
    else:
        branch, head_repo, state = args.branch, REPO, "open"
    if state != "open" or head_repo != REPO:
        note("— ⚠️ can't be added automatically (the PR is closed or comes from a fork); "
             f"add it to `{WORDS_PATH}` by hand.")
        return 0

    for attempt in range(2):  # retry once if the branch moved while we were editing
        if DRY_RUN:
            text, sha = Path(__file__).with_name("words.txt").read_text(encoding="utf-8"), "dry-run"
        else:
            current = gh(f"repos/{REPO}/contents/{WORDS_PATH}?ref={quote(branch)}", allow_fail=True)
            if current is None:
                note(f"— ⚠️ this branch has no `{WORDS_PATH}` yet; update the branch from master first.")
                return 0
            text, sha = base64.b64decode(current["content"]).decode("utf-8"), current["sha"]
        new_text, added = merge_words(text, words)
        if not added:
            break
        result = gh(f"repos/{REPO}/contents/{WORDS_PATH}", "-X", "PUT", allow_fail=attempt == 0, payload={
            "message": f"Add {', '.join(added)} to the content-check word list\n\nTicked by @{sender} in #{pr}.",
            "content": base64.b64encode(new_text.encode("utf-8")).decode("ascii"),
            "sha": sha, "branch": branch})
        if result is not None:
            break

    rerun = gh("repos/{}/actions/workflows/content-checks.yml/dispatches".format(REPO), "-X", "POST",
               payload={"ref": branch, "inputs": {"pr": str(pr)}}, allow_fail=True)
    note(f"— ✅ added to the word list by @{sender}"
         + ("; re-running the check…" if rerun is not None else "; push any change to re-run the check."))
    return 0


def main():
    global DRY_RUN
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--dry-run", action="store_true", help="print API calls instead of sending them")
    sub = parser.add_subparsers(dest="command", required=True)
    report = sub.add_parser("report", help="post or update the PR comment and fix suggestions")
    report.add_argument("--pr", required=True)
    report.add_argument("--report", required=True)
    add = sub.add_parser("add-words", help="add the words ticked in the bot comment to words.txt")
    add.add_argument("--event", help="event JSON (default: $GITHUB_EVENT_PATH)")
    add.add_argument("--branch", default="dry-run-branch", help=argparse.SUPPRESS)
    args = parser.parse_args()
    DRY_RUN = args.dry_run
    return report_cmd(args) if args.command == "report" else add_words_cmd(args)


if __name__ == "__main__":
    sys.exit(main())
