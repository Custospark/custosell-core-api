<?php

namespace Tests\Feature;

use App\Mail\StandardEmail;
use App\Models\Business;
use App\Models\PipelineBoard;
use App\Models\User;
use App\Services\Pipeline\PipelineNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Board notification emails must deep-link to the board by its opaque CODE -
 * the UI opens boards at /pipeline/boards/{code}, not by numeric id.
 */
class PipelineBoardNotificationCtaTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_notification_email_links_to_board_by_code_not_id(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['is_active' => true]);
        $teammate = User::factory()->create(['is_active' => true]);
        $business = Business::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);
        $owner->business_id = $business->id;
        $owner->save();
        $teammate->business_id = $business->id;
        $teammate->save();

        $board = PipelineBoard::create([
            'business_id' => $business->id,
            'created_by' => $owner->id,
            'name' => 'Sales board',
            'visibility' => 'team',
            'workspace' => 'pipeline',
        ]);

        app(PipelineNotificationService::class)->notifyAnnouncement(
            $board,
            $owner,
            'Big news',
            'We hit our target.',
            [$teammate],
        );

        Mail::assertSent(StandardEmail::class, function (StandardEmail $mail) use ($board, $teammate) {
            return $mail->hasTo($teammate->email)
                && $mail->ctaUrl !== null
                && str_contains($mail->ctaUrl, "/boards/{$board->code}")
                && !str_contains($mail->ctaUrl, "/boards/{$board->id}");
        });
    }
}
