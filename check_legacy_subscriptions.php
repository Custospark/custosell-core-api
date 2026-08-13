<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;

echo "=== ALL PLANS (incl. stale) ===".PHP_EOL;
foreach (Plan::orderBy('sort_order')->get() as $p) {
    echo '#'.$p->id.' slug='.$p->slug.' name='.$p->name.' type='.$p->type
        .' active='.($p->is_active ? 1 : 0).' sort='.$p->sort_order
        .' price='.$p->price_monthly_usd.PHP_EOL;
}

echo PHP_EOL.'=== ALL SUBSCRIPTIONS ==='.PHP_EOL;
$subs = Subscription::with('plan')->get();
foreach ($subs as $s) {
    $slug = $s->plan ? $s->plan->slug : 'NO_PLAN';
    $name = $s->plan ? $s->plan->name : 'NO_PLAN';
    echo 'sub#'.$s->id.' biz#'.$s->business_id.' plan_id='.$s->plan_id
        .' (slug='.$slug.', name='.$name.') status='.$s->status->value
        .' trial_used='.($s->trial_used ? 1 : 0)
        .' fee_paid='.($s->onboarding_fee_paid ? 1 : 0).PHP_EOL;
}

echo PHP_EOL.'=== BUSINESSES WITHOUT SUBSCRIPTION ==='.PHP_EOL;
$noSub = Business::whereNull('deleted_at')
    ->whereNotIn('id', $subs->pluck('business_id'))
    ->get(['id', 'name', 'created_at']);
foreach ($noSub as $b) {
    echo 'biz#'.$b->id.' '.$b->name.' created='.$b->created_at.PHP_EOL;
}
echo 'count: '.$noSub->count().PHP_EOL;

echo PHP_EOL.'=== COUNTS ==='.PHP_EOL;
echo 'businesses (not deleted): '.Business::whereNull('deleted_at')->count().PHP_EOL;
echo 'subscriptions: '.$subs->count().PHP_EOL;
echo 'plans (all): '.Plan::count().PHP_EOL;