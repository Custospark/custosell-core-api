<?php

namespace Tests\Feature;

use App\Events\UserRegistered;
use App\Listeners\SendWelcomeEmail;
use App\Mail\StandardEmail;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendWelcomeEmailTest extends TestCase
{
    public function test_welcome_email_is_sent_with_business_name(): void
    {
        Mail::fake();

        $user = new User();
        $user->name = 'Jane Doe';
        $user->email = 'jane@example.com';

        $business = new Business();
        $business->name = 'Jane Co';

        (new SendWelcomeEmail())->handle(new UserRegistered($user, $business));

        Mail::assertSent(StandardEmail::class, function (StandardEmail $mail): bool {
            return $mail->to[0]['address'] === 'jane@example.com'
                && str_contains($mail->mailBody, 'Jane Co')
                && str_contains($mail->title, 'Jane');
        });
    }

    public function test_welcome_email_is_sent_without_business(): void
    {
        Mail::fake();

        $user = new User();
        $user->name = 'John Smith';
        $user->email = 'john@example.com';

        (new SendWelcomeEmail())->handle(new UserRegistered($user));

        Mail::assertSent(StandardEmail::class, function (StandardEmail $mail): bool {
            return $mail->to[0]['address'] === 'john@example.com'
                && ! str_contains($mail->mailBody, 'for <strong>');
        });
    }
}
