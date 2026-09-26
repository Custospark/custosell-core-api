<?php

namespace App\Services\Assistant;

use App\Models\GuideFaq;
use App\Models\GuideTutorial;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

/**
 * Lightweight knowledge base for Oscar. Passages come from live,
 * admin-managed sources (published FAQs + tutorials) plus a curated static
 * product brief. Keyword retrieval keeps it dependency-free (no vector DB);
 * the corpus is cached and every answer stays char-capped for cost control.
 */
class AssistantKnowledgeService
{
    private const CACHE_KEY = 'assistant_kb_corpus';

    private const CACHE_TTL_SECONDS = 600;

    private const MAX_PASSAGES = 5;

    private const MAX_CHARS = 2500;

    /** @return list<string> capped, most-relevant-first excerpts */
    public function relevantPassages(string $query): array
    {
        $terms = $this->terms($query);
        if ($terms === []) {
            return [];
        }

        $scored = [];
        foreach ($this->corpus() as $passage) {
            $score = $this->score($passage, $terms);
            if ($score > 0) {
                $scored[] = [$score, $passage['text']];
            }
        }

        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);

        $picked = [];
        $chars = 0;
        foreach ($scored as [$score, $text]) {
            if (count($picked) >= self::MAX_PASSAGES) {
                break;
            }
            if ($chars + strlen($text) > self::MAX_CHARS) {
                continue;
            }
            $picked[] = $text;
            $chars += strlen($text);
        }

        return $picked;
    }

    /** @return list<array{title: string, text: string}> */
    private function corpus(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            $items = [];

            foreach (static::productBrief() as $title => $text) {
                $items[] = ['title' => $title, 'text' => "[Guide] {$title}: {$text}"];
            }

            GuideFaq::query()
                ->where('is_published', true)
                ->orderBy('sort_order')
                ->limit(200)
                ->get(['question', 'answer'])
                ->each(function ($faq) use (&$items) {
                    $items[] = ['title' => (string) $faq->question, 'text' => '[FAQ] Q: '.((string) $faq->question).' A: '.((string) $faq->answer)];
                });

            GuideTutorial::query()
                ->where('is_published', true)
                ->orderBy('sort_order')
                ->limit(200)
                ->get(['title', 'description', 'category'])
                ->each(function ($tutorial) use (&$items) {
                    $items[] = ['title' => (string) $tutorial->title, 'text' => '[Tutorial] '.((string) $tutorial->title).' ('.((string) $tutorial->category).'): '.((string) $tutorial->description)];
                });

            foreach ($this->planPassages() as $title => $text) {
                $items[] = ['title' => $title, 'text' => "[Pricing] {$title}: {$text}"];
            }

            foreach ($this->routeEntries() as $entry) {
                $url = rtrim((string) env('FRONTEND_URL', config('app.url')), '/').($entry['path']);
                $items[] = [
                    'title' => $entry['label'],
                    'text' => '[Route] '.($entry['label']).' lives at '.($url).' ('.implode(', ', array_slice($entry['keywords'], 0, 8)).')',
                ];
            }

            return $items;
        });
    }

    /** @return list<array{label: string, path: string, keywords: list<string>}> */
    private function routeEntries(): array
    {
        static $entries = null;
        if ($entries !== null) {
            return $entries;
        }
        $file = __DIR__.'/route-map.json';
        if (! is_file($file)) {
            return $entries = [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (! is_array($decoded)) {
            return $entries = [];
        }

        return $entries = array_values(array_filter($decoded, fn ($e) => is_array($e)
            && isset($e['label'], $e['path'])
            && is_string($e['label']) && is_string($e['path'])));
    }

    /** Live subscription plans - prices come from the DB, never hardcoded. */
    /** @return array<string, string> */
    private function planPassages(): array
    {
        $out = [];

        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['name', 'type', 'description', 'features', 'price_monthly_usd', 'price_yearly_usd', 'onboarding_fee_usd', 'trial_days', 'is_popular']);

        foreach ($plans as $plan) {
            $features = $plan->features;
            if (is_string($features)) {
                $features = json_decode($features, true);
            }
            $enabled = is_array($features)
                ? array_slice(array_keys(array_filter($features)), 0, 12)
                : [];
            $popular = (bool) $plan->is_popular ? ' (most popular)' : '';
            $out["Plan {$plan->name}"] = sprintf(
                '%s plan%s: $%s/month, $%s/year, %d-day trial, no onboarding fee. Includes: %s.',
                $plan->name,
                $popular,
                number_format((float) $plan->price_monthly_usd, 2),
                number_format((float) ($plan->price_yearly_usd ?? 0), 2),
                (int) $plan->trial_days,
                $enabled === [] ? ((string) $plan->description) : implode(', ', $enabled)
            );
        }

        return $out;
    }

    /** Curated product facts mirroring landing/pricing pages. */
    /** @return array<string, string> */
    private static function productBrief(): array
    {
        return [
            'What Custosell is' => 'Custosell ERP (Your Business Operating System) unifies POS, inventory, invoices, expenses, HR and payroll, projects, sales pipeline (CRM), forecasting and documents. Works online and offline.',
            'Who makes Custosell' => 'Custosell ERP is a product of Custospark Company Ltd (www.custospark.com).',
            'Who it serves' => 'Registered businesses (shops, restaurants, pharmacies, warehouses), personal workspace accounts, and Discover-only shoppers who browse and order.',
            'Getting started' => 'Register, verify email, create or join a business, complete onboarding (company, taxes, users), import products and opening stock, then sell via POS or storefront.',
            'Plans and billing' => 'Subscription plans per business with a trial period; billing, receipts and referral rewards live under billing. Owners manage subscription from account settings.',
            'Offline mode' => 'Core selling and records keep working without internet and sync when reconnected. The AI assistant needs internet.',
        ];
    }

    /** @return list<string> */
    private function terms(string $query): array
    {
        $stop = ['the', 'a', 'an', 'and', 'or', 'is', 'are', 'what', 'how', 'do', 'does', 'i', 'my', 'me', 'it', 'to', 'of', 'in', 'on', 'for', 'with', 'can', 'you', 'your', 'we', 'us', 'this', 'that'];
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($query)) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn ($w) => strlen($w) > 2 && ! in_array($w, $stop, true)
        )));
    }

    /** @param array{title: string, text: string} $passage @param list<string> $terms */
    private function score(array $passage, array $terms): int
    {
        $score = 0;
        $title = mb_strtolower($passage['title']);
        $text = mb_strtolower($passage['text']);

        foreach ($terms as $term) {
            if (str_contains($title, $term)) {
                $score += 3;
            }
            $score += min(substr_count($text, $term), 3);
        }

        return $score;
    }
}
