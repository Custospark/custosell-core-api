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
        # Auth recovery lives outside the sidebar - logged-out users asking
        # about passwords must land here, not on account/security.
        ("Forgot password", "/forgot-password", ["forgot password", "forgot my password", "cannot log in", "cant log in", "locked out", "password reset email", "password reset link", "recover account"]),
        # Hubs, index pages and flows missing from sidebar/search sources.
        ("Onboarding", "/onboarding", ["onboarding", "setup wizard", "getting started checklist", "new business setup", "first steps"]),
        ("Reset password", "/reset-password", ["reset password with code", "set new password", "password reset token"]),
        ("Verify code", "/verify-code", ["verification code", "verify email code", "enter code", "otp code"]),
        ("Help center", "/guide", ["help center", "help hub", "guides home", "all help"]),
        ("Public FAQs", "/faqs", ["faqs", "frequently asked questions", "common questions"]),
        ("Your tools", "/your-tools", ["your tools", "my tools", "personal workspace", "my workspace tools"]),
        ("My account", "/account", ["my account", "account home", "account overview"]),
        ("Settings", "/settings", ["settings home", "all settings", "app settings"]),
        ("Sales", "/sales", ["sales home", "sell", "record sale", "make a sale"]),
        ("Inventory", "/inventory", ["inventory home", "stock home", "products home"]),
        ("Pipeline", "/pipeline", ["pipeline home", "crm home", "deals home", "funnel home"]),
        ("Projects & Estimates", "/estimates", ["estimates home", "projects home", "quotes home"]),
        ("Expenses", "/expenses", ["expenses home", "money home", "spending home"]),
        ("HR & Payroll", "/hr", ["hr home", "team home", "staff home", "people home"]),
        ("Accounting", "/accounting", ["accounting home", "books home", "ledger home"]),
        ("Forecasting", "/forecasting", ["forecasting home", "cash outlook home", "projections home"]),
        ("EFRIS", "/efris", ["efris home", "fiscal home", "ura receipts home"]),
        ("New invoice", "/invoices/new", ["new invoice", "create invoice", "bill customer", "raise invoice"]),
        ("HR departments", "/hr/departments", ["departments", "company departments", "teams structure"]),
        ("HR attendance", "/hr/attendance", ["attendance", "clock in", "time tracking", "staff attendance"]),
        ("HR leave", "/hr/leave", ["leave", "time off", "vacation request", "staff leave"]),
        ("HR payroll", "/hr/payroll", ["payroll", "pay salaries", "staff pay", "payslips"]),
        ("Projects", "/estimates/projects", ["manage projects", "my projects", "project list"]),
        ("Estimate templates", "/estimates/templates", ["estimate templates", "quote templates"]),
        ("Sales leads", "/pipeline/leads", ["leads", "sales leads", "new leads"]),
        ("Financial statements", "/accounting/statements", ["financial statements", "profit and loss", "balance sheet", "income statement"]),
        ("Forecast scenarios", "/forecasting/scenarios", ["what if scenarios", "forecast scenarios", "projections scenarios"]),
        ("Wishlist", "/discover/wishlist", ["wishlist", "saved items", "favourite items"]),
        ("Favorites", "/discover/favorites", ["favorites", "followed shops", "liked products"]),
        ("Platform", "/platform", ["platform home", "admin home", "platform dashboard"]),
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
