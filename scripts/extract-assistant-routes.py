#!/usr/bin/env python3
"""Extract Custosell frontend nav map for Oscar's knowledge base.

Reads (frontend, single source of truth):
  shared.paths.ts      ROUTES.X -> '/path' literals
  searchKeywords.ts    [ROUTES.X] -> natural-language keywords
  sidebarNavGroups.ts  label + to: ROUTES.X pairs
Writes:
  Backend/app/Services/Assistant/route-map.json  [{label, path, keywords}]
Re-run whenever navigation changes.
"""
import json
import os
import re
import sys

BACKEND_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FRONTEND_ROOT = os.path.join(os.path.dirname(BACKEND_ROOT), "Frontend")
FE = os.path.join(FRONTEND_ROOT, "src", "renderer")
LAYOUT = os.path.join(FE, "shared", "components", "layout")
OUT = os.path.join(BACKEND_ROOT, "app", "Services", "Assistant", "route-map.json")


def parse_routes(path):
    """Dotted key -> literal path for string-valued ROUTES leaves."""
    src = open(path, encoding="utf-8").read()
    routes, stack, token = {}, [], ""
    i, n = 0, len(src)
    while i < n:
        m = re.match(r"([A-Z][A-Z0-9_]*)\s*:", src[i:])
        if m:
            key = m.group(1)
            j = i + m.end()
            while j < n and src[j] in " \t":
                j += 1
            if j < n and src[j] == "{":
                stack.append(key)
                token = ""
                i = j + 1
                continue
            sm = re.match(r"'([^']*)'", src[j:])
            if sm:
                dotted = ".".join(stack + [key])
                routes[dotted] = sm.group(1)
                i = j + sm.end()
                continue
            i = j
            continue
        ch = src[i]
        if ch == "{":
            pass
        elif ch == "}":
            if stack:
                stack.pop()
        i += 1
    return routes


def parse_keywords(path):
    src = open(path, encoding="utf-8").read()
    out = {}
    for m in re.finditer(r"\[ROUTES\.([A-Z0-9_\.]+)\]\s*:\s*\[(.*?)\]", src, re.S):
        kws = re.findall(r"'((?:[^'\\]|\\.)*)'", m.group(2))
        out[m.group(1)] = [k.strip() for k in kws if k.strip()]
    return out


def parse_nav_labels(path):
    src = open(path, encoding="utf-8").read()
    labels = {}
    for m in re.finditer(
        r"\{\s*to:\s*ROUTES\.([A-Z0-9_\.]+)\s*,\s*label:\s*'((?:[^'\\]|\\.)*)'", src
    ):
        labels[m.group(1)] = m.group(2)
    for m in re.finditer(
        r"label:\s*'((?:[^'\\]|\\.)*)'\s*,\s*icon:[^}]*?to:\s*ROUTES\.([A-Z0-9_\.]+)", src, re.S
    ):
        labels.setdefault(m.group(2), m.group(1))
    return labels


def main():
    routes = parse_routes(os.path.join(FE, "app", "routes", "constants", "shared.paths.ts"))
    keywords = parse_keywords(os.path.join(LAYOUT, "search", "searchKeywords.ts"))
    labels = parse_nav_labels(os.path.join(LAYOUT, "sidebarNavGroups.ts"))
    print(f"routes={len(routes)} keyword-entries={len(keywords)} nav-labels={len(labels)}")

    entries, seen = [], set()
    for dotted, kws in keywords.items():
        path = routes.get(dotted)
        if not path or path in seen:
            continue
        seen.add(path)
        label = labels.get(dotted, dotted.split(".")[-1].replace("_", " ").title())
        entries.append({"label": label, "path": path, "keywords": kws})
    for dotted, label in labels.items():
        path = routes.get(dotted)
        if not path or path in seen:
            continue
        seen.add(path)
        entries.append({"label": label, "path": path, "keywords": [label.lower()]})

    core = [
        ("Login", "/login", ["login", "sign in", "log in"]),
        ("Register", "/register", ["register", "sign up", "create account"]),
        ("Pricing", "/pricing", ["pricing", "plans", "cost", "price", "subscription cost"]),
    ]
    for label, path, kws in core:
        if path not in seen:
            seen.add(path)
            entries.append({"label": label, "path": path, "keywords": kws})

    entries.sort(key=lambda e: e["path"])
    with open(OUT, "w", encoding="utf-8") as fh:
        json.dump(entries, fh, indent=1)
    print(f"wrote {len(entries)} entries to {OUT}")
    print("sample:", json.dumps(entries[:3]))


if __name__ == "__main__":
    sys.exit(main())
