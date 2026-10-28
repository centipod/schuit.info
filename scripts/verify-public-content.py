#!/usr/bin/env python3
"""Compare the public source inventory and review HTTP/media availability."""

import concurrent.futures
import html
import json
import pathlib
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BASE = "https://schuit.centipod.ai"


class Page(HTMLParser):
    def __init__(self):
        super().__init__()
        self.text = []
        self.links = set()
        self.media = set()

    def handle_data(self, data):
        self.text.append(data)

    def handle_starttag(self, tag, attrs):
        d = dict(attrs)
        if tag == "a" and d.get("href"):
            self.links.add(d["href"])
        if tag == "img" and d.get("src"):
            self.media.add(d["src"])
        for v in d.get("srcset", "").split(","):
            if v.strip():
                self.media.add(v.strip().split()[0])


def clean(text):
    p = Page()
    p.feed(text)
    return " ".join(html.unescape(" ".join(p.text)).split())


def fetch(url):
    with urllib.request.urlopen(url, timeout=30) as r:
        return r.read().decode(), r.status


report = {"content": {}, "pages": {}, "assets": {}, "legacy_source_links": []}
pages = {BASE + "/", BASE + "/contact/", BASE + "/about/", BASE + "/category/publicaties/"}
for kind in ["pages", "posts"]:
    original = json.loads(pathlib.Path("working/source/" + kind + ".json").read_text())
    body, _ = fetch(BASE + "/?rest_route=/wp/v2/" + kind + "&per_page=100")
    current = {x["id"]: x for x in json.loads(body)}
    missing = [x["id"] for x in original if x["id"] not in current]
    changed = [
        x["id"]
        for x in original
        if x["id"] in current
        and clean(x["content"]["rendered"]) != clean(current[x["id"]]["content"]["rendered"])
    ]
    report["content"][kind] = {
        "source_count": len(original),
        "review_count": len(current),
        "missing_ids": missing,
        "text_differences": changed,
    }
    pages.update(x["link"] for x in current.values())
assets = set()
internal = set()


def readpage(url):
    try:
        assert urllib.parse.urlsplit(url).netloc == "schuit.centipod.ai"
        body, status = fetch(url)
        p = Page()
        p.feed(body)
        return url, status, p
    except Exception as e:
        return url, str(e), None


with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
    for url, status, p in pool.map(readpage, sorted(pages)):
        report["pages"][url] = status
        if p is None:
            continue
        for link in p.links | p.media:
            target = urllib.parse.urljoin(url, html.unescape(link))
            parsed = urllib.parse.urlsplit(target)
            if parsed.netloc in ["www.schuit.info", "schuit.info", "stichtingschu-y-i-ij-t.nl"]:
                report["legacy_source_links"].append({"page": url, "url": target})
            if parsed.netloc == "schuit.centipod.ai":
                if "/wp-content/uploads/" in parsed.path:
                    assets.add(target)
                elif link in p.links and not parsed.path.startswith(("/tree", "/wp-login", "/wp-admin")):
                    internal.add(target.split("#")[0])


def head(url):
    try:
        with urllib.request.urlopen(urllib.request.Request(url, method="HEAD"), timeout=30) as r:
            return url, r.status
    except Exception as e:
        return url, str(e)


with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
    report["assets"] = dict(pool.map(head, sorted(assets)))
    report["internal_links"] = dict(pool.map(head, sorted(internal - pages)))
pathlib.Path("working/public-acceptance.json").write_text(json.dumps(report, indent=2) + "\n")
print(
    json.dumps(
        {
            "content": report["content"],
            "pages_checked": len(report["pages"]),
            "page_failures": {k: v for k, v in report["pages"].items() if v != 200},
            "assets_checked": len(assets),
            "asset_failures": sum(v != 200 for v in report["assets"].values()),
            "internal_links_checked": len(report["internal_links"]),
            "link_failures": {k: v for k, v in report["internal_links"].items() if v != 200},
            "legacy_source_links": len(report["legacy_source_links"]),
        },
        indent=2,
    )
)
